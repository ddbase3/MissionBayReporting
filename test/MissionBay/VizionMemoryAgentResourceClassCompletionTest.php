<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use MissionBay\Api\IAgentConfigValueResolver;
use MissionBayReporting\MissionBay\VizionMemoryAgentResource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQuerySchemaProvider;
use ResourceFoundation\Dto\FieldMetadata;
use ResourceFoundation\Dto\TableMetadata;

#[CoversClass(VizionMemoryAgentResource::class)]
final class VizionMemoryAgentResourceClassCompletionTest extends TestCase {

	private function resource(array $tables, array $config = []): VizionMemoryAgentResource {
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(static fn(mixed $value): mixed => $value);
		$schema = $this->createStub(IQuerySchemaProvider::class);
		$schema->method('getSchema')->willReturn($tables);
		$resource = new VizionMemoryAgentResource($resolver, $schema);
		$resource->setConfig($config);
		return $resource;
	}

	private function table(string $name, array $fields = [], array $tags = ['Public']): TableMetadata {
		return new TableMetadata(
			name: $name,
			label: '',
			domain: 'learning',
			category: 'reporting',
			tags: $tags,
			fields: $fields
		);
	}

	private function prompt(VizionMemoryAgentResource $resource): string {
		$history = $resource->loadNodeHistory('reporting-node');
		$this->assertSame('system', $history[0]['role']);
		return $history[0]['content'];
	}

	public function testMalformedSchemaEntriesAndFieldsAreIgnoredWithoutExposingPrivateData(): void {
		$table = $this->table('visible', [
			'not field metadata',
			new FieldMetadata(name: 'secret', type: 'string', sensitive: true),
			new FieldMetadata(name: 'id', type: 'int', sensitive: false)
		]);
		$prompt = $this->prompt($this->resource(['not a table', $table]));
		$this->assertStringContainsString('"table": "visible"', $prompt);
		$this->assertStringContainsString('"field": "id"', $prompt);
		$this->assertStringNotContainsString('"field": "secret"', $prompt);
	}

	public function testTablesWithoutTagsAreExcludedWhenTagFilterIsConfigured(): void {
		$noTags = $this->table('without_tags', [new FieldMetadata(name: 'id', type: 'int')], []);
		$withTags = $this->table('with_tags', [new FieldMetadata(name: 'id', type: 'int')], ['PUBLIC']);
		$prompt = $this->prompt($this->resource([$noTags, $withTags], [
			'domainFilter' => ['other', 'LEARNING'],
			'categoryFilter' => ['other', 'reporting'],
			'tagFilter' => ['missing', 'public']
		]));
		$this->assertStringContainsString('"table": "with_tags"', $prompt);
		$this->assertStringNotContainsString('"table": "without_tags"', $prompt);
	}

	public function testInvalidUtf8LabelCannotProduceAnInvalidSchemaDerivedJsonExample(): void {
		$table = $this->table('safe_name', [new FieldMetadata(name: 'id', type: 'int')]);
		$table->label = "\xB1";
		$prompt = $this->prompt($this->resource([$table]));
		$this->assertStringContainsString('## Canvas Output Policy', $prompt);
		$this->assertStringContainsString('## Reporting Tool Rules', $prompt);
		$this->assertStringNotContainsString('## Example (schema-derived)', $prompt);
	}

	public function testOnlySensitiveOrMalformedFieldsDoNotCreateAnEmptyExample(): void {
		$table = $this->table('hidden', [
			new FieldMetadata(name: 'email', type: 'string', sensitive: true),
			'not a field',
			new FieldMetadata(name: 'token', type: 'string', sensitive: true)
		]);
		$prompt = $this->prompt($this->resource([$table]));
		$this->assertStringNotContainsString('## Example (schema-derived)', $prompt);
		$this->assertStringNotContainsString('"field": "email"', $prompt);
	}

	public function testPriorityAndNonStringFilterValuesRetainConfiguredSemantics(): void {
		$resource = $this->resource([$this->table('visible', [new FieldMetadata(name: 'id', type: 'int')])], [
			'priority' => '0',
			'domainFilter' => false,
			'categoryFilter' => 32,
			'tagFilter' => [null, ['nested'], '', ' PUBLIC ', 'public']
		]);
		$this->assertSame(0, $resource->getPriority());
		$this->assertStringContainsString('"table": "visible"', $this->prompt($resource));

		$resource->setConfig(['priority' => null, 'tagFilter' => '']);
		$this->assertSame(56, $resource->getPriority());
		$this->assertStringContainsString('"table": "visible"', $this->prompt($resource));
	}

	public function testScalarTagsAndNonMatchingCategoriesDoNotBypassPresetFilters(): void {
		$table = $this->table('sensitive_category', [new FieldMetadata(name: 'id', type: 'int')]);
		$table->category = 'internal';
		$prompt = $this->prompt($this->resource([$table], [
			'domainFilter' => 'learning',
			'categoryFilter' => 'reporting',
			'tagFilter' => 'public'
		]));
		$this->assertStringNotContainsString('## Example (schema-derived)', $prompt);
	}
}
