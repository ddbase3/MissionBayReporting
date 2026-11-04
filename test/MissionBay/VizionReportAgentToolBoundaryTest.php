<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBayReporting\MissionBay\VizionReportAgentTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vizion\Api\IReportDataService;

#[CoversClass(VizionReportAgentTool::class)]
final class VizionReportAgentToolBoundaryTest extends TestCase {

	private function makeTool(IReportDataService $service, array $config = ['reportingscope' => 'ilias']): VizionReportAgentTool {
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(static fn(mixed $value): mixed => $value);
		$tool = new VizionReportAgentTool($service, $resolver);
		$tool->setConfig($config);
		return $tool;
	}

	private function call(VizionReportAgentTool $tool, string $name, array $arguments): array {
		return $tool->callTool($name, $arguments, $this->createStub(IAgentContext::class));
	}

	private function reportDescription(): array {
		return [
			'fields' => [
				['key' => 'name', 'sortable' => true],
				['key' => 'count', 'sortable' => false],
				['key' => ''],
				'ignored'
			],
			'filters' => [['key' => 'status'], ['key' => ''], false],
			'treeFilters' => [['key' => 'category'], ['key' => ''], null]
		];
	}

	public function testSchemaNameDescriptionAndOutputShapesAreStable(): void {
		$tool = $this->makeTool($this->createStub(IReportDataService::class));
		$this->assertSame('vizionreportagenttool', VizionReportAgentTool::getName());
		$this->assertStringContainsString('read-only', $tool->getDescription());
		$schema = $tool->getSchema();
		$this->assertSame(['reportingscope'], $schema['required']);
		$this->assertFalse($schema['additionalProperties']);
		$this->assertSame(30, $schema['properties']['describeLimit']['maximum']);

		$names = ['describe_vizion_reports', 'execute_vizion_report', 'search_vizion_tree'];
		$this->assertSame($names, array_keys($tool->getOutputSchemas()));
		foreach($tool->getOutputSchemas() as $output) {
			$this->assertSame(['ok', 'operation'], $output['required']);
			$this->assertSame('boolean', $output['properties']['ok']['type']);
		}
	}

	public function testConfiguredScopeIsMandatoryAndPriorityIsBounded(): void {
		$service = $this->createStub(IReportDataService::class);
		foreach([[], ['reportingscope' => ''], ['reportingscope' => " \n "]] as $config) {
			try {
				$this->makeTool($service, $config);
				$this->fail('A missing reporting scope must be rejected.');
			} catch(\InvalidArgumentException $exception) {
				$this->assertStringContainsString('requires reportingscope', $exception->getMessage());
			}
		}

		$tool = $this->makeTool($service, [
			'reportingscope' => ' ilias ',
			'priority' => 2000,
			'describeLimit' => 900
		]);
		foreach($tool->getToolDefinitions() as $definition) {
			$this->assertSame(1000, $definition['priority']);
		}

		$tool->setConfig(['reportingscope' => 'ilias', 'priority' => -11, 'describeLimit' => -3]);
		$this->assertSame(0, $tool->getToolDefinitions()[0]['priority']);
	}

	public function testDescribeCatalogAndSearchRespectConfiguredAndClampedLimits(): void {
		foreach([
			[['search' => '  '], 'catalog', '', 4],
			[['search' => ' courses '], 'search', 'courses', 4],
			[['limit' => 0], 'catalog', '', 1],
			[['limit' => 300], 'catalog', '', 30],
			[['limit' => 'not-a-number'], 'catalog', '', 4]
		] as [$arguments, $mode, $search, $limit]) {
			$service = $this->createMock(IReportDataService::class);
			$service->expects($this->once())->method('listReports')
				->with('ilias', $search, $limit)
				->willReturn([['id' => 'report-a']]);

			$tool = $this->makeTool($service, ['reportingscope' => 'ilias', 'describeLimit' => 4]);
			$result = $this->call($tool, 'describe_vizion_reports', $arguments);
			$this->assertTrue($result['ok']);
			$this->assertSame($mode, $result['mode']);
			$this->assertSame([['id' => 'report-a']], $result['catalog']);
		}
	}

	public function testDescribeExactReportAcceptsLocalAndScopedIdentifiers(): void {
		foreach([' report-a ', 'ilias:report-a'] as $report) {
			$service = $this->createMock(IReportDataService::class);
			$service->expects($this->once())->method('describeReport')
				->with('ilias:report-a')->willReturn(['name' => 'Report A']);
			$service->expects($this->never())->method('listReports');
			$result = $this->call($this->makeTool($service), 'describe_vizion_reports', ['report' => $report]);
			$this->assertSame('report', $result['mode']);
			$this->assertSame(['name' => 'Report A'], $result['report']);
		}
	}

	public function testDescribeAndTreeFailuresAreReturnedAsStructuredErrors(): void {
		$service = $this->createStub(IReportDataService::class);
		$service->method('listReports')->willThrowException(new \RuntimeException('Catalog offline'));
		$result = $this->call($this->makeTool($service), 'describe_vizion_reports', []);
		$this->assertFalse($result['ok']);
		$this->assertSame('describe_failed', $result['error']['code']);
		$this->assertSame('Catalog offline', $result['error']['message']);

		$service = $this->createStub(IReportDataService::class);
		$service->method('searchTree')->willThrowException(new \RuntimeException('Tree unavailable'));
		$result = $this->call($this->makeTool($service), 'search_vizion_tree', [
			'report' => 'courses', 'treeFilter' => 'category'
		]);
		$this->assertFalse($result['ok']);
		$this->assertSame('tree_search_failed', $result['error']['code']);
		$this->assertSame('Tree unavailable', $result['error']['message']);
	}

	public function testTreeSearchValidatesRequiredNamesAndBoundsLimit(): void {
		foreach([
			[['report' => ''], 'Missing report or treeFilter.'],
			[['report' => 'courses'], 'Missing report or treeFilter.'],
			[['treeFilter' => 'category'], 'Missing report or treeFilter.'],
			[['report' => 'other:courses', 'treeFilter' => 'category'], 'outside the configured reporting scope'],
			[['report' => 'ilias:', 'treeFilter' => 'category'], 'outside the configured reporting scope']
		] as [$arguments, $error]) {
			$result = $this->call($this->makeTool($this->createStub(IReportDataService::class)), 'search_vizion_tree', $arguments);
			$this->assertFalse($result['ok']);
			$this->assertStringContainsString($error, $result['error']['message']);
		}

		foreach([
			[[], 20],
			[['limit' => 'invalid'], 20],
			[['limit' => -4], 1],
			[['limit' => 999], 100],
			[['limit' => '7'], 7]
		] as [$arguments, $limit]) {
			$service = $this->createMock(IReportDataService::class);
			$service->expects($this->once())->method('searchTree')
				->with('ilias:courses', 'category', 'needle', $limit)
				->willReturn([['id' => 'n1']]);
			$result = $this->call($this->makeTool($service), 'search_vizion_tree', array_merge([
				'report' => ' courses ', 'treeFilter' => ' category ', 'search' => ' needle '
			], $arguments));
			$this->assertTrue($result['ok']);
			$this->assertSame([['id' => 'n1']], $result['result']);
		}
	}

	public function testExecuteRejectsMissingReportAndForeignScopeBeforeCallingBackend(): void {
		$service = $this->createMock(IReportDataService::class);
		$service->expects($this->never())->method('describeReport');
		$service->expects($this->never())->method('executeReport');
		$tool = $this->makeTool($service);
		foreach([[], ['report' => ' '], ['report' => 'other:courses'], ['report' => 'ilias:']] as $arguments) {
			$result = $this->call($tool, 'execute_vizion_report', $arguments);
			$this->assertFalse($result['ok']);
			$this->assertSame('execute_failed', $result['error']['code']);
		}
	}

	public function testExecuteRejectsUnknownOrMalformedReportFieldFilterTreeAndSort(): void {
		$cases = [
			[['fields' => 'name'], 'fields must be an array'],
			[['fields' => ['unknown']], 'Unknown report field'],
			[['fields' => [['key' => 'name']]], 'Unknown report field'],
			[['filters' => 'status'], 'filter values must be an object'],
			[['filters' => ['private' => 'x']], 'Unknown filter: private'],
			[['treeFilters' => 'category'], 'tree filter values must be an object'],
			[['treeFilters' => ['unknown' => '42']], 'Unknown tree filter: unknown'],
			[['sort' => 'name'], 'sort must be an array'],
			[['sort' => ['name']], 'sort entries must be objects'],
			[['sort' => [['key' => 'count']]], 'Unknown or non-sortable report field'],
			[['sort' => [['key' => 'missing']]], 'Unknown or non-sortable report field']
		];

		foreach($cases as [$invalid, $error]) {
			$service = $this->createMock(IReportDataService::class);
			$service->method('describeReport')->willReturn($this->reportDescription());
			$service->expects($this->never())->method('executeReport');
			$result = $this->call($this->makeTool($service), 'execute_vizion_report', array_merge([
				'report' => 'courses'
			], $invalid));
			$this->assertFalse($result['ok']);
			$this->assertSame('execute_failed', $result['error']['code']);
			$this->assertStringContainsString($error, $result['error']['message']);
		}
	}

	public function testExecuteOnlyPassesDocumentedArgumentsAndStripsAllInternalRowKeys(): void {
		$payload = [
			'search' => 'courses',
			'filters' => ['status' => 1],
			'treeFilters' => ['category' => '42'],
			'sort' => [['key' => 'name', 'dir' => 'asc']],
			'fields' => ['name'],
			'page' => 2,
			'pageSize' => 5
		];
		$service = $this->createMock(IReportDataService::class);
		$service->method('describeReport')->willReturn($this->reportDescription());
		$service->expects($this->once())->method('executeReport')
			->with('ilias:courses', $payload)
			->willReturn([
				'data' => [
					['name' => 'Visible', '__key' => 123, '__internal' => ['secret' => 1]],
					false,
					['name' => 'Second', 'nested' => ['__not_top_level' => true]]
				],
				'total' => 2
			]);
		$result = $this->call($this->makeTool($service), 'execute_vizion_report', array_merge(
			['report' => 'courses', 'unexpected' => 'not passed'],
			$payload
		));
		$this->assertTrue($result['ok']);
		$this->assertSame('execute_vizion_report', $result['operation']);
		$this->assertSame(2, $result['result']['total']);
		$this->assertSame([
			['name' => 'Visible'],
			['name' => 'Second', 'nested' => ['__not_top_level' => true]]
		], $result['result']['data']);
	}

	public function testExecuteTreatsInvalidRowListAsEmptyAndReportsBackendExceptions(): void {
		$service = $this->createStub(IReportDataService::class);
		$service->method('describeReport')->willReturn([]);
		$service->method('executeReport')->willReturn(['data' => 'invalid', 'total' => 0]);
		$result = $this->call($this->makeTool($service), 'execute_vizion_report', ['report' => 'courses']);
		$this->assertTrue($result['ok']);
		$this->assertSame([], $result['result']['data']);

		$service = $this->createStub(IReportDataService::class);
		$service->method('describeReport')->willReturn([]);
		$service->method('executeReport')->willThrowException(new \RuntimeException('Report backend unavailable'));
		$result = $this->call($this->makeTool($service), 'execute_vizion_report', ['report' => 'courses']);
		$this->assertFalse($result['ok']);
		$this->assertSame('execute_failed', $result['error']['code']);
		$this->assertSame('Report backend unavailable', $result['error']['message']);
	}

	public function testUnsupportedToolNameIsRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported tool: missing_tool');
		$this->call($this->makeTool($this->createStub(IReportDataService::class)), 'missing_tool', []);
	}
}
