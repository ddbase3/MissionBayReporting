<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBayReporting\MissionBay\VizionReportAgentTool;
use PHPUnit\Framework\TestCase;
use Vizion\Api\IReportDataService;

final class VizionReportAgentToolTest extends TestCase {

	public function testUsesStableFunctionNamesAndReadOnlyHints(): void {
		$tool = $this->makeTool($this->createStub(IReportDataService::class));
		$definitions = [];

		foreach($tool->getToolDefinitions() as $definition) {
			$name = (string)($definition['function']['name'] ?? '');
			$definitions[$name] = $definition;
		}

		$this->assertSame([
			'describe_vizion_reports',
			'execute_vizion_report',
			'search_vizion_tree'
		], array_keys($definitions));

		foreach($definitions as $definition) {
			$this->assertTrue($definition['readOnlyHint']);
			$this->assertFalse($definition['mutation']);
			$this->assertFalse($definition['requiresApproval']);
			$this->assertSame('reporting', $definition['category']);
		}
	}

	public function testExecuteQualifiesReportAndRemovesInternalRowMetadata(): void {
		$dataService = $this->createMock(IReportDataService::class);
		$dataService->expects($this->once())
			->method('describeReport')
			->with('ilias:course_report_rows')
			->willReturn([
				'fields' => [
					['key' => 'course_title', 'sortable' => true]
				],
				'filters' => [],
				'treeFilters' => [
					['key' => 'category', 'label' => 'Categories']
				]
			]);
		$dataService->expects($this->once())
			->method('executeReport')
			->with('ilias:course_report_rows', $this->callback(function(array $payload): bool {
				return ($payload['treeFilters']['category'] ?? null) === '4621'
					&& ($payload['pageSize'] ?? null) === 10;
			}))
			->willReturn([
				'data' => [[
					'course_title' => 'Course A',
					'__row_key' => 'internal'
				]],
				'total' => 1
			]);

		$tool = $this->makeTool($dataService);
		$result = $tool->callTool('execute_vizion_report', [
			'report' => 'course_report_rows',
			'treeFilters' => ['category' => '4621'],
			'pageSize' => 10
		], $this->createStub(IAgentContext::class));

		$this->assertTrue($result['ok']);
		$this->assertSame('Course A', $result['result']['data'][0]['course_title'] ?? null);
		$this->assertArrayNotHasKey('__row_key', $result['result']['data'][0]);
	}

	public function testRejectsReportOutsideConfiguredReportingScope(): void {
		$tool = $this->makeTool($this->createStub(IReportDataService::class));
		$result = $tool->callTool('execute_vizion_report', [
			'report' => 'other:course_report_rows'
		], $this->createStub(IAgentContext::class));

		$this->assertFalse($result['ok']);
		$this->assertSame('execute_failed', $result['error']['code'] ?? null);
	}

	private function makeTool(IReportDataService $dataService): VizionReportAgentTool {
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(fn($value) => $value);

		$tool = new VizionReportAgentTool($dataService, $resolver);
		$tool->setConfig([
			'reportingscope' => 'ilias'
		]);

		return $tool;
	}
}
