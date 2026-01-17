<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use PHPUnit\Framework\TestCase;
use MissionBayReporting\MissionBay\VizionCanvasAgentTool;
use MissionBay\Api\IAgentContext;
use DataHawk\Api\IReportExporterFactory;
use DataHawk\Api\IReportExporter;
use ResourceFoundation\Dto\QueryResult;

/**
 * @covers \MissionBayReporting\MissionBay\VizionCanvasAgentTool
 */
class VizionCanvasAgentToolTest extends TestCase {

	private function makeContextWithStream(object $stream): IAgentContext {
		// Use a stub (not a mock) to avoid PHPUnit "no expectations" notices.
		$ctx = $this->createStub(IAgentContext::class);

		$ctx->method('getVar')
			->willReturnCallback(function (string $key) use ($stream) {
				if ($key === 'eventstream') {
					return $stream;
				}
				return null;
			});

		return $ctx;
	}

	private function makeContextWithoutStream(): IAgentContext {
		// Use a stub (not a mock) to avoid PHPUnit "no expectations" notices.
		$ctx = $this->createStub(IAgentContext::class);
		$ctx->method('getVar')->willReturn(null);
		return $ctx;
	}

	public function testGetName(): void {
		$this->assertSame('vizioncanvasagenttool', VizionCanvasAgentTool::getName());
	}

	public function testGetDescription(): void {
		$factory = $this->createStub(IReportExporterFactory::class);
		$tool = new VizionCanvasAgentTool($factory);

		$this->assertSame(
			'Renders a DataHawk-based report into the chatbot canvas using a single HTML block.',
			$tool->getDescription()
		);
	}

	public function testGetToolDefinitionsContainsVizionReportCanvas(): void {
		$factory = $this->createStub(IReportExporterFactory::class);
		$tool = new VizionCanvasAgentTool($factory);

		$defs = $tool->getToolDefinitions();

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
		$factory = $this->createStub(IReportExporterFactory::class);
		$tool = new VizionCanvasAgentTool($factory);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported tool: nope');

		$tool->callTool('nope', [], $this->createStub(IAgentContext::class));
	}

	public function testCallToolThrowsWhenConfigIsMissing(): void {
		$factory = $this->createStub(IReportExporterFactory::class);
		$tool = new VizionCanvasAgentTool($factory);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Missing or invalid "config" argument.');

		$tool->callTool('vizion_report_canvas', [], $this->makeContextWithoutStream());
	}

	public function testCallToolThrowsWhenConfigJsonIsInvalid(): void {
		$factory = $this->createStub(IReportExporterFactory::class);
		$tool = new VizionCanvasAgentTool($factory);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Could not parse config JSON.');

		$tool->callTool(
			'vizion_report_canvas',
			['config' => '{invalid-json'],
			$this->makeContextWithoutStream()
		);
	}

	public function testCallToolReturnsErrorWhenTypeOrQueryMissingInConfig(): void {
		$factory = $this->createStub(IReportExporterFactory::class);
		$tool = new VizionCanvasAgentTool($factory);

		$stream = new class {
			public function isDisconnected(): bool {
				return false;
			}

			public function push(string $event, array $payload): void {
				// no-op
			}
		};

		$out = $tool->callTool(
			'vizion_report_canvas',
			['config' => ['type' => 'table']],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => false,
			'error' => 'Missing "type" or "query" in report config.'
		], $out);
	}

	public function testCallToolReturnsErrorWhenEventStreamMissingInContext(): void {
		$factory = $this->createStub(IReportExporterFactory::class);
		$tool = new VizionCanvasAgentTool($factory);

		$out = $tool->callTool(
			'vizion_report_canvas',
			['config' => ['type' => 'table', 'query' => ['select' => []]]],
			$this->makeContextWithoutStream()
		);

		$this->assertSame([
			'ok' => false,
			'error' => 'Missing eventstream in context.'
		], $out);
	}

	public function testCallToolCreatesExporterSetsQueryAndPushesOpenAndRenderWhenConnected(): void {
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
		$config = ['type' => 'datatable', 'query' => $query];

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
				['id' => 1]
			]
		);

		$exporter = $this->createMock(IReportExporter::class);
		$exporter->expects($this->once())
			->method('setExportQuery')
			->with($query)
			->willReturnSelf();

		$exporter->expects($this->once())
			->method('toString')
			->willReturn('<p>hello</p>');

		$exporter->expects($this->once())
			->method('toSql')
			->willReturn('SELECT 1');

		$exporter->expects($this->once())
			->method('getResult')
			->willReturn($result);

		$factory = $this->createMock(IReportExporterFactory::class);
		$factory->expects($this->once())
			->method('createExporter')
			->with('datatablereportexporter')
			->willReturn($exporter);

		$tool = new VizionCanvasAgentTool($factory);

		$out = $tool->callTool(
			'vizion_report_canvas',
			[
				'canvas_id' => ' c1 ',
				'title' => ' My Report ',
				'open' => true,
				'config' => $config
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
		$this->assertSame([
			'id' => 'c1',
			'title' => 'My Report',
			'focus' => true
		], $events[0][1]);

		$this->assertSame('canvas.render', $events[1][0]);

		$render = $events[1][1];
		$this->assertSame('c1', $render['id']);
		$this->assertSame('replace', $render['mode']);
		$this->assertSame('My Report', $render['title']);

		$this->assertIsArray($render['blocks']);
		$this->assertCount(1, $render['blocks']);
		$this->assertSame('html', $render['blocks'][0]['type']);
		$this->assertSame('<div><p>hello</p></div>', $render['blocks'][0]['html']);
		$this->assertFalse($render['blocks'][0]['sanitize']);
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

		$query = ['select' => []];
		$config = ['type' => 'table', 'query' => $query];

		$exporter = $this->createMock(IReportExporter::class);
		$exporter->expects($this->once())
			->method('setExportQuery')
			->with($query)
			->willReturnSelf();

		$exporter->method('toString')->willReturn('x');
		$exporter->method('toSql')->willReturn('SQL');
		$exporter->method('getResult')->willReturn(null);

		$factory = $this->createMock(IReportExporterFactory::class);
		$factory->expects($this->once())
			->method('createExporter')
			->with('htmltablereportexporter')
			->willReturn($exporter);

		$tool = new VizionCanvasAgentTool($factory);

		$out = $tool->callTool(
			'vizion_report_canvas',
			[
				'open' => true,
				'config' => $config
			],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => true,
			'canvas_id' => 'main',
			'sql' => 'SQL',
			'columns' => null
		], $out);

		$this->assertCount(0, $events);
	}

	public function testCallToolReturnsErrorWhenExporterFactoryThrows(): void {
		$stream = new class {
			public function isDisconnected(): bool {
				return false;
			}

			public function push(string $event, array $payload): void {
				// no-op
			}
		};

		$factory = $this->createMock(IReportExporterFactory::class);
		$factory->expects($this->once())
			->method('createExporter')
			->willThrowException(new \RuntimeException('no exporter'));

		$tool = new VizionCanvasAgentTool($factory);

		$out = $tool->callTool(
			'vizion_report_canvas',
			['config' => ['type' => 'piechart', 'query' => ['select' => []]]],
			$this->makeContextWithStream($stream)
		);

		$this->assertSame([
			'ok' => false,
			'error' => 'Report generation or canvas push failed: no exporter'
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

		$query = ['select' => []];
		$configJson = json_encode(['type' => 'table', 'query' => $query], JSON_UNESCAPED_SLASHES);

		$exporter = $this->createMock(IReportExporter::class);
		$exporter->expects($this->once())
			->method('setExportQuery')
			->with($query)
			->willReturnSelf();

		$exporter->method('toString')->willReturn('x');
		$exporter->method('toSql')->willReturn('SQL');
		$exporter->method('getResult')->willReturn(null);

		$factory = $this->createMock(IReportExporterFactory::class);
		$factory->expects($this->once())
			->method('createExporter')
			->with('htmltablereportexporter')
			->willReturn($exporter);

		$tool = new VizionCanvasAgentTool($factory);

		$out = $tool->callTool(
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
			'columns' => null
		], $out);

		// Open flag is false, so only render event should be pushed.
		$this->assertCount(1, $events);
		$this->assertSame('canvas.render', $events[0][0]);

		$render = $events[0][1];
		$this->assertSame('main', $render['id']);
		$this->assertSame('Report', $render['title']);
	}
}
