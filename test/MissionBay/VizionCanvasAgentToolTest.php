<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IClassMap;
use MissionBayReporting\MissionBay\VizionCanvasAgentTool;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Api\IReportExporter;
use ResourceFoundation\Dto\QueryResult;

/**
 * @covers \MissionBayReporting\MissionBay\VizionCanvasAgentTool
 */
class VizionCanvasAgentToolTest extends TestCase {

	private function makeContextWithStream(object $stream): IAgentContext {
		$context = $this->createStub(IAgentContext::class);
		$context->method('getVar')
			->willReturnCallback(function(string $key) use ($stream) {
				return $key === 'eventstream' ? $stream : null;
			});

		return $context;
	}

	private function makeContextWithoutStream(): IAgentContext {
		$context = $this->createStub(IAgentContext::class);
		$context->method('getVar')->willReturn(null);
		return $context;
	}

	private function makeResult(?string $sql = 'SELECT 1'): QueryResult {
		return new QueryResult(
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
				['id' => 1]
			],
			debugSql: $sql
		);
	}

	private function makeTool(?IQueryService $queryService = null, ?IClassMap $classMap = null): VizionCanvasAgentTool {
		return new VizionCanvasAgentTool(
			$queryService ?? $this->createStub(IQueryService::class),
			$classMap ?? $this->createStub(IClassMap::class)
		);
	}

	public function testGetName(): void {
		$this->assertSame('vizioncanvasagenttool', VizionCanvasAgentTool::getName());
	}

	public function testGetDescription(): void {
		$this->assertSame(
			'Renders a DataHawk-based report into the chatbot canvas using a single HTML block.',
			$this->makeTool()->getDescription()
		);
	}

	public function testGetToolDefinitionsContainsVizionReportCanvas(): void {
		$defs = $this->makeTool()->getToolDefinitions();

		$this->assertIsArray($defs);
		$this->assertCount(1, $defs);

		$def = $defs[0];

		$this->assertSame('function', $def['type']);
		$this->assertSame('Vizion Report Canvas', $def['label']);
		$this->assertSame('reporting', $def['category']);
		$this->assertSame(['vizion', 'report', 'canvas', 'datatable', 'chart'], $def['tags']);
		$this->assertSame(50, $def['priority']);
		$this->assertSame('vizion_report_canvas', $def['function']['name']);
		$this->assertSame(['config'], $def['function']['parameters']['required']);
		$this->assertArrayHasKey('config', $def['function']['parameters']['properties']);
	}

	public function testCallToolThrowsForUnsupportedToolName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported tool: nope');

		$this->makeTool()->callTool('nope', [], $this->createStub(IAgentContext::class));
	}

	public function testCallToolThrowsWhenConfigIsMissing(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing or invalid "config" argument.');

		$this->makeTool()->callTool('vizion_report_canvas', [], $this->makeContextWithoutStream());
	}

	public function testCallToolThrowsWhenConfigJsonIsInvalid(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Could not parse config JSON.');

		$this->makeTool()->callTool(
			'vizion_report_canvas',
			['config' => '{invalid-json'],
			$this->makeContextWithoutStream()
		);
	}

	public function testCallToolReturnsErrorWhenTypeOrQueryMissingInConfig(): void {
		$stream = new class {
			public function isDisconnected(): bool {
				return false;
			}

			public function push(string $event, array $payload): void {}
		};

		$out = $this->makeTool()->callTool(
			'vizion_report_canvas',
			['config' => ['type' => 'table']],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => false,
			'error' => 'Missing "type" or "query" in report config.'
		], $out);
	}

	public function testCallToolReturnsErrorWhenQueryIsInvalid(): void {
		$stream = new class {
			public function isDisconnected(): bool {
				return false;
			}

			public function push(string $event, array $payload): void {}
		};

		$out = $this->makeTool()->callTool(
			'vizion_report_canvas',
			['config' => ['type' => 'table', 'query' => 'invalid']],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => false,
			'error' => 'Invalid "query" in report config.'
		], $out);
	}

	public function testCallToolReturnsErrorWhenEventStreamMissingInContext(): void {
		$out = $this->makeTool()->callTool(
			'vizion_report_canvas',
			['config' => ['type' => 'table', 'query' => ['select' => []]]],
			$this->makeContextWithoutStream()
		);

		$this->assertSame([
			'ok' => false,
			'error' => 'Missing eventstream in context.'
		], $out);
	}

	public function testCallToolExecutesQueryRendersExporterAndPushesCanvas(): void {
		$events = [];
		$stream = new class($events) {
			public array $events;

			public function __construct(array &$events) {
				$this->events = &$events;
			}

			public function isDisconnected(): bool {
				return false;
			}

			public function push(string $event, array $payload): void {
				$this->events[] = [$event, $payload];
			}
		};

		$query = ['select' => [['type' => 'fld', 'table' => 't', 'field' => 'id']]];
		$result = $this->makeResult();

		$queryService = $this->createMock(IQueryService::class);
		$queryService->expects($this->once())
			->method('executeQuery')
			->with($query)
			->willReturn($result);

		$exporter = $this->createMock(IReportExporter::class);
		$exporter->expects($this->once())
			->method('setResult')
			->with($result)
			->willReturnSelf();
		$exporter->expects($this->once())
			->method('toString')
			->willReturn('<p>hello</p>');

		$classMap = $this->createMock(IClassMap::class);
		$classMap->expects($this->once())
			->method('getInstanceByInterfaceName')
			->with(IReportExporter::class, 'datatablereportexporter')
			->willReturn($exporter);

		$out = $this->makeTool($queryService, $classMap)->callTool(
			'vizion_report_canvas',
			[
				'canvas_id' => ' c1 ',
				'title' => ' My Report ',
				'open' => true,
				'config' => ['type' => 'datatable', 'query' => $query]
			],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => true,
			'canvas_id' => 'c1',
			'sql' => 'SELECT 1',
			'columns' => $result->columns
		], $out);
		$this->assertCount(2, $events);
		$this->assertSame('canvas.open', $events[0][0]);
		$this->assertSame('canvas.render', $events[1][0]);
		$this->assertSame('<div><p>hello</p></div>', $events[1][1]['blocks'][0]['html']);
		$this->assertFalse($events[1][1]['blocks'][0]['sanitize']);
	}

	public function testCallToolDoesNotPushWhenDisconnected(): void {
		$events = [];
		$stream = new class($events) {
			public array $events;

			public function __construct(array &$events) {
				$this->events = &$events;
			}

			public function isDisconnected(): bool {
				return true;
			}

			public function push(string $event, array $payload): void {
				$this->events[] = [$event, $payload];
			}
		};

		$result = $this->makeResult('SQL');
		$queryService = $this->createStub(IQueryService::class);
		$queryService->method('executeQuery')->willReturn($result);

		$exporter = $this->createStub(IReportExporter::class);
		$exporter->method('setResult')->willReturnSelf();
		$exporter->method('toString')->willReturn('x');

		$classMap = $this->createStub(IClassMap::class);
		$classMap->method('getInstanceByInterfaceName')->willReturn($exporter);

		$out = $this->makeTool($queryService, $classMap)->callTool(
			'vizion_report_canvas',
			['open' => true, 'config' => ['type' => 'table', 'query' => ['select' => []]]],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => true,
			'canvas_id' => 'main',
			'sql' => 'SQL',
			'columns' => $result->columns
		], $out);
		$this->assertCount(0, $events);
	}

	public function testCallToolReturnsErrorWhenExporterIsUnavailable(): void {
		$stream = new class {
			public function isDisconnected(): bool {
				return false;
			}

			public function push(string $event, array $payload): void {}
		};

		$queryService = $this->createStub(IQueryService::class);
		$queryService->method('executeQuery')->willReturn($this->makeResult());

		$out = $this->makeTool($queryService)->callTool(
			'vizion_report_canvas',
			['config' => ['type' => 'piechart', 'query' => ['select' => []]]],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => false,
			'error' => 'Report generation or canvas push failed: Report exporter is not available: piechartreportexporter'
		], $out);
	}

	public function testCallToolResolvesDefaultsAndOpenFlagParsing(): void {
		$events = [];
		$stream = new class($events) {
			public array $events;

			public function __construct(array &$events) {
				$this->events = &$events;
			}

			public function isDisconnected(): bool {
				return false;
			}

			public function push(string $event, array $payload): void {
				$this->events[] = [$event, $payload];
			}
		};

		$result = $this->makeResult('SQL');
		$queryService = $this->createStub(IQueryService::class);
		$queryService->method('executeQuery')->willReturn($result);

		$exporter = $this->createStub(IReportExporter::class);
		$exporter->method('setResult')->willReturnSelf();
		$exporter->method('toString')->willReturn('x');

		$classMap = $this->createStub(IClassMap::class);
		$classMap->method('getInstanceByInterfaceName')->willReturn($exporter);

		$configJson = json_encode(
			['type' => 'table', 'query' => ['select' => []]],
			JSON_UNESCAPED_SLASHES
		);

		$out = $this->makeTool($queryService, $classMap)->callTool(
			'vizion_report_canvas',
			[
				'canvas_id' => '   ',
				'title' => '',
				'open' => 'false',
				'config' => $configJson
			],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => true,
			'canvas_id' => 'main',
			'sql' => 'SQL',
			'columns' => $result->columns
		], $out);
		$this->assertCount(1, $events);
		$this->assertSame('canvas.render', $events[0][0]);
		$this->assertSame('main', $events[0][1]['id']);
		$this->assertSame('Report', $events[0][1]['title']);
	}
}
