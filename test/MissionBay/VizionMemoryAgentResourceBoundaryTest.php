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
final class VizionMemoryAgentResourceBoundaryTest extends TestCase {

	private function makeResource(array $schema, array $config = []): VizionMemoryAgentResource {
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(static fn(mixed $value): mixed => $value);
		$provider = $this->createStub(IQuerySchemaProvider::class);
		$provider->method('getSchema')->willReturn($schema);
		$resource = new VizionMemoryAgentResource($resolver, $provider);
		$resource->setConfig($config);
		return $resource;
	}

	private function table(string $name, array $fields, bool $sensitive = false): TableMetadata {
		return new TableMetadata(
			name: $name,
			label: '',
			domain: 'learning',
			category: 'reporting',
			tags: ['Public'],
			fields: $fields,
			sensitive: $sensitive
		);
	}

	public function testExampleRejectsSensitiveColumnsEvenInNonSensitiveTables(): void {
		$table = $this->table('private_fields', [
			new FieldMetadata(name: 'email', type: 'string', sensitive: true),
			new FieldMetadata(name: 'token', type: 'string', sensitive: true)
		]);
		$prompt = $this->makeResource([$table])->loadNodeHistory('node')[0]['content'];
		$this->assertStringContainsString('## Canvas Output Policy', $prompt);
		$this->assertStringNotContainsString('## Example (schema-derived)', $prompt);
		$this->assertStringNotContainsString('"field": "email"', $prompt);
		$this->assertStringNotContainsString('"field": "token"', $prompt);
	}

	public function testExampleSkipsIneligibleTablesAndUsesFallbackLabel(): void {
		$noFields = $this->table('a_no_fields', []);
		$sensitive = $this->table('b_sensitive', [new FieldMetadata(name: 'id', type: 'int')], true);
		$eligible = $this->table('c_public', [new FieldMetadata(name: 'name', type: 'string')]);
		$prompt = $this->makeResource([$noFields, $sensitive, $eligible])->loadNodeHistory('node')[0]['content'];
		$this->assertStringContainsString('"table": "c_public"', $prompt);
		$this->assertStringContainsString('"title": "c_public"', $prompt);
		$this->assertStringNotContainsString('"table": "a_no_fields"', $prompt);
		$this->assertStringNotContainsString('"table": "b_sensitive"', $prompt);
	}

	public function testExampleCapsProjectionToFourNonSensitiveFields(): void {
		$fields = [];
		foreach(range(1, 6) as $number) {
			$fields[] = new FieldMetadata(name: 'field_' . $number, type: 'int');
		}
		$prompt = $this->makeResource([$this->table('wide_report', $fields)])->loadNodeHistory('node')[0]['content'];
		foreach(range(1, 4) as $number) {
			$this->assertStringContainsString('"field": "field_' . $number . '"', $prompt);
		}
		$this->assertStringNotContainsString('"field": "field_5"', $prompt);
		$this->assertStringNotContainsString('"field": "field_6"', $prompt);
	}

	public function testCaseInsensitiveDomainCategoryAndTagFiltersOnlyExposeMatchingTable(): void {
		$correct = $this->table('visible', [new FieldMetadata(name: 'id', type: 'int')]);
		$other = $this->table('hidden', [new FieldMetadata(name: 'id', type: 'int')]);
		$other->tags = ['internal'];
		$resource = $this->makeResource([$other, $correct], [
			'domainFilter' => [' LEARNING ', ['ignored'], ''],
			'categoryFilter' => 'REPORTING',
			'tagFilter' => 'public'
		]);
		$prompt = $resource->loadNodeHistory('node')[0]['content'];
		$this->assertStringContainsString('"table": "visible"', $prompt);
		$this->assertStringNotContainsString('"table": "hidden"', $prompt);
	}
}
