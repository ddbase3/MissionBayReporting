<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use PHPUnit\Framework\TestCase;
use MissionBayReporting\MissionBay\DataHawkAgentTool;
use MissionBay\Api\IAgentContext;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Dto\QueryResult;

/**
 * @covers \MissionBayReporting\MissionBay\DataHawkAgentTool
 */
class DataHawkAgentToolTest extends TestCase {

	private function makeContextStub(): IAgentContext {
		// DataHawkAgentTool does not use $context, so a plain stub is sufficient.
		return $this->createStub(IAgentContext::class);
	}

	public function testGetName(): void {
		$this->assertSame('datahawkagenttool', DataHawkAgentTool::getName());
	}

	public function testGetDescription(): void {
		$qs = $this->createStub(IQueryService::class);
		$tool = new DataHawkAgentTool($qs);

		$this->assertSame('Executes structured DataHawk queries provided as JSON.', $tool->getDescription());
	}

	public function testGetToolDefinitionsContainsExecuteFunction(): void {
		$qs = $this->createStub(IQueryService::class);
		$tool = new DataHawkAgentTool($qs);

		$defs = $tool->getToolDefinitions();

		$this->assertIsArray($defs);
		$this->assertCount(1, $defs);

		$def = $defs[0];

		$this->assertSame('function', $def['type']);
		$this->assertSame('Database Report', $def['label']);
		$this->assertSame('data', $def['category']);
		$this->assertSame(['datahawk', 'query', 'structured_query', 'analytics'], $def['tags']);
		$this->assertSame(50, $def['priority']);

		$this->assertIsArray($def['function']);
		$this->assertSame('execute_datahawk_query', $def['function']['name']);

		$this->assertIsArray($def['function']['parameters']);
		$this->assertSame('object', $def['function']['parameters']['type']);
		$this->assertSame(['query'], $def['function']['parameters']['required']);
		$this->assertArrayHasKey('query', $def['function']['parameters']['properties']);
		$this->assertSame('object', $def['function']['parameters']['properties']['query']['type']);
	}

	public function testCallToolThrowsForUnsupportedToolName(): void {
		$qs = $this->createStub(IQueryService::class);
		$tool = new DataHawkAgentTool($qs);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported tool: no_such_tool');

		$tool->callTool('no_such_tool', [], $this->makeContextStub());
	}

	public function testCallToolReturnsErrorWhenQueryParamIsMissing(): void {
		$qs = $this->createStub(IQueryService::class);
		$tool = new DataHawkAgentTool($qs);

		$out = $tool->callTool('execute_datahawk_query', [], $this->makeContextStub());

		$this->assertIsArray($out);
		$this->assertSame(['error' => 'Missing parameter: query'], $out);
	}

	public function testCallToolReturnsErrorWhenQueryIsEmptyString(): void {
		$qs = $this->createStub(IQueryService::class);
		$tool = new DataHawkAgentTool($qs);

		$out = $tool->callTool(
			'execute_datahawk_query',
			['query' => '   '],
			$this->makeContextStub()
		);

		$this->assertIsArray($out);
		$this->assertSame(['error' => 'Empty query string.'], $out);
	}

	public function testCallToolReturnsErrorWhenQueryJsonIsInvalid(): void {
		$qs = $this->createStub(IQueryService::class);
		$tool = new DataHawkAgentTool($qs);

		$out = $tool->callTool(
			'execute_datahawk_query',
			['query' => '{invalid-json'],
			$this->makeContextStub()
		);

		$this->assertIsArray($out);
		$this->assertSame(['error' => 'Invalid JSON in query parameter.'], $out);
	}

	public function testCallToolReturnsErrorWhenQueryHasInvalidType(): void {
		$qs = $this->createStub(IQueryService::class);
		$tool = new DataHawkAgentTool($qs);

		$out = $tool->callTool(
			'execute_datahawk_query',
			['query' => 123],
			$this->makeContextStub()
		);

		$this->assertIsArray($out);
		$this->assertSame(['error' => 'Invalid type for "query" parameter. Expected JSON string or object.'], $out);
	}

	public function testCallToolExecutesQueryWhenQueryIsArray(): void {
		$query = [
			'select' => [
				['type' => 'fld', 'table' => 't', 'field' => 'id']
			]
		];

		$result = new QueryResult(
			columns: [
				[
					'name' => 'id',
					'type' => 'int',
					'field' => 'id',
					'alias' => null,
					'table' => 't',
					'sensitive' => false
				]
			],
			rows: [
				['id' => 1],
				['id' => 2]
			]
		);

		$qs = $this->createMock(IQueryService::class);
		$qs->expects($this->once())
			->method('executeQuery')
			->with($query)
			->willReturn($result);

		$tool = new DataHawkAgentTool($qs);

		$out = $tool->callTool(
			'execute_datahawk_query',
			['query' => $query],
			$this->makeContextStub()
		);

		$this->assertIsArray($out);
		$this->assertSame($result->columns, $out['columns']);
		$this->assertSame($result->rows, $out['rows']);
		$this->assertSame(2, $out['count']);
		$this->assertSame($query, $out['query_received']);
	}

	public function testCallToolExecutesQueryWhenQueryIsJsonString(): void {
		$query = [
			'select' => [
				['type' => 'fld', 'table' => 't', 'field' => 'name']
			],
			'where' => [
				'type' => 'op',
				'operator' => '=',
				'params' => [
					['type' => 'fld', 'table' => 't', 'field' => 'id'],
					1
				]
			]
		];

		$json = json_encode($query, JSON_UNESCAPED_SLASHES);

		$result = new QueryResult(
			columns: [
				[
					'name' => 'name',
					'type' => 'string',
					'field' => 'name',
					'alias' => null,
					'table' => 't',
					'sensitive' => false
				]
			],
			rows: [
				['name' => 'Alice']
			]
		);

		$qs = $this->createMock(IQueryService::class);
		$qs->expects($this->once())
			->method('executeQuery')
			->with($query)
			->willReturn($result);

		$tool = new DataHawkAgentTool($qs);

		$out = $tool->callTool(
			'execute_datahawk_query',
			['query' => $json],
			$this->makeContextStub()
		);

		$this->assertIsArray($out);
		$this->assertSame($result->columns, $out['columns']);
		$this->assertSame($result->rows, $out['rows']);
		$this->assertSame(1, $out['count']);
		$this->assertSame($query, $out['query_received']);
	}

	public function testCallToolReturnsErrorWhenQueryExecutionThrows(): void {
		$query = ['select' => []];

		$qs = $this->createMock(IQueryService::class);
		$qs->expects($this->once())
			->method('executeQuery')
			->with($query)
			->willThrowException(new \RuntimeException('DB down'));

		$tool = new DataHawkAgentTool($qs);

		$out = $tool->callTool(
			'execute_datahawk_query',
			['query' => $query],
			$this->makeContextStub()
		);

		$this->assertIsArray($out);
		$this->assertSame(['error' => 'Query execution failed: DB down'], $out);
	}
}
