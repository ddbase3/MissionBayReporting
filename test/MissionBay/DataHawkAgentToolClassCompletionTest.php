<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBayReporting\MissionBay\DataHawkAgentTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Api\IScopedQuerySchemaProvider;
use ResourceFoundation\Dto\FieldMetadata;
use ResourceFoundation\Dto\JoinMetadata;
use ResourceFoundation\Dto\QueryResult;
use ResourceFoundation\Dto\TableMetadata;

#[CoversClass(DataHawkAgentTool::class)]
final class DataHawkAgentToolClassCompletionTest extends TestCase {

	private function table(string $name = 'courses', array $fields = []): TableMetadata {
		return new TableMetadata(
			name: $name,
			label: 'Course reporting',
			description: 'Course enrolments and progress',
			domain: 'learning',
			category: 'reporting',
			tags: ['Public', 'Education'],
			fields: $fields ?: [
				new FieldMetadata(name: 'id', type: 'int', primaryKey: true),
				new FieldMetadata(name: 'title', type: 'string', description: 'Course title'),
				new FieldMetadata(name: 'status', type: 'string', tags: ['progress']),
			]
		);
	}

	private function tool(IQueryService $service, ?array $tables, array $config = [], ?IScopedQuerySchemaProvider $schema = null): DataHawkAgentTool {
		if ($tables !== null) {
			$service->method('listTables')->willReturn($tables);
		}
		if ($schema === null) {
			$schema = $this->createStub(IScopedQuerySchemaProvider::class);
			$schema->method('getScopes')->willReturn(['learning']);
			$schema->method('getTableForScope')->willReturnCallback(
				static function(string $scope, string $name) use ($tables): ?TableMetadata {
					if ($scope !== 'learning') {
						return null;
					}
					foreach ($tables as $table) {
						if ($table instanceof TableMetadata && $table->name === $name) {
							return $table;
						}
					}
					return null;
				}
			);
		}
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(static fn(mixed $value): mixed => $value);
		$tool = new DataHawkAgentTool($service, $schema, $resolver);
		$tool->setConfig($config);
		return $tool;
	}

	private function call(DataHawkAgentTool $tool, string $name, array $arguments): array {
		return $tool->callTool($name, $arguments, $this->createStub(IAgentContext::class));
	}

	private function query(array $extra = []): array {
		return array_replace([
			'type' => 'select',
			'table' => 'courses',
			'fields' => [['element' => ['type' => 'fld', 'field' => 'id']]]
		], $extra);
	}

	public function testCatalogCachesVisibleTablesUntilThePresetChanges(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->exactly(2))->method('listTables')->willReturn([
			$this->table('courses'), $this->table('students'), 'not metadata'
		]);
		$tool = $this->tool($service, null);
		$first = $this->call($tool, 'describe_reporting_data', []);
		$second = $this->call($tool, 'describe_reporting_data', []);
		$this->assertSame(2, $first['available_table_count']);
		$this->assertSame($first['tables'], $second['tables']);
		$tool->setConfig(['tableFilter' => ' students ']);
		$filtered = $this->call($tool, 'describe_reporting_data', []);
		$this->assertSame(['students'], array_column($filtered['tables'], 'name'));
	}

	public function testDescribeUnmatchedTableReturnsSortedFallbackSuggestionsAndHandlesEmptyCatalog(): void {
		$tool = $this->tool($this->createStub(IQueryService::class), [
			$this->table('zebra'), $this->table('alpha'), $this->table('beta')
		], ['describeLimit' => 2]);
		$result = $this->call($tool, 'describe_reporting_data', ['table' => 'completely_unrelated']);
		$this->assertFalse($result['ok']);
		$this->assertSame('table_not_available', $result['error']['code']);
		$this->assertSame(['alpha', 'beta'], $result['suggestions']);

		$empty = $this->call($this->tool($this->createStub(IQueryService::class), []), 'describe_reporting_data', [
			'table' => 'unknown'
		]);
		$this->assertSame([], $empty['suggestions']);
	}

	public function testSearchMatchesFieldDescriptionsTokensAndLimitsResults(): void {
		$fields = [new FieldMetadata(name: 'course_ref', type: 'int', description: 'Enrolment progress', tags: ['completion'])];
		$first = $this->table('progress_a', $fields);
		$second = $this->table('progress_b', $fields);
		$tool = $this->tool($this->createStub(IQueryService::class), [$second, $first]);
		$result = $this->call($tool, 'describe_reporting_data', ['search' => 'Enrolment progress', 'limit' => 1]);
		$this->assertTrue($result['ok']);
		$this->assertSame('search', $result['mode']);
		$this->assertSame(2, $result['match_count']);
		$this->assertTrue($result['truncated']);
		$this->assertSame('progress_a', $result['tables'][0]['name']);
		$this->assertSame('course_ref', $result['tables'][0]['matched_fields'][0]['name']);
		$this->assertContains('course_ref', $result['tables'][0]['field_names']);
	}

	public function testTableDetailKeepsOnlyAllowedRelationsAndFormatsFieldMetadata(): void {
		$course = $this->table('courses', [
			new FieldMetadata(name: 'id', type: 'int', primaryKey: true, nullable: false),
			new FieldMetadata(name: 'email', type: 'string', alias: 'contact', sensitive: true, nullable: true, tags: ['private'])
		]);
		$course->joins = [
			new JoinMetadata('students', [['id' => 'course_id']], 'LEFT', ['default' => true]),
			new JoinMetadata('hidden', [['id' => 'course_id']], 'INNER'),
			'not a join'
		];
		$tool = $this->tool($this->createStub(IQueryService::class), [$course, $this->table('students')], [
			'tableFilter' => ['courses', 'students']
		]);
		$result = $this->call($tool, 'describe_reporting_data', ['table' => 'courses']);
		$this->assertTrue($result['ok']);
		$this->assertSame('table', $result['mode']);
		$this->assertCount(1, $result['table']['relations']);
		$this->assertSame('students', $result['table']['relations'][0]['target_table']);
		$this->assertTrue($result['table']['relations'][0]['default']);
		$this->assertSame('contact', $result['table']['fields'][1]['alias']);
		$this->assertTrue($result['table']['fields'][1]['sensitive']);
		$this->assertTrue($result['table']['fields'][1]['nullable']);
		$this->assertSame(2, count($result['query_example']['fields']));
	}

	public function testQueryExampleLimitsFieldsAndUsesConfiguredDefaultLimit(): void {
		$fields = [];
		foreach (range(1, 8) as $index) {
			$fields[] = new FieldMetadata(name: 'field_' . $index, type: 'int');
		}
		$tool = $this->tool($this->createStub(IQueryService::class), [$this->table('courses', $fields)], [
			'defaultLimit' => 3
		]);
		$result = $this->call($tool, 'describe_reporting_data', ['table' => 'courses']);
		$this->assertCount(5, $result['query_example']['fields']);
		$this->assertSame(3, $result['query_example']['limit']);
		$this->assertSame('field_5', $result['query_example']['fields'][4]['alias']);
	}

	public function testSearchIgnoresMalformedFieldEntriesAndRetainsRepresentativeFields(): void {
		$table = $this->table('codes', [
			'not a field',
			new FieldMetadata(name: 'status_code', type: 'string', alias: 'enrolment_state', description: 'Enrolment progress'),
			new FieldMetadata(name: 'id', type: 'int'),
			new FieldMetadata(name: 'status_code', type: 'string')
		]);
		$result = $this->call($this->tool($this->createStub(IQueryService::class), [$table]), 'describe_reporting_data', [
			'search' => 'enrolment'
		]);
		$this->assertSame('codes', $result['tables'][0]['name']);
		$this->assertSame('status_code', $result['tables'][0]['matched_fields'][0]['name']);
		$this->assertSame(['status_code', 'id'], $result['tables'][0]['field_names']);
	}

	public function testFiltersRequireMatchingDomainCategoryTagsAndExactTable(): void {
		$visible = $this->table('courses');
		$wrongDomain = $this->table('other_domain');
		$wrongDomain->domain = 'analytics';
		$wrongCategory = $this->table('other_category');
		$wrongCategory->category = 'internal';
		$wrongTag = $this->table('other_tag');
		$wrongTag->tags = [];
		$wrongTable = $this->table('other_table');
		$tool = $this->tool($this->createStub(IQueryService::class), [
			$wrongDomain, $wrongCategory, $wrongTag, $wrongTable, $visible
		], [
			'domainFilter' => ['other', 'LEARNING'],
			'categoryFilter' => ['reporting'],
			'tagFilter' => ['bad', 'PUBLIC'],
			'tableFilter' => ['courses']
		]);
		$result = $this->call($tool, 'describe_reporting_data', []);
		$this->assertSame(['courses'], array_column($result['tables'], 'name'));
	}

	public function testEmptyAndMalformedFilterConfigurationDoesNotExpandPermissions(): void {
		$tool = $this->tool($this->createStub(IQueryService::class), [$this->table()], [
			'domainFilter' => 99,
			'categoryFilter' => false,
			'tagFilter' => [['ignored'], '', ' Public ', 'PUBLIC'],
			'tableFilter' => null,
			'priority' => 'not a number',
			'describeLimit' => '500'
		]);
		$result = $this->call($tool, 'describe_reporting_data', []);
		$this->assertSame(['courses'], array_column($result['tables'], 'name'));
		$this->assertSame(60, $tool->getToolDefinitions()[0]['priority']);
		$this->assertSame(20, $tool->getSchema()['properties']['describeLimit']['maximum']);
	}

	public function testProviderNamespaceAndFromTableArePreservedWithoutScopeGuessing(): void {
		$captured = null;
		$service = $this->createStub(IQueryService::class);
		$service->method('executeQuery')->willReturnCallback(static function(array $query) use (&$captured): QueryResult {
			$captured = $query;
			return new QueryResult([], []);
		});
		$tool = $this->tool($service, [$this->table()]);
		$result = $this->call($tool, 'execute_datahawk_query', ['query' => [
			'type' => 'SELECT', 'from' => 'courses', 'provider' => 'provider.api-1',
			'fields' => [['element' => ['type' => 'fld', 'field' => '*']]],
			'limit' => '9', 'offset' => 0
		]]);
		$this->assertTrue($result['ok']);
		$this->assertSame('provider.api-1', $captured['provider']);
		$this->assertArrayNotHasKey('schema', $captured);
		$this->assertSame(9, $captured['limit']);
		$this->assertSame('courses', $captured['from']);
	}

	public function testQueryWithoutBaseTableDoesNotInventAnAuthorizationScope(): void {
		$captured = null;
		$service = $this->createStub(IQueryService::class);
		$service->method('executeQuery')->willReturnCallback(static function(array $query) use (&$captured): QueryResult {
			$captured = $query;
			return new QueryResult([], [['value' => 1]]);
		});
		$tool = $this->tool($service, []);
		$result = $this->call($tool, 'execute_datahawk_query', ['query' => [
			'fields' => [['element' => ['type' => 'fn', 'function' => 'COUNT', 'params' => [1]]]]
		]]);
		$this->assertTrue($result['ok']);
		$this->assertArrayNotHasKey('schema', $captured);
		$this->assertArrayNotHasKey('table', $captured);
		$this->assertSame(100, $captured['limit']);
	}

	public function testSingleUnknownScopeDoesNotInventSchemaWhenNotReported(): void {
		$schema = $this->createStub(IScopedQuerySchemaProvider::class);
		$schema->method('getScopes')->willReturn([]);
		$captured = null;
		$service = $this->createStub(IQueryService::class);
		$service->method('executeQuery')->willReturnCallback(static function(array $query) use (&$captured): QueryResult {
			$captured = $query;
			return new QueryResult([], []);
		});
		$result = $this->call($this->tool($service, [$this->table()], [], $schema), 'execute_datahawk_query', [
			'query' => $this->query()
		]);
		$this->assertTrue($result['ok']);
		$this->assertArrayNotHasKey('schema', $captured);
	}

	public function testValidWindowCaseSubqueryAndWildcardExpressionsArePreserved(): void {
		$captured = null;
		$service = $this->createStub(IQueryService::class);
		$service->method('executeQuery')->willReturnCallback(static function(array $query) use (&$captured): QueryResult {
			$captured = $query;
			return new QueryResult([], []);
		});
		$tool = $this->tool($service, [$this->table()]);
		$subquery = $this->query(['limit' => 2]);
		$query = $this->query([
			'fields' => [
				['element' => ['type' => 'fld', 'field' => '*']],
				['element' => ['type' => 'windowfn', 'function' => 'ROW_NUMBER', 'params' => []]],
				['element' => ['type' => 'case', 'cases' => [['when' => true, 'then' => 1]]]],
				['element' => ['type' => 'subquery', 'query' => $subquery]]
			],
			'order_by' => [['element' => ['type' => 'fld', 'table' => 'courses', 'field' => 'id']]]
		]);
		$result = $this->call($tool, 'execute_datahawk_query', ['query' => $query]);
		$this->assertTrue($result['ok']);
		$this->assertSame(2, $captured['fields'][3]['element']['query']['limit']);
		$this->assertSame('learning', $captured['fields'][3]['element']['query']['schema']);
		$this->assertSame('ROW_NUMBER', $captured['fields'][1]['element']['function']);
	}

	public function testMalformedUnionMembersAndRecursiveFieldsAreRejected(): void {
		$tool = $this->tool($this->createStub(IQueryService::class), [$this->table()]);
		$cases = [
			[$this->query(['union' => ['queries' => [$this->query(), 'not a query']]]), 'must be a query object'],
			[$this->query(['fields' => [['element' => ['type' => 'fn', 'function' => 'COUNT', 'params' => 'invalid']]]]), 'params array'],
			[$this->query(['fields' => [['element' => ['type' => 'op', 'operator' => '=', 'params' => 'invalid']]]]), 'params array'],
			[$this->query(['fields' => [['element' => ['type' => 'subquery', 'query' => []]]]]), 'non-empty fields array'],
			[$this->query(['fields' => [['element' => ['type' => 'fld', 'field' => 'id', 'table' => 'unknown']]]]), 'table is not available']
		];
		foreach ($cases as [$query, $message]) {
			$result = $this->call($tool, 'execute_datahawk_query', ['query' => $query]);
			$this->assertFalse($result['ok']);
			$this->assertSame('invalid_query', $result['error']['code']);
			$this->assertStringContainsString($message, $result['error']['message']);
		}
	}

	public function testInvalidTypedExpressionsAndInvalidOffsetsAreRejected(): void {
		$tool = $this->tool($this->createStub(IQueryService::class), [$this->table()]);
		$cases = [
			[$this->query(['fields' => [['element' => ['type' => 'windowfn', 'function' => 'EVAL', 'params' => []]]]]), 'unsupported reporting function'],
			[$this->query(['fields' => [['element' => ['type' => 'fn', 'function' => '']]]]), 'unsupported reporting function'],
			[$this->query(['fields' => [['element' => ['type' => 'op', 'operator' => '', 'params' => []]]]]), 'unsupported reporting operator'],
			[$this->query(['fields' => [['element' => ['type' => 'case', 'cases' => 'invalid']]]]), 'non-empty cases array'],
			[$this->query(['offset' => -5]), 'must not be negative'],
			[$this->query(['limit' => 'not-numeric']), 'must be an integer'],
			[$this->query(['schema' => 123]), 'invalid technical name'],
			[$this->query(['provider' => true]), 'invalid technical name']
		];
		foreach ($cases as [$query, $expected]) {
			$result = $this->call($tool, 'execute_datahawk_query', ['query' => $query]);
			$this->assertFalse($result['ok']);
			$this->assertSame('invalid_query', $result['error']['code']);
			$this->assertStringContainsString($expected, $result['error']['message']);
		}
	}

	public function testDescribeLimitAcceptsNumericTextRejectsBooleansAndClampsMaximum(): void {
		$tables = [];
		for ($i = 0; $i < 21; $i++) {
			$tables[] = $this->table('table_' . $i);
		}
		$tool = $this->tool($this->createStub(IQueryService::class), $tables, [
			'describeLimit' => 2
		]);
		foreach ([['1', 1], [99, 20], ['', 2], [-1, 1], [0, 1]] as [$limit, $expected]) {
			$result = $this->call($tool, 'describe_reporting_data', ['limit' => $limit]);
			$this->assertTrue($result['ok']);
			$this->assertCount($expected, $result['tables']);
		}
		foreach ([true, '-1', '1.5'] as $limit) {
			$result = $this->call($tool, 'describe_reporting_data', ['limit' => $limit]);
			$this->assertFalse($result['ok']);
			$this->assertSame('describe_failed', $result['error']['code']);
		}
	}
}
