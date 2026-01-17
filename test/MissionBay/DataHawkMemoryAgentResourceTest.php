<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use PHPUnit\Framework\TestCase;
use MissionBayReporting\MissionBay\DataHawkMemoryAgentResource;
use MissionBay\Api\IAgentConfigValueResolver;
use ResourceFoundation\Api\IQuerySchemaProvider;
use ResourceFoundation\Dto\TableMetadata;
use ResourceFoundation\Dto\FieldMetadata;
use ResourceFoundation\Dto\JoinMetadata;

/**
 * @covers \MissionBayReporting\MissionBay\DataHawkMemoryAgentResource
 */
class DataHawkMemoryAgentResourceTest extends TestCase {

	private function makeResolverPassthrough(): IAgentConfigValueResolver {
		// Use a stub (not a mock) to avoid PHPUnit "no expectations" notices.
		$resolver = $this->createStub(IAgentConfigValueResolver::class);

		$resolver->method('resolveValue')
			->willReturnCallback(function ($value) {
				return $value;
			});

		return $resolver;
	}

	private function makeSchemaProvider(array $tables): IQuerySchemaProvider {
		// Use a stub (not a mock) to avoid PHPUnit "no expectations" notices.
		$sp = $this->createStub(IQuerySchemaProvider::class);

		$sp->method('getSchema')->willReturn($tables);

		$sp->method('getTable')->willReturnCallback(function (string $name) use ($tables) {
			foreach ($tables as $t) {
				if ($t instanceof TableMetadata && $t->name === $name) {
					return $t;
				}
			}
			return null;
		});

		return $sp;
	}

	public function testGetName(): void {
		$this->assertSame('datahawkmemoryagentresource', DataHawkMemoryAgentResource::getName());
	}

	public function testGetDescription(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new DataHawkMemoryAgentResource($r, $sp);

		$this->assertSame(
			'Provides DataHawk structured query rules and the generated schema as a system prompt.',
			$m->getDescription()
		);
	}

	public function testGetPriorityDefaultsTo55(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new DataHawkMemoryAgentResource($r, $sp);

		$this->assertSame(55, $m->getPriority());
	}

	public function testSetConfigResolvesPriorityAndFilters(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new DataHawkMemoryAgentResource($r, $sp);

		$m->setConfig([
			'priority' => '77',
			'domainFilter' => ['Sales', '', '  '],
			'categoryFilter' => 'Analytics',
			'tagFilter' => ['public', '']
		]);

		$this->assertSame(77, $m->getPriority());

		$history = $m->loadNodeHistory('n1');
		$this->assertCount(1, $history);
		$this->assertSame('system', $history[0]['role']);
		$this->assertIsString($history[0]['content']);
	}

	public function testLoadNodeHistoryContainsRulesReminderAndSchemaHeader(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new DataHawkMemoryAgentResource($r, $sp);

		$history = $m->loadNodeHistory('node-x');

		$this->assertCount(1, $history);
		$this->assertSame('system', $history[0]['role']);

		$prompt = (string)$history[0]['content'];

		$this->assertStringContainsString('## DataHawk Structured Query Rules', $prompt);
		$this->assertStringContainsString('## Schema Reminder', $prompt);
		$this->assertStringContainsString('## Allowed Tables and Fields', $prompt);
	}

	public function testSchemaRenderingSortsTablesAndRendersMetaFieldsAndRelations(): void {
		$r = $this->makeResolverPassthrough();

		$b = new TableMetadata(
			name: 'bbb_table',
			label: 'B',
			description: 'B desc',
			domain: 'domB',
			category: 'catB',
			tags: ['tB'],
			fields: [
				new FieldMetadata(
					name: 'id',
					type: 'integer',
					description: 'Primary id',
					primaryKey: true,
					foreignKey: null,
					nullable: false,
					tags: [],
					alias: null,
					sensitive: false
				),
				new FieldMetadata(
					name: 'fk_id',
					type: 'int',
					description: null,
					primaryKey: false,
					foreignKey: null,
					nullable: true,
					tags: [],
					alias: null,
					sensitive: false
				),
				new FieldMetadata(
					name: 'secret',
					type: 'varchar',
					description: null,
					primaryKey: false,
					foreignKey: null,
					nullable: false,
					tags: [],
					alias: null,
					sensitive: true
				)
			],
			joins: [
				new JoinMetadata(
					targetTable: 'aaa_table',
					on: ['bbb_table.fk_id' => 'aaa_table.id'],
					type: 'LEFT',
					meta: ['default' => true]
				)
			],
			sensitive: true
		);

		$a = new TableMetadata(
			name: 'aaa_table',
			label: null,
			description: null,
			domain: '',
			category: '',
			tags: [],
			fields: [
				new FieldMetadata(name: 'name', type: 'string', description: 'Display name')
			],
			joins: [],
			sensitive: false
		);

		$sp = $this->makeSchemaProvider([$b, $a]);

		$m = new DataHawkMemoryAgentResource($r, $sp);

		$prompt = (string)$m->loadNodeHistory('n1')[0]['content'];

		// Tables should be sorted alphabetically: aaa_table first.
		$posA = strpos($prompt, '### Table: `aaa_table`');
		$posB = strpos($prompt, '### Table: `bbb_table` — B');
		$this->assertIsInt($posA);
		$this->assertIsInt($posB);
		$this->assertTrue($posA < $posB);

		// Meta and description rendering.
		$this->assertStringContainsString('**Description:** B desc', $prompt);
		$this->assertStringContainsString('**Meta:** domain: domB | category: catB | tags: tB | SENSITIVE', $prompt);

		// Field type mapping and markers.
		$this->assertStringContainsString('* `id` (int, PK) — Primary id', $prompt);
		$this->assertStringContainsString('* `fk_id` (int, NULLABLE)', $prompt);
		$this->assertStringContainsString('* `secret` (str, SENSITIVE)', $prompt);

		// Relations rendering.
		$this->assertStringContainsString('**Relations:**', $prompt);
		$this->assertStringContainsString('`bbb_table` -> `aaa_table` (LEFT, default: true) on bbb_table.fk_id = aaa_table.id', $prompt);

		// Basic field rendering for aaa_table
		$this->assertStringContainsString('* `name` (str) — Display name', $prompt);
	}

	public function testSchemaFilteringByDomainCategoryAndTags(): void {
		$r = $this->makeResolverPassthrough();

		$good = new TableMetadata(
			name: 'good_table',
			label: 'Good',
			domain: 'Sales',
			category: 'Analytics',
			tags: ['Public', 'X'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int')
			]
		);

		$badDomain = new TableMetadata(
			name: 'bad_domain',
			label: 'Bad Domain',
			domain: 'Other',
			category: 'Analytics',
			tags: ['Public'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int')
			]
		);

		$badCategory = new TableMetadata(
			name: 'bad_category',
			label: 'Bad Category',
			domain: 'Sales',
			category: 'Other',
			tags: ['Public'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int')
			]
		);

		$badTags = new TableMetadata(
			name: 'bad_tags',
			label: 'Bad Tags',
			domain: 'Sales',
			category: 'Analytics',
			tags: ['Private'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int')
			]
		);

		$sp = $this->makeSchemaProvider([$badDomain, $badCategory, $badTags, $good]);

		$m = new DataHawkMemoryAgentResource($r, $sp);

		$m->setConfig([
			'domainFilter' => ['sales'],
			'categoryFilter' => ['analytics'],
			'tagFilter' => ['public']
		]);

		$prompt = (string)$m->loadNodeHistory('n1')[0]['content'];

		$this->assertStringContainsString('### Table: `good_table` — Good', $prompt);

		$this->assertStringNotContainsString('### Table: `bad_domain`', $prompt);
		$this->assertStringNotContainsString('### Table: `bad_category`', $prompt);
		$this->assertStringNotContainsString('### Table: `bad_tags`', $prompt);
	}

	public function testTypeMappingCoversCommonAliases(): void {
		$r = $this->makeResolverPassthrough();

		$t = new TableMetadata(
			name: 'types',
			label: null,
			description: null,
			domain: '',
			category: '',
			tags: [],
			fields: [
				new FieldMetadata(name: 'a', type: 'varchar'),
				new FieldMetadata(name: 'b', type: 'text'),
				new FieldMetadata(name: 'c', type: 'bool'),
				new FieldMetadata(name: 'd', type: 'datetime'),
				new FieldMetadata(name: 'e', type: 'timestamp'),
				new FieldMetadata(name: 'f', type: 'unknown_custom')
			]
		);

		$sp = $this->makeSchemaProvider([$t]);
		$m = new DataHawkMemoryAgentResource($r, $sp);

		$prompt = (string)$m->loadNodeHistory('n1')[0]['content'];

		$this->assertStringContainsString('* `a` (str)', $prompt);
		$this->assertStringContainsString('* `b` (text)', $prompt);
		$this->assertStringContainsString('* `c` (bool)', $prompt);
		$this->assertStringContainsString('* `d` (dt)', $prompt);
		$this->assertStringContainsString('* `e` (dt)', $prompt);
		$this->assertStringContainsString('* `f` (str)', $prompt);
	}

	public function testAppendResetAndFeedbackAreNoOpsOrFalse(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new DataHawkMemoryAgentResource($r, $sp);

		// No-op methods should not throw.
		$m->appendNodeHistory('n1', ['role' => 'user', 'content' => 'x']);
		$m->resetNodeHistory('n1');

		$this->assertFalse($m->setFeedback('n1', 'm1', 'up'));
		$this->assertFalse($m->setFeedback('n1', 'm1', null));
	}
}
