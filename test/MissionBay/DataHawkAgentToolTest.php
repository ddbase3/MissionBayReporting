<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBayReporting\MissionBay\DataHawkAgentTool;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Api\IScopedQuerySchemaProvider;
use ResourceFoundation\Dto\FieldMetadata;
use ResourceFoundation\Dto\QueryResult;
use ResourceFoundation\Dto\TableMetadata;

/**
 * @covers \MissionBayReporting\MissionBay\DataHawkAgentTool
 */
final class DataHawkAgentToolTest extends TestCase {

	private const REPORTING_SCOPE = 'learning';
	private const USER_SCHEMA = 'users';
	private const COURSE_SCHEMA = 'courses';

	private function makeContextStub(): IAgentContext {
		return $this->createStub(IAgentContext::class);
	}

	private function makeResolver(): IAgentConfigValueResolver {
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(fn($value) => $value);
		return $resolver;
	}

	/**
	 * @param array<string,TableMetadata[]> $schemas
	 */
	private function makeSchemaProvider(array $schemas): IScopedQuerySchemaProvider {
		$provider = $this->createStub(IScopedQuerySchemaProvider::class);
		$provider->method('getScopes')->willReturn(array_keys($schemas));
		$provider->method('getSchemaForScope')->willReturnCallback(
			fn(string $scope): array => $schemas[$scope] ?? []
		);
		$provider->method('getTableForScope')->willReturnCallback(
			function(string $scope, string $tableName) use ($schemas): ?TableMetadata {
				foreach ($schemas[$scope] ?? [] as $table) {
					if ($table->name === $tableName) {
						return $table;
					}
				}
				return null;
			}
		);
		return $provider;
	}

	/**
	 * @param array<string,TableMetadata[]> $schemas
	 * @param string[]|null $querySchemaScopes
	 * @param array<string,mixed> $config
	 */
	private function makeTool(
		IQueryService $queryService,
		array $schemas,
		?array $querySchemaScopes = null,
		array $config = []
	): DataHawkAgentTool {
		$querySchemaScopes ??= array_keys($schemas);
		$visibleTables = [];
		foreach ($schemas as $scope => $tables) {
			if (in_array($scope, $querySchemaScopes, true)) {
				array_push($visibleTables, ...$tables);
			}
		}
		$queryService->method('listTables')->willReturn($visibleTables);
		$tool = new DataHawkAgentTool(
			$queryService,
			$this->makeSchemaProvider($schemas),
			$this->makeResolver()
		);
		$tool->setConfig($config);
		return $tool;
	}

	private function makeUserTable(string $name = 'user_report_rows'): TableMetadata {
		return new TableMetadata(
			name: $name,
			label: 'Users',
			description: 'Reporting rows for ILIAS users.',
			domain: 'ilias_materialized',
			category: 'reporting',
			tags: ['user', 'reporting'],
			fields: [
				new FieldMetadata(name: 'usr_id', type: 'int', description: 'User ID', primaryKey: true),
				new FieldMetadata(name: 'login', type: 'str', description: 'Login name', sensitive: true),
				new FieldMetadata(name: 'firstname', type: 'str', description: 'First name', sensitive: true),
				new FieldMetadata(name: 'lastname', type: 'str', description: 'Last name', sensitive: true),
				new FieldMetadata(name: 'email', type: 'str', description: 'E-mail address', sensitive: true),
				new FieldMetadata(name: 'active', type: 'bool', description: 'Account is active')
			],
			sensitive: true
		);
	}

	private function makeCourseTable(string $name = 'course_report_rows'): TableMetadata {
		return new TableMetadata(
			name: $name,
			label: 'Courses',
			description: 'Reporting rows for courses and participants.',
			domain: 'ilias_materialized',
			category: 'reporting',
			tags: ['course', 'reporting'],
			fields: [
				new FieldMetadata(name: 'course_id', type: 'int'),
				new FieldMetadata(name: 'course_title', type: 'str')
			]
		);
	}

	public function testGetName(): void {
		$this->assertSame('datahawkagenttool', DataHawkAgentTool::getName());
	}

	public function testToolPublishesDescribeAndQueryFunctions(): void {
		$tool = $this->makeTool($this->createStub(IQueryService::class), [self::USER_SCHEMA => []]);
		$definitions = $tool->getToolDefinitions();

		$this->assertCount(2, $definitions);
		$this->assertSame('describe_reporting_data', $definitions[0]['function']['name']);
		$this->assertSame('execute_datahawk_query', $definitions[1]['function']['name']);
		$this->assertTrue($definitions[0]['readOnlyHint']);
		$this->assertTrue($definitions[1]['readOnlyHint']);
		$this->assertSame(['topic', 'search', 'table', 'limit'], array_keys($definitions[0]['function']['parameters']['properties']));
		$this->assertArrayNotHasKey('schema', $definitions[0]['function']['parameters']['properties']);
		$this->assertSame('object', $definitions[1]['function']['parameters']['properties']['query']['type']);
		$this->assertSame(['query'], $definitions[1]['function']['parameters']['required']);
	}

	public function testSchemaUsesDirectReportingTableFilters(): void {
		$tool = new DataHawkAgentTool(
			$this->createStub(IQueryService::class),
			$this->makeSchemaProvider([self::USER_SCHEMA => []]),
			$this->makeResolver()
		);
		$schema = $tool->getSchema();

		$this->assertSame('object', $schema['type']);
		$this->assertSame([], $schema['required']);
		$this->assertArrayHasKey('priority', $schema['properties']);
		$this->assertArrayHasKey('domainFilter', $schema['properties']);
		$this->assertArrayHasKey('categoryFilter', $schema['properties']);
		$this->assertArrayHasKey('tagFilter', $schema['properties']);
		$this->assertArrayHasKey('tableFilter', $schema['properties']);
		$this->assertArrayHasKey('defaultLimit', $schema['properties']);
		$this->assertArrayHasKey('maxLimit', $schema['properties']);
	}

	public function testConfigurationFiltersKnownTablesWithoutReportingScopeRegistry(): void {
		$tool = $this->makeTool(
			$this->createStub(IQueryService::class),
			[self::USER_SCHEMA => [$this->makeUserTable()], self::COURSE_SCHEMA => [$this->makeCourseTable()]],
			null,
			['tagFilter' => ['course']]
		);

		$result = $tool->callTool('describe_reporting_data', [], $this->makeContextStub());
		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['available_table_count']);
		$this->assertSame('course_report_rows', $result['tables'][0]['name']);
	}

	public function testDescribeSearchReturnsExactSchemaAndTable(): void {
		$tool = $this->makeTool(
			$this->createStub(IQueryService::class),
			[
				self::COURSE_SCHEMA => [$this->makeCourseTable()],
				self::USER_SCHEMA => [$this->makeUserTable()]
			]
		);

		$result = $tool->callTool(
			'describe_reporting_data',
			['search' => 'user'],
			$this->makeContextStub()
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('search', $result['mode']);
		$this->assertArrayNotHasKey('reporting_scope', $result);
		$this->assertSame('user_report_rows', $result['tables'][0]['name']);
		$this->assertNotEmpty($result['tables'][0]['matched_fields']);
		$this->assertContains('firstname', $result['tables'][0]['field_names']);
		$this->assertArrayNotHasKey('query_rules', $result);
	}

	public function testCatalogIncludesTablesFromDifferentVisibleQueryScopes(): void {
		$tool = $this->makeTool(
			$this->createStub(IQueryService::class),
			[
				self::USER_SCHEMA => [$this->makeUserTable()],
				self::COURSE_SCHEMA => [$this->makeCourseTable()]
			]
		);

		$result = $tool->callTool('describe_reporting_data', [], $this->makeContextStub());

		$this->assertTrue($result['ok']);
		$this->assertSame(2, $result['available_table_count']);
		$this->assertCount(2, $result['tables']);
		$this->assertSame(['course_report_rows', 'user_report_rows'], array_column($result['tables'], 'name'));
	}

	public function testDescribeExactTableDoesNotRequireQuerySchema(): void {
		$tool = $this->makeTool(
			$this->createStub(IQueryService::class),
			[self::USER_SCHEMA => [$this->makeUserTable()]]
		);

		$result = $tool->callTool(
			'describe_reporting_data',
			['table' => 'user_report_rows'],
			$this->makeContextStub()
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('table', $result['mode']);
		$this->assertSame('user_report_rows', $result['table']['name']);
	}

	public function testDescribeUnknownTableReturnsAvailableTableNameSuggestions(): void {
		$tool = $this->makeTool(
			$this->createStub(IQueryService::class),
			[self::USER_SCHEMA => [$this->makeUserTable()]]
		);

		$result = $tool->callTool(
			'describe_reporting_data',
			['table' => 'usr_data'],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('table_not_available', $result['error']['code']);
		$this->assertSame('user_report_rows', $result['suggestions'][0]);
	}

	public function testDescribeExactTableReturnsFieldMetadataAndQueryExample(): void {
		$tool = $this->makeTool(
			$this->createStub(IQueryService::class),
			[self::USER_SCHEMA => [$this->makeUserTable()]]
		);

		$result = $tool->callTool(
			'describe_reporting_data',
			['table' => 'user_report_rows'],
			$this->makeContextStub()
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('table', $result['mode']);
		$this->assertSame('user_report_rows', $result['query_example']['table']);
		$this->assertSame('user_report_rows', $result['table']['name']);

		$fieldNames = array_column($result['table']['fields'], 'name');
		$this->assertContains('firstname', $fieldNames);
		$this->assertContains('lastname', $fieldNames);
		$this->assertContains('email', $fieldNames);
	}

	public function testConfigTableFilterRestrictsListedTables(): void {
		$tool = $this->makeTool(
			$this->createStub(IQueryService::class),
			[
				self::USER_SCHEMA => [$this->makeUserTable()],
				self::COURSE_SCHEMA => [$this->makeCourseTable()]
			],
			null,
			['tableFilter' => ['user_report_rows']]
		);

		$result = $tool->callTool('describe_reporting_data', [], $this->makeContextStub());

		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['available_table_count']);
		$this->assertSame('user_report_rows', $result['tables'][0]['name']);
	}

	public function testQueryAddsDefaultLimitAndExecutesExactSchema(): void {
		$query = [
			'type' => 'select',
			'schema' => self::USER_SCHEMA,
			'table' => 'user_report_rows',
			'fields' => [
				[
					'element' => ['type' => 'fld', 'table' => 'user_report_rows', 'field' => 'firstname'],
					'alias' => 'firstname'
				]
			],
			'where' => [
				'type' => 'op',
				'operator' => '=',
				'params' => [
					['type' => 'fld', 'table' => 'user_report_rows', 'field' => 'firstname'],
					'Daniel'
				]
			]
		];

		$service = $this->createMock(IQueryService::class);
		$service->expects($this->once())
			->method('executeQuery')
			->with($this->callback(function(array $actual): bool {
				return $actual['type'] === 'select'
					&& $actual['schema'] === self::USER_SCHEMA
					&& $actual['limit'] === 100
					&& $actual['where']['params'][1] === 'Daniel';
			}))
			->willReturn(new QueryResult(
				columns: [['name' => 'firstname', 'type' => 'string', 'sensitive' => true]],
				rows: [['firstname' => 'Daniel']],
				sensitive: true
			));

		$tool = $this->makeTool($service, [self::USER_SCHEMA => [$this->makeUserTable()]]);
		$result = $tool->callTool('execute_datahawk_query', ['query' => $query], $this->makeContextStub());

		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['count']);
		$this->assertTrue($result['sensitive']);
		$this->assertSame(100, $result['limit']);
	}

	public function testQueryInfersUnambiguousSchemaBeforeExecution(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->once())->method('executeQuery')
			->with($this->callback(static fn(array $query): bool =>
				($query['schema'] ?? null) === self::USER_SCHEMA
				&& ($query['table'] ?? null) === 'user_report_rows'
			))
			->willReturn(new QueryResult(columns: [], rows: [['usr_id' => 42]]));
		$tool = $this->makeTool($service, [self::USER_SCHEMA => [$this->makeUserTable()]]);

		$result = $tool->callTool('execute_datahawk_query', ['query' => [
			'type' => 'select',
			'table' => 'user_report_rows',
			'fields' => [[
				'element' => ['type' => 'fld', 'table' => 'user_report_rows', 'field' => 'usr_id']
			]]
		]], $this->makeContextStub());

		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['count']);
	}

	public function testQueryRejectsTableOutsideVisibleQueryServiceTables(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool(
			$service,
			[
				self::USER_SCHEMA => [$this->makeUserTable()],
				self::COURSE_SCHEMA => [$this->makeCourseTable()]
			],
			[self::USER_SCHEMA]
		);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
				'schema' => self::COURSE_SCHEMA,
				'table' => 'course_report_rows',
				'fields' => [[
					'element' => ['type' => 'fld', 'table' => 'course_report_rows', 'field' => 'course_id']
				]]
			]],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('table is not available in this reporting preset', $result['error']['message']);
	}

	public function testQueryRejectsUnsafeProviderIdentifier(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service, [self::USER_SCHEMA => [$this->makeUserTable()]]);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
				'schema' => self::USER_SCHEMA,
				'provider' => '../unsafe',
				'table' => 'user_report_rows',
				'fields' => [[
					'element' => ['type' => 'fld', 'table' => 'user_report_rows', 'field' => 'usr_id']
				]]
			]],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('invalid technical name', $result['error']['message']);
	}

	public function testQueryRejectsJsonStringInput(): void {
		$tool = $this->makeTool(
			$this->createStub(IQueryService::class),
			[self::USER_SCHEMA => [$this->makeUserTable()]]
		);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => '{"type":"select"}'],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('invalid_query', $result['error']['code']);
	}

	public function testQueryRejectsWriteOperationsBeforeExecution(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service, [self::USER_SCHEMA => [$this->makeUserTable()]]);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => ['type' => 'update', 'schema' => self::USER_SCHEMA, 'table' => 'user_report_rows']],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('invalid_query', $result['error']['code']);
		$this->assertStringContainsString('only SELECT queries are allowed', $result['error']['message']);
	}

	public function testQueryRejectsTableOutsidePresetFilter(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool(
			$service,
			[self::USER_SCHEMA => [$this->makeUserTable(), $this->makeCourseTable()]],
			null,
			['tableFilter' => ['user_report_rows']]
		);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
				'schema' => self::USER_SCHEMA,
				'table' => 'course_report_rows',
				'fields' => [[
					'element' => ['type' => 'fld', 'table' => 'course_report_rows', 'field' => 'course_id']
				]]
			]],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('table is not available', $result['error']['message']);
	}

	public function testQueryRejectsUnknownField(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service, [self::USER_SCHEMA => [$this->makeUserTable()]]);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
				'schema' => self::USER_SCHEMA,
				'table' => 'user_report_rows',
				'fields' => [[
					'element' => ['type' => 'fld', 'table' => 'user_report_rows', 'field' => 'invented']
				]]
			]],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('field is not available', $result['error']['message']);
	}

	public function testQueryRejectsUnsafeFunctionName(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service, [self::USER_SCHEMA => [$this->makeUserTable()]]);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
				'schema' => self::USER_SCHEMA,
				'table' => 'user_report_rows',
				'fields' => [[
					'element' => ['type' => 'fn', 'function' => 'EVIL_SQL', 'params' => []],
					'alias' => 'x'
				]]
			]],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('unsupported reporting function', $result['error']['message']);
	}

	public function testQueryRejectsLimitAbovePresetMaximum(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool(
			$service,
			[self::USER_SCHEMA => [$this->makeUserTable()]],
			null,
			['maxLimit' => 10]
		);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
				'schema' => self::USER_SCHEMA,
				'table' => 'user_report_rows',
				'fields' => [[
					'element' => ['type' => 'fld', 'table' => 'user_report_rows', 'field' => 'usr_id']
				]],
				'limit' => 11
			]],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('configured maximum of 10', $result['error']['message']);
	}
}
