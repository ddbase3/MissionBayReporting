<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IClassMap;
use MissionBayReporting\MissionBay\VizionCanvasAgentTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Api\IReportExporter;
use ResourceFoundation\Dto\QueryResult;

require_once __DIR__ . '/VizionCanvasAgentToolTestDouble3.php';
require_once __DIR__ . '/VizionCanvasPushFailingStream.php';

#[CoversClass(VizionCanvasAgentTool::class)]
final class VizionCanvasAgentToolAdditionalTest extends TestCase {

	private function context(object $stream): IAgentContext {
		$context = $this->createStub(IAgentContext::class);
		$context->method('getVar')->willReturnCallback(static fn(string $name): mixed => $name === 'eventstream' ? $stream : null);
		return $context;
	}

	private function makeTool(IQueryService $queryService, ?IReportExporter $exporter = null): VizionCanvasAgentTool {
		$classMap = $this->createStub(IClassMap::class);
		if($exporter !== null) {
			$classMap->method('getInstanceByInterfaceName')->willReturn($exporter);
		}
		return new VizionCanvasAgentTool($queryService, $classMap);
	}

	private function validArguments(array $extra = []): array {
		return array_merge([
			'config' => ['type' => 'barchart', 'query' => ['type' => 'select', 'table' => 'courses']]
		], $extra);
	}

	public function testQueryFailureIsReturnedWithoutSendingCanvasEvents(): void {
		$events = [];
		$stream = new VizionCanvasAgentToolTestDouble3($events);
		$queryService = $this->createStub(IQueryService::class);
		$queryService->method('executeQuery')->willThrowException(new \RuntimeException('Query timed out'));

		$result = $this->makeTool($queryService)->callTool(
			'vizion_report_canvas',
			$this->validArguments(),
			$this->context($stream)
		);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('Query timed out', $result['error']);
		$this->assertSame([], $events);
	}

	public function testExporterFailureDoesNotEmitPartialCanvasContent(): void {
		$events = [];
		$stream = new VizionCanvasAgentToolTestDouble3($events);
		$queryService = $this->createStub(IQueryService::class);
		$queryService->method('executeQuery')->willReturn(new QueryResult([], []));
		$exporter = $this->createStub(IReportExporter::class);
		$exporter->method('setResult')->willReturnSelf();
		$exporter->method('toString')->willThrowException(new \RuntimeException('Renderer crashed'));

		$result = $this->makeTool($queryService, $exporter)->callTool(
			'vizion_report_canvas',
			$this->validArguments(),
			$this->context($stream)
		);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('Renderer crashed', $result['error']);
		$this->assertSame([], $events);
	}

	public function testCanvasPushFailureProducesExplicitError(): void {
		$queryService = $this->createStub(IQueryService::class);
		$queryService->method('executeQuery')->willReturn(new QueryResult([], []));
		$exporter = $this->createStub(IReportExporter::class);
		$exporter->method('setResult')->willReturnSelf();
		$exporter->method('toString')->willReturn('<p>report</p>');

		$result = $this->makeTool($queryService, $exporter)->callTool(
			'vizion_report_canvas',
			$this->validArguments(),
			$this->context(new VizionCanvasPushFailingStream())
		);
		$this->assertFalse($result['ok']);
		$this->assertStringContainsString('Canvas stream rejected the event', $result['error']);
	}

	public function testBarchartAndInvalidOpenFlagUseExistingCanvasEvents(): void {
		$events = [];
		$stream = new VizionCanvasAgentToolTestDouble3($events);
		$queryService = $this->createMock(IQueryService::class);
		$queryService->expects($this->once())->method('executeQuery')
			->willReturn(new QueryResult([['name' => 'count']], [['count' => 2]], debugSql: 'SELECT COUNT(*)'));
		$exporter = $this->createStub(IReportExporter::class);
		$exporter->method('setResult')->willReturnSelf();
		$exporter->method('toString')->willReturn('<div>2</div>');
		$classMap = $this->createMock(IClassMap::class);
		$classMap->expects($this->once())->method('getInstanceByInterfaceName')
			->with(IReportExporter::class, 'barchartreportexporter')->willReturn($exporter);

		$result = (new VizionCanvasAgentTool($queryService, $classMap))->callTool(
			'vizion_report_canvas',
			$this->validArguments(['open' => 'not-a-boolean']),
			$this->context($stream)
		);
		$this->assertTrue($result['ok']);
		$this->assertSame('SELECT COUNT(*)', $result['sql']);
		$this->assertCount(2, $events);
		$this->assertSame('canvas.open', $events[0][0]);
		$this->assertSame('canvas.render', $events[1][0]);
		$this->assertSame('<div><div>2</div></div>', $events[1][1]['blocks'][0]['html']);
	}
}
