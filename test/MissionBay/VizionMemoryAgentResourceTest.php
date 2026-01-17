<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use PHPUnit\Framework\TestCase;
use MissionBayReporting\MissionBay\VizionMemoryAgentResource;
use MissionBay\Api\IAgentConfigValueResolver;
use ResourceFoundation\Api\IQuerySchemaProvider;
use ResourceFoundation\Dto\TableMetadata;
use ResourceFoundation\Dto\FieldMetadata;

/**
 * @covers \MissionBayReporting\MissionBay\VizionMemoryAgentResource
 */
class VizionMemoryAgentResourceTest extends TestCase {

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
		$this->assertSame('vizionmemoryagentresource', VizionMemoryAgentResource::getName());
	}

	public function testGetDescription(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new VizionMemoryAgentResource($r, $sp);

		$this->assertSame(
			'Provides Vizion canvas/reporting rules (and a schema-derived example) as a system prompt.',
			$m->getDescription()
		);
	}

	public function testGetPriorityDefaultsTo56(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new VizionMemoryAgentResource($r, $sp);

		$this->assertSame(56, $m->getPriority());
	}

	public function testSetConfigResolvesPriorityAndFilters(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new VizionMemoryAgentResource($r, $sp);

		$m->setConfig([
			'priority' => '99',
			'domainFilter' => ['Sales', '', '  '],
			'categoryFilter' => 'Analytics',
			'tagFilter' => ['public', '']
		]);

		$this->assertSame(99, $m->getPriority());

		$history = $m->loadNodeHistory('n1');
		$this->assertCount(1, $history);
		$this->assertSame('system', $history[0]['role']);
		$this->assertIsString($history[0]['content']);
	}

	public function testLoadNodeHistoryContainsStaticPolicyAndRulesBlocks(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new VizionMemoryAgentResource($r, $sp);

		$history = $m->loadNodeHistory('node-x');

		$this->assertCount(1, $history);
		$this->assertSame('system', $history[0]['role']);

		$prompt = (string)$history[0]['content'];

		$this->assertStringContainsString('## Canvas Output Policy', $prompt);
		$this->assertStringContainsString('## Vizion Report Tool Rules (`vizion_report_canvas`)', $prompt);
		$this->assertStringContainsString('vizion_report_canvas', $prompt);
		$this->assertStringContainsString('execute_datahawk_query', $prompt);

		// No schema -> no example block.
		$this->assertStringNotContainsString('## Example (schema-derived)', $prompt);
	}

	public function testLoadNodeHistoryIncludesSchemaDerivedExampleWhenNonSensitiveTableAndFieldsExist(): void {
		$r = $this->makeResolverPassthrough();

		$table = new TableMetadata(
			name: 'packagist_package',
			label: 'Packages',
			description: null,
			domain: 'data',
			category: 'analytics',
			tags: ['public'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', sensitive: false),
				new FieldMetadata(name: 'name', type: 'string', sensitive: false),
				new FieldMetadata(name: 'downloads', type: 'int', sensitive: false),
				new FieldMetadata(name: 'repository', type: 'string', sensitive: false),
				new FieldMetadata(name: 'secret', type: 'string', sensitive: true)
			],
			sensitive: false
		);

		$sp = $this->makeSchemaProvider([$table]);

		$m = new VizionMemoryAgentResource($r, $sp);

		$history = $m->loadNodeHistory('n1');
		$prompt = (string)$history[0]['content'];

		$this->assertStringContainsString('## Example (schema-derived)', $prompt);
		$this->assertStringContainsString('"canvas_id": "main"', $prompt);
		$this->assertStringContainsString('"type": "datatable"', $prompt);
		$this->assertStringContainsString('"table": "packagist_package"', $prompt);
		$this->assertStringContainsString('"limit": 50', $prompt);

		// Only non-sensitive fields should be included; the 5th field is sensitive.
		$this->assertStringContainsString('"field": "id"', $prompt);
		$this->assertStringContainsString('"field": "name"', $prompt);
		$this->assertStringContainsString('"field": "downloads"', $prompt);
		$this->assertStringContainsString('"field": "repository"', $prompt);
		$this->assertStringNotContainsString('"field": "secret"', $prompt);
	}

	public function testExampleBlockIsOmittedWhenOnlySensitiveTablesExist(): void {
		$r = $this->makeResolverPassthrough();

		$table = new TableMetadata(
			name: 'secrets',
			label: 'Secrets',
			domain: 'data',
			category: 'analytics',
			tags: ['public'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', sensitive: false)
			],
			sensitive: true
		);

		$sp = $this->makeSchemaProvider([$table]);

		$m = new VizionMemoryAgentResource($r, $sp);

		$prompt = (string)$m->loadNodeHistory('n1')[0]['content'];

		$this->assertStringNotContainsString('## Example (schema-derived)', $prompt);
	}

	public function testExampleBlockRespectsDomainCategoryAndTagFilters(): void {
		$r = $this->makeResolverPassthrough();

		$good = new TableMetadata(
			name: 'good_table',
			label: 'Good',
			domain: 'Sales',
			category: 'Analytics',
			tags: ['Public', 'X'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', sensitive: false),
				new FieldMetadata(name: 'name', type: 'string', sensitive: false)
			],
			sensitive: false
		);

		$badDomain = new TableMetadata(
			name: 'bad_domain',
			label: 'Bad Domain',
			domain: 'Other',
			category: 'Analytics',
			tags: ['Public'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', sensitive: false)
			],
			sensitive: false
		);

		$badCategory = new TableMetadata(
			name: 'bad_category',
			label: 'Bad Category',
			domain: 'Sales',
			category: 'Other',
			tags: ['Public'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', sensitive: false)
			],
			sensitive: false
		);

		$badTags = new TableMetadata(
			name: 'bad_tags',
			label: 'Bad Tags',
			domain: 'Sales',
			category: 'Analytics',
			tags: ['Private'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', sensitive: false)
			],
			sensitive: false
		);

		$sp = $this->makeSchemaProvider([$badDomain, $badCategory, $badTags, $good]);

		$m = new VizionMemoryAgentResource($r, $sp);

		$m->setConfig([
			'domainFilter' => ['sales'],
			'categoryFilter' => ['analytics'],
			'tagFilter' => ['public']
		]);

		$prompt = (string)$m->loadNodeHistory('n1')[0]['content'];

		$this->assertStringContainsString('## Example (schema-derived)', $prompt);
		$this->assertStringContainsString('"table": "good_table"', $prompt);

		$this->assertStringNotContainsString('"table": "bad_domain"', $prompt);
		$this->assertStringNotContainsString('"table": "bad_category"', $prompt);
		$this->assertStringNotContainsString('"table": "bad_tags"', $prompt);
	}

	public function testExamplePicksAlphabeticallyFirstMatchingTable(): void {
		$r = $this->makeResolverPassthrough();

		$a = new TableMetadata(
			name: 'aaa_table',
			label: 'A',
			domain: '',
			category: '',
			tags: [],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', sensitive: false)
			],
			sensitive: false
		);

		$b = new TableMetadata(
			name: 'bbb_table',
			label: 'B',
			domain: '',
			category: '',
			tags: [],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', sensitive: false)
			],
			sensitive: false
		);

		// Provide in reverse order; implementation sorts by name.
		$sp = $this->makeSchemaProvider([$b, $a]);

		$m = new VizionMemoryAgentResource($r, $sp);

		$prompt = (string)$m->loadNodeHistory('n1')[0]['content'];

		$this->assertStringContainsString('"table": "aaa_table"', $prompt);
		$this->assertStringNotContainsString('"table": "bbb_table"', $prompt);
	}

	public function testAppendResetAndFeedbackAreNoOpsOrFalse(): void {
		$r = $this->makeResolverPassthrough();
		$sp = $this->makeSchemaProvider([]);

		$m = new VizionMemoryAgentResource($r, $sp);

		// No-op methods should not throw.
		$m->appendNodeHistory('n1', ['role' => 'user', 'content' => 'x']);
		$m->resetNodeHistory('n1');

		$this->assertFalse($m->setFeedback('n1', 'm1', 'up'));
		$this->assertFalse($m->setFeedback('n1', 'm1', null));
	}
}
