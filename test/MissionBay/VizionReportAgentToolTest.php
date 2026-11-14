<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of MissionBayReporting for BASE3 Framework.
 *
 * MissionBayReporting extends BASE3 with agent-oriented reporting tools.
 * It integrates MissionBay conversations with DataHawk and Vizion.
 * The tools provide query execution and visual report rendering.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/missionbayreporting
 * https://github.com/ddbase3/MissionBayReporting
 **********************************************************************/

namespace Base3\MissionBayReporting\Test\MissionBay;

use Base3\AssistantFoundation\Api\IAgentContext;
use Base3\MissionBay\Api\IAgentConfigValueResolver;
use Base3\MissionBayReporting\MissionBay\VizionReportAgentTool;
use PHPUnit\Framework\TestCase;
use Base3\Vizion\Api\IReportDataService;

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
