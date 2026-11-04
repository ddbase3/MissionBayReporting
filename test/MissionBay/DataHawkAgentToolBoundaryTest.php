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
final class DataHawkAgentToolBoundaryTest extends TestCase {

	private function makeTable(string $name = 'courses'): TableMetadata {
		return new TableMetadata(
			name: $name,
			label: 'Courses',
			description: 'Visible course reports',
			domain: 'learning',
			category: 'reporting',
			tags: ['public', 'courses'],
			fields: [
				new FieldMetadata(name: 'id', type: 'int', primaryKey: true),
				new FieldMetadata(name: 'title', type: 'string', description: 'Course title'),
				new FieldMetadata(name: 'secret', type: 'string', sensitive: true)
			]
		);
	}

	private function makeTool(IQueryService $service, array $tables = [], array $config = [], ?IScopedQuerySchemaProvider $schema = null): DataHawkAgentTool {
		$service->method('listTables')->willReturn($tables);
		if($schema === null) {
			$schema = $this->createStub(IScopedQuerySchemaProvider::class);
			$schema->method('getScopes')->willReturn(['learning']);
			$schema->method('getTableForScope')->willReturnCallback(
				static function(string $scope, string $name) use ($tables): ?TableMetadata {
					if($scope !== 'learning') {
						return null;
					}
					foreach($tables as $table) {
						if($table instanceof TableMetadata && $table->name === $name) {
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

	private function call(DataHawkAgentTool $tool, string $function, array $arguments): array {
		return $tool->callTool($function, $arguments, $this->createStub(IAgentContext::class));
	}

	private function baseQuery(): array {
		return [
			'type' => 'select',
			'table' => 'courses',
			'fields' => [['element' => ['type' => 'fld', 'table' => 'courses', 'field' => 'id']]]
		];
	}

	public function testDescriptionOutputSchemasAndConfiguredPriority(): void {
		$tool = $this->makeTool($this->createStub(IQueryService::class), [], ['priority' => 2000]);
		$this->assertStringContainsString('read-only structured data queries', $tool->getDescription());
		foreach($tool->getToolDefinitions() as $definition) {
			$this->assertSame(1000, $definition['priority']);
			$this->assertFalse($definition['mutation']);
		}
		$this->assertSame(['describe_reporting_data', 'execute_datahawk_query'], array_keys($tool->getOutputSchemas()));
		foreach($tool->getOutputSchemas() as $schema) {
			$this->assertSame('object', $schema['type']);
			$this->assertSame(['ok', 'operation'], $schema['required']);
		}
	}

	public function testHelpTopicsReturnDedicatedQueryGuidanceWithoutBackendExecution(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service);
		foreach(['fields', 'filters', 'aggregations', 'grouping', 'sorting', 'pagination', 'examples'] as $topic) {
			$result = $this->call($tool, 'describe_reporting_data', ['topic' => strtoupper($topic)]);
			$this->assertTrue($result['ok']);
			$this->assertSame('help', $result['mode']);
			$this->assertSame($topic, $result['topic']);
			$this->assertNotEmpty($result['help']['rules']);
		}
		$unknown = $this->call($tool, 'describe_reporting_data', ['topic' => 'erase']);
		$this->assertFalse($unknown['ok']);
		$this->assertSame('unknown_help_topic', $unknown['error']['code']);
		$this->assertCount(7, $unknown['available_topics']);
	}

	public function testHelpTopicsCannotBeMixedWithSchemaSearchOrExactTable(): void {
		$tool = $this->makeTool($this->createStub(IQueryService::class));
		foreach([['search' => 'course'], ['table' => 'courses']] as $extra) {
			$result = $this->call($tool, 'describe_reporting_data', array_merge(['topic' => 'fields'], $extra));
			$this->assertFalse($result['ok']);
			$this->assertSame('describe_failed', $result['error']['code']);
			$this->assertStringContainsString('topic cannot be combined', $result['error']['message']);
		}
	}

	public function testSchemaSearchRejectsMalformedLimitsAndClampsValidLimits(): void {
		$tables = [$this->makeTable(), $this->makeTable('courses_extra')];
		$tool = $this->makeTool($this->createStub(IQueryService::class), $tables, ['describeLimit' => 1]);
		foreach([1.5, '2.5', 'invalid', []] as $value) {
			$result = $this->call($tool, 'describe_reporting_data', ['limit' => $value]);
			$this->assertFalse($result['ok']);
			$this->assertStringContainsString('limit must be an integer', $result['error']['message']);
		}
		$result = $this->call($tool, 'describe_reporting_data', []);
		$this->assertTrue($result['ok']);
		$this->assertCount(1, $result['tables']);
		$this->assertTrue($result['truncated']);
		$this->assertSame(2, $result['available_table_count']);

		$minimum = $this->call($tool, 'describe_reporting_data', ['limit' => -2]);
		$this->assertTrue($minimum['ok']);
		$this->assertCount(1, $minimum['tables']);

		$result = $this->call($tool, 'describe_reporting_data', ['limit' => '20']);
		$this->assertTrue($result['ok']);
		$this->assertCount(2, $result['tables']);
		$this->assertFalse($result['truncated']);
	}

	public function testExactTableOnlyIncludesRelationsToCurrentlyAllowedTables(): void {
		$courses = $this->makeTable();
		$courses->joins = [
			new JoinMetadata('enrollments', [['courses.id', '=', 'enrollments.course_id']], 'INNER', ['default' => true]),
			new JoinMetadata('inaccessible', [['courses.id', '=', 'inaccessible.id']]),
			'not-a-relation'
		];
		$enrollments = $this->makeTable('enrollments');
		$tool = $this->makeTool($this->createStub(IQueryService::class), [$courses, $enrollments]);
		$result = $this->call($tool, 'describe_reporting_data', ['table' => 'courses']);
		$this->assertTrue($result['ok']);
		$this->assertCount(1, $result['table']['relations']);
		$this->assertSame('enrollments', $result['table']['relations'][0]['target_table']);
		$this->assertTrue($result['table']['relations'][0]['default']);
		$this->assertSame('title', $result['table']['fields'][1]['name']);
		$this->assertSame('courses', $result['query_example']['table']);
	}

	public function testDefaultQueryLimitCannotExceedConfiguredMaximum(): void {
		$query = $this->baseQuery();
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->once())->method('executeQuery')
			->with($this->callback(static fn(array $value): bool => $value['limit'] === 5 && $value['schema'] === 'learning'))
			->willReturn(new QueryResult([], [], null));
		$tool = $this->makeTool($service, [$this->makeTable()], ['defaultLimit' => 500, 'maxLimit' => 5]);
		$result = $this->call($tool, 'execute_datahawk_query', ['query' => $query]);
		$this->assertTrue($result['ok']);
		$this->assertSame(5, $result['limit']);
	}

	public function testInvalidQueryShapesNeverReachTheQueryService(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service, [$this->makeTable()], ['maxLimit' => 8]);
		$cases = [
			[[], 'Missing parameter: query'],
			[['query' => 'SELECT *'], 'structured object'],
			[['query' => ['type' => 'delete', 'table' => 'courses']], 'only SELECT'],
			[['query' => ['type' => 'select', 'table' => 'courses']], 'non-empty fields array'],
			[['query' => array_merge($this->baseQuery(), ['where' => []])], 'omit where'],
			[['query' => array_merge($this->baseQuery(), ['having' => []])], 'omit having'],
			[['query' => array_merge($this->baseQuery(), ['limit' => 0])], 'at least 1'],
			[['query' => array_merge($this->baseQuery(), ['limit' => 9])], 'configured maximum of 8'],
			[['query' => array_merge($this->baseQuery(), ['limit' => -1])], 'must not be negative'],
			[['query' => array_merge($this->baseQuery(), ['limit' => 1.2])], 'must be an integer'],
			[['query' => array_merge($this->baseQuery(), ['offset' => 'half'])], 'must be an integer'],
			[['query' => array_merge($this->baseQuery(), ['order_by' => 'id'])], 'must be an array'],
			[['query' => array_merge($this->baseQuery(), ['order_by' => [[]]])], 'requires an element'],
			[['query' => array_merge($this->baseQuery(), ['order_by' => [['element' => ['type' => 'fld', 'field' => 'id'], 'direction' => 'sideways']]])], 'invalid direction'],
			[['query' => array_merge($this->baseQuery(), ['union' => ['queries' => [$this->baseQuery()]]])], 'at least two SELECT queries'],
			[['query' => array_merge($this->baseQuery(), ['schema' => 'other namespace'])], 'invalid technical name'],
			[['query' => array_merge($this->baseQuery(), ['schema' => "learning\n"])], 'invalid technical name'],
			[['query' => array_merge($this->baseQuery(), ['provider' => "learning\n"])], 'invalid technical name']
		];
		foreach($cases as [$arguments, $expected]) {
			$result = $this->call($tool, 'execute_datahawk_query', $arguments);
			$this->assertFalse($result['ok']);
			$this->assertSame('invalid_query', $result['error']['code']);
			$this->assertStringContainsString($expected, $result['error']['message']);
		}
	}

	public function testInvalidNestedElementsAreRejectedBeforeExecution(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service, [$this->makeTable()]);
		$cases = [
			[['type' => 'alias', 'name' => 'id'], 'alias is not an element type'],
			[['type' => 'javascript'], 'unsupported reporting element type'],
			[['type' => 'fld'], 'requires a field name'],
			[['type' => 'fld', 'field' => 'secret_unknown'], 'field is not available'],
			[['type' => 'fn', 'function' => 'EVAL', 'params' => []], 'unsupported reporting function'],
			[['type' => 'fn', 'function' => 'COUNT'], 'requires a params array'],
			[['type' => 'op', 'operator' => 'EXEC', 'params' => []], 'unsupported reporting operator'],
			[['type' => 'op', 'operator' => 'AND'], 'requires a params array'],
			[['type' => 'subquery'], 'subquery requires a query object'],
			[['type' => 'case', 'cases' => []], 'CASE element requires a non-empty cases array']
		];
		foreach($cases as [$element, $expected]) {
			$query = $this->baseQuery();
			$query['fields'][] = ['element' => $element];
			$result = $this->call($tool, 'execute_datahawk_query', ['query' => $query]);
			$this->assertFalse($result['ok']);
			$this->assertSame('invalid_query', $result['error']['code']);
			$this->assertStringContainsString($expected, $result['error']['message']);
		}
	}

	public function testDuplicateSchemaScopeIsRejectedRatherThanGuessingOne(): void {
		$table = $this->makeTable();
		$schema = $this->createStub(IScopedQuerySchemaProvider::class);
		$schema->method('getScopes')->willReturn(['first', 'second']);
		$schema->method('getTableForScope')->willReturn($table);
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service, [$table], [], $schema);
		$result = $this->call($tool, 'execute_datahawk_query', ['query' => $this->baseQuery()]);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('multiple query schema scopes', $result['error']['message']);
	}

	public function testValidGroupedExpressionsAndPaginationReachTheQueryServiceUnmodified(): void {
		$count = ['type' => 'fn', 'function' => 'COUNT', 'params' => [1]];
		$query = $this->baseQuery();
		$query['fields'][] = ['element' => $count, 'alias' => 'count'];
		$query['where'] = ['type' => 'op', 'operator' => '=', 'params' => [
			['type' => 'fld', 'table' => 'courses', 'field' => 'id'], 1
		]];
		$query['group_by'] = [['type' => 'fld', 'table' => 'courses', 'field' => 'id']];
		$query['order_by'] = [['element' => $count, 'direction' => 'DESC']];
		$query['limit'] = '5';
		$query['offset'] = '2';

		$service = $this->createMock(IQueryService::class);
		$service->expects($this->once())->method('executeQuery')
			->with($this->callback(static function(array $actual): bool {
				return $actual['schema'] === 'learning'
					&& $actual['limit'] === 5
					&& $actual['offset'] === 2
					&& $actual['fields'][1]['element']['function'] === 'COUNT';
			}))
			->willReturn(new QueryResult([['name' => 'count']], [['count' => 4]], sensitive: false));
		$result = $this->call($this->makeTool($service, [$this->makeTable()]), 'execute_datahawk_query', ['query' => $query]);
		$this->assertTrue($result['ok']);
		$this->assertSame(1, $result['count']);
		$this->assertSame([['count' => 4]], $result['rows']);
		$this->assertSame(5, $result['limit']);
	}

	public function testValidUnionQueriesAreScopedAndOnlyRootReceivesDefaultLimit(): void {
		$query = [
			'type' => 'select',
			'table' => 'courses',
			'union' => ['queries' => [$this->baseQuery(), $this->baseQuery()]]
		];
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->once())->method('executeQuery')
			->with($this->callback(static function(array $actual): bool {
				return $actual['schema'] === 'learning'
					&& $actual['limit'] === 100
					&& count($actual['union']['queries']) === 2
					&& !array_key_exists('limit', $actual['union']['queries'][0])
					&& !array_key_exists('limit', $actual['union']['queries'][1])
					&& $actual['union']['queries'][0]['schema'] === 'learning'
					&& $actual['union']['queries'][1]['schema'] === 'learning';
			}))
			->willReturn(new QueryResult([], [['id' => 1], ['id' => 2]]));
		$result = $this->call($this->makeTool($service, [$this->makeTable()]), 'execute_datahawk_query', ['query' => $query]);
		$this->assertTrue($result['ok']);
		$this->assertSame(2, $result['count']);
	}

	public function testInvalidNestedSubqueryCannotBypassSelectOnlyValidation(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service, [$this->makeTable()]);
		$query = $this->baseQuery();
		$query['fields'][] = ['element' => [
			'type' => 'subquery',
			'query' => ['type' => 'delete', 'table' => 'courses']
		]];
		$result = $this->call($tool, 'execute_datahawk_query', ['query' => $query]);
		$this->assertFalse($result['ok']);
		$this->assertSame('invalid_query', $result['error']['code']);
		$this->assertStringContainsString('only SELECT queries', $result['error']['message']);
	}

	public function testBackendExceptionsAreReportedAsQueryFailure(): void {
		$service = $this->createStub(IQueryService::class);
		$service->method('executeQuery')->willThrowException(new \RuntimeException('Backend unavailable'));
		$result = $this->call($this->makeTool($service, [$this->makeTable()]), 'execute_datahawk_query', ['query' => $this->baseQuery()]);
		$this->assertFalse($result['ok']);
		$this->assertSame('query_failed', $result['error']['code']);
		$this->assertSame('Backend unavailable', $result['error']['message']);
	}

	public function testUnsupportedToolNameThrowsWithoutAccessingBackend(): void {
		$service = $this->createMock(IQueryService::class);
		$service->expects($this->never())->method('executeQuery');
		$tool = $this->makeTool($service);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported tool: not_report_tool');
		$this->call($tool, 'not_report_tool', []);
	}
}
