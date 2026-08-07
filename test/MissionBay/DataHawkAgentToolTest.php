<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBayReporting\MissionBay\DataHawkAgentTool;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Dto\FieldMetadata;
use ResourceFoundation\Dto\QueryResult;
use ResourceFoundation\Dto\TableMetadata;

/**
 * @covers \MissionBayReporting\MissionBay\DataHawkAgentTool
 */
final class DataHawkAgentToolTest extends TestCase {

	private function makeContextStub(): IAgentContext {
		return $this->createStub(IAgentContext::class);
	}

	private function makeResolver(): IAgentConfigValueResolver {
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(fn($value) => $value);
		return $resolver;
	}

	/**
	 * @param TableMetadata[] $tables
	 */
	private function makeQueryService(array $tables): IQueryService {
		$service = $this->createStub(IQueryService::class);
		$service->method('listTables')->willReturn($tables);
		$service->method('getTable')->willReturnCallback(function(string $name) use ($tables): ?TableMetadata {
			foreach ($tables as $table) {
				if ($table->name === $name) {
					return $table;
				}
			}
			return null;
		});
		return $service;
	}

	private function makeUserTable(): TableMetadata {
		return new TableMetadata(
			name: 'user_report_rows',
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

	private function makeCourseTable(): TableMetadata {
		return new TableMetadata(
			name: 'course_report_rows',
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
		$tool = new DataHawkAgentTool($this->makeQueryService([]), $this->makeResolver());
		$definitions = $tool->getToolDefinitions();

		$this->assertCount(2, $definitions);
		$this->assertSame('describe_reporting_data', $definitions[0]['function']['name']);
		$this->assertSame('execute_datahawk_query', $definitions[1]['function']['name']);
		$this->assertTrue($definitions[0]['readOnlyHint']);
		$this->assertTrue($definitions[1]['readOnlyHint']);
		$this->assertSame('object', $definitions[1]['function']['parameters']['properties']['query']['type']);
		$this->assertSame(['query'], $definitions[1]['function']['parameters']['required']);
	}

	public function testSchemaProvidesPresetScopeConfiguration(): void {
		$tool = new DataHawkAgentTool($this->makeQueryService([]), $this->makeResolver());
		$schema = $tool->getSchema();

		$this->assertSame('object', $schema['type']);
		$this->assertArrayHasKey('domainFilter', $schema['properties']);
		$this->assertArrayHasKey('categoryFilter', $schema['properties']);
		$this->assertArrayHasKey('tagFilter', $schema['properties']);
		$this->assertArrayHasKey('tableFilter', $schema['properties']);
		$this->assertArrayHasKey('defaultLimit', $schema['properties']);
		$this->assertArrayHasKey('maxLimit', $schema['properties']);
	}

	public function testDescribeSearchFindsUserTableAndSensitiveFields(): void {
		$tool = new DataHawkAgentTool(
			$this->makeQueryService([$this->makeCourseTable(), $this->makeUserTable()]),
			$this->makeResolver()
		);

		$result = $tool->callTool(
			'describe_reporting_data',
			['search' => 'user'],
			$this->makeContextStub()
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('search', $result['mode']);
		$this->assertSame('user_report_rows', $result['tables'][0]['name']);
		$this->assertNotEmpty($result['tables'][0]['matched_fields']);
		$this->assertContains('firstname', $result['tables'][0]['field_names']);
		$this->assertArrayNotHasKey('query_rules', $result);
		$this->assertStringContainsString('request that table once', $result['next_step']);
	}

	public function testDescribeUnknownTableReturnsValidAlternatives(): void {
		$tool = new DataHawkAgentTool(
			$this->makeQueryService([$this->makeCourseTable(), $this->makeUserTable()]),
			$this->makeResolver()
		);

		$result = $tool->callTool(
			'describe_reporting_data',
			['table' => 'usr_data'],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('table_not_available', $result['error']['code']);
		$this->assertNotEmpty($result['suggestions']);
		$this->assertContains('user_report_rows', $result['suggestions']);
		$this->assertStringContainsString('do not guess', $result['error']['message']);
	}

	public function testDescribeExactTableReturnsPersonalFields(): void {
		$tool = new DataHawkAgentTool($this->makeQueryService([$this->makeUserTable()]), $this->makeResolver());

		$result = $tool->callTool(
			'describe_reporting_data',
			['table' => 'user_report_rows'],
			$this->makeContextStub()
		);

		$this->assertTrue($result['ok']);
		$this->assertSame('table', $result['mode']);
		$this->assertSame('user_report_rows', $result['table']['name']);

		$fieldNames = array_column($result['table']['fields'], 'name');
		$this->assertContains('firstname', $fieldNames);
		$this->assertContains('lastname', $fieldNames);
		$this->assertContains('email', $fieldNames);
	}

	public function testConfigFiltersTheAvailableReportingTables(): void {
		$tool = new DataHawkAgentTool(
			$this->makeQueryService([$this->makeCourseTable(), $this->makeUserTable()]),
			$this->makeResolver()
		);
		$tool->setConfig(['tableFilter' => ['user_report_rows']]);

		$result = $tool->callTool('describe_reporting_data', [], $this->makeContextStub());

		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['available_table_count']);
		$this->assertSame('user_report_rows', $result['tables'][0]['name']);
	}

	public function testQueryAddsDefaultLimitAndExecutesReadOnlySelect(): void {
		$query = [
			'type' => 'select',
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
		$service->method('listTables')->willReturn([$this->makeUserTable()]);
		$service->expects($this->once())
			->method('executeQuery')
			->with($this->callback(function(array $actual): bool {
				return $actual['type'] === 'select'
					&& $actual['limit'] === 100
					&& $actual['where']['params'][1] === 'Daniel';
			}))
			->willReturn(new QueryResult(
				columns: [['name' => 'firstname', 'type' => 'string', 'sensitive' => true]],
				rows: [['firstname' => 'Daniel']],
				sensitive: true
			));

		$tool = new DataHawkAgentTool($service, $this->makeResolver());
		$result = $tool->callTool('execute_datahawk_query', ['query' => $query], $this->makeContextStub());

		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['count']);
		$this->assertTrue($result['sensitive']);
		$this->assertSame(100, $result['limit']);
	}

	public function testQueryRejectsJsonStringInput(): void {
		$tool = new DataHawkAgentTool($this->makeQueryService([$this->makeUserTable()]), $this->makeResolver());

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
		$service->method('listTables')->willReturn([$this->makeUserTable()]);
		$service->expects($this->never())->method('executeQuery');

		$tool = new DataHawkAgentTool($service, $this->makeResolver());
		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => ['type' => 'update', 'table' => 'user_report_rows']],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertSame('invalid_query', $result['error']['code']);
		$this->assertStringContainsString('only SELECT queries are allowed', $result['error']['message']);
	}

	public function testQueryRejectsTableOutsidePresetScope(): void {
		$service = $this->createMock(IQueryService::class);
		$service->method('listTables')->willReturn([$this->makeUserTable(), $this->makeCourseTable()]);
		$service->expects($this->never())->method('executeQuery');

		$tool = new DataHawkAgentTool($service, $this->makeResolver());
		$tool->setConfig(['tableFilter' => ['user_report_rows']]);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
				'table' => 'course_report_rows',
				'fields' => [[
					'element' => ['type' => 'fld', 'table' => 'course_report_rows', 'field' => 'course_id']
				]]
			]],
			$this->makeContextStub()
		);

		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('not available in this reporting preset', $result['error']['message']);
	}

	public function testQueryRejectsUnknownField(): void {
		$service = $this->createMock(IQueryService::class);
		$service->method('listTables')->willReturn([$this->makeUserTable()]);
		$service->expects($this->never())->method('executeQuery');

		$tool = new DataHawkAgentTool($service, $this->makeResolver());
		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
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
		$service->method('listTables')->willReturn([$this->makeUserTable()]);
		$service->expects($this->never())->method('executeQuery');

		$tool = new DataHawkAgentTool($service, $this->makeResolver());
		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
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
		$service->method('listTables')->willReturn([$this->makeUserTable()]);
		$service->expects($this->never())->method('executeQuery');

		$tool = new DataHawkAgentTool($service, $this->makeResolver());
		$tool->setConfig(['maxLimit' => 10]);

		$result = $tool->callTool(
			'execute_datahawk_query',
			['query' => [
				'type' => 'select',
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
