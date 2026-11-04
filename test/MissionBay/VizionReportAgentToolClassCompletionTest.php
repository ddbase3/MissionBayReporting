<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBayReporting\MissionBay\VizionReportAgentTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Vizion\Api\IReportDataService;

#[CoversClass(VizionReportAgentTool::class)]
final class VizionReportAgentToolClassCompletionTest extends TestCase {

	private function tool(IReportDataService $service, array $config = ['reportingscope' => 'ilias']): VizionReportAgentTool {
		$resolver = $this->createStub(IAgentConfigValueResolver::class);
		$resolver->method('resolveValue')->willReturnCallback(static fn(mixed $value): mixed => $value);
		$tool = new VizionReportAgentTool($service, $resolver);
		$tool->setConfig($config);
		return $tool;
	}

	private function call(VizionReportAgentTool $tool, string $name, array $arguments): array {
		return $tool->callTool($name, $arguments, $this->createStub(IAgentContext::class));
	}

	private function description(): array {
		return [
			'fields' => [['key' => 'name', 'sortable' => true], ['key' => 'total', 'sortable' => false]],
			'filters' => [['key' => 'status']],
			'treeFilters' => [['key' => 'category']]
		];
	}

	public function testEmptySortAndProjectionAreAcceptedWithConfiguredFilters(): void {
		$captured = null;
		$service = $this->createStub(IReportDataService::class);
		$service->method('describeReport')->willReturn($this->description());
		$service->method('executeReport')->willReturnCallback(static function(string $report, array $payload) use (&$captured): array {
			$captured = [$report, $payload];
			return ['data' => [['name' => 'A', '__meta' => 1]], 'total' => 1];
		});
		$arguments = [
			'report' => 'courses',
			'fields' => [],
			'filters' => [],
			'treeFilters' => ['category' => '42'],
			'sort' => [],
			'page' => 1
		];
		$result = $this->call($this->tool($service), 'execute_vizion_report', $arguments);
		$this->assertTrue($result['ok']);
		$this->assertSame('ilias:courses', $captured[0]);
		$this->assertSame([], $captured[1]['sort']);
		$this->assertSame([['name' => 'A']], $result['result']['data']);
	}

	public function testInvalidProjectionAndSortKeysAreRejectedBeforeExecution(): void {
		$service = $this->createMock(IReportDataService::class);
		$service->method('describeReport')->willReturn($this->description());
		$service->expects($this->never())->method('executeReport');
		$tool = $this->tool($service);
		foreach ([
			[['fields' => [null]], 'Unknown report field'],
			[['fields' => ['name', 'missing']], 'Unknown report field'],
			[['sort' => [['key' => '']]], 'Unknown or non-sortable'],
			[['sort' => [['key' => 'total']]], 'Unknown or non-sortable'],
			[['sort' => [null]], 'sort entries must be objects'],
			[['filters' => ['wrong' => true]], 'Unknown filter'],
			[['treeFilters' => ['wrong' => true]], 'Unknown tree filter']
		] as [$arguments, $expected]) {
			$result = $this->call($tool, 'execute_vizion_report', array_merge(['report' => 'courses'], $arguments));
			$this->assertFalse($result['ok']);
			$this->assertStringContainsString($expected, $result['error']['message']);
		}
	}

	public function testSchemaDescriptionWithMissingOptionalCollectionsAllowsUnfilteredReport(): void {
		$service = $this->createStub(IReportDataService::class);
		$service->method('describeReport')->willReturn([]);
		$service->method('executeReport')->willReturn(['data' => [null, ['name' => 'A'], 'not a row'], 'total' => 1]);
		$result = $this->call($this->tool($service), 'execute_vizion_report', [
			'report' => 'courses', 'filters' => [], 'treeFilters' => [], 'sort' => []
		]);
		$this->assertTrue($result['ok']);
		$this->assertSame([['name' => 'A']], $result['result']['data']);
	}

	public function testNonScalarScopeCannotCreateAnUnscopedReportTool(): void {
		$service = $this->createStub(IReportDataService::class);
		foreach ([['reportingscope' => []], ['reportingscope' => ['ilias']], ['reportingscope' => null]] as $config) {
			try {
				$this->tool($service, $config);
				$this->fail('A non-scalar reporting scope must be rejected.');
			} catch (\InvalidArgumentException $exception) {
				$this->assertStringContainsString('requires reportingscope', $exception->getMessage());
			}
		}
	}
}
