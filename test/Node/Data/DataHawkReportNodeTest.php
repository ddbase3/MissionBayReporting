<?php declare(strict_types=1);

namespace Test\MissionBayReporting\Node\Data;

use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IClassMap;
use MissionBay\Agent\AgentNodePort;
use MissionBayReporting\Node\Data\DataHawkReportNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Api\IReportExporter;
use ResourceFoundation\Dto\QueryResult;

#[CoversClass(DataHawkReportNode::class)]
final class DataHawkReportNodeTest extends TestCase {

	private function makeNode(?IQueryService $queryService = null, ?IClassMap $classMap = null): DataHawkReportNode {
		return new DataHawkReportNode(
			$queryService ?? $this->createStub(IQueryService::class),
			$classMap ?? $this->createStub(IClassMap::class)
		);
	}

	private function execute(DataHawkReportNode $node, mixed $config): array {
		return $node->execute(['config' => $config], [], $this->createStub(IAgentContext::class));
	}

	public function testConstructorInitializesTheInheritedNodeIdentity(): void {
		$node = $this->makeNode();
		$this->assertStringStartsWith('node_', $node->getId());
		$node->setId('report-node');
		$this->assertSame('report-node', $node->getId());
		$this->assertStringContainsString('[ datahawkreportnode | report-node ]', $this->execute($node, '')['error']);
	}

	public function testTechnicalNameAndPortsDescribeTheExistingNodeContract(): void {
		$node = $this->makeNode();
		$this->assertSame('datahawkreportnode', DataHawkReportNode::getName());
		$this->assertStringContainsString('structured ResourceFoundation query', $node->getDescription());

		$inputs = $node->getInputDefinitions();
		$this->assertCount(1, $inputs);
		$this->assertInstanceOf(AgentNodePort::class, $inputs[0]);
		$this->assertSame('config', $inputs[0]->name);
		$this->assertSame('string', $inputs[0]->type);
		$this->assertTrue($inputs[0]->required);

		$outputs = $node->getOutputDefinitions();
		$this->assertSame(['response', 'report', 'columns', 'sql', 'error'], array_map(
			static fn(AgentNodePort $port): string => $port->name,
			$outputs
		));
		$this->assertSame(['string', 'string', 'array', 'string', 'string'], array_map(
			static fn(AgentNodePort $port): string => $port->type,
			$outputs
		));
		foreach($outputs as $port) {
			$this->assertFalse($port->required);
		}
	}

	public function testMissingNonStringAndMalformedConfigurationNeverAccessQueryService(): void {
		$queryService = $this->createMock(IQueryService::class);
		$queryService->expects($this->never())->method('executeQuery');
		$node = $this->makeNode($queryService);

		foreach([null, '', " \n ", [], 7] as $config) {
			$result = $this->execute($node, $config);
			$this->assertArrayHasKey('error', $result);
			$this->assertStringContainsString('Missing or invalid config input', $result['error']);
		}

		$result = $this->execute($node, '{invalid');
		$this->assertStringContainsString('Could not parse config JSON', $result['error']);
		$result = $this->execute($node, '"string"');
		$this->assertStringContainsString('Could not parse config JSON', $result['error']);
	}

	public function testConfigurationRequiresTypeAndStructuredQuery(): void {
		$node = $this->makeNode();
		foreach(['{}', '{"type":"table"}', '{"query":{}}'] as $config) {
			$this->assertStringContainsString('Missing "type" or "query"', $this->execute($node, $config)['error']);
		}
		$this->assertStringContainsString('Invalid "query"', $this->execute($node, '{"type":"table","query":"SELECT 1"}')['error']);
	}

	public function testAllSupportedReportTypesSelectTheExistingExporterAndForwardQueryResult(): void {
		foreach([
			'table' => 'htmltablereportexporter',
			'datatable' => 'datatablereportexporter',
			'piechart' => 'piechartreportexporter',
			'barchart' => 'barchartreportexporter',
			'unknown' => 'htmltablereportexporter',
			'PIECHART' => 'piechartreportexporter'
		] as $type => $exporterName) {
			$result = new QueryResult(
				columns: [['name' => 'count', 'type' => 'int']],
				rows: [['count' => 3]],
				debugSql: 'SELECT COUNT(*) FROM report'
			);
			$query = ['type' => 'select', 'table' => 'report'];
			$queryService = $this->createMock(IQueryService::class);
			$queryService->expects($this->once())->method('executeQuery')->with($query)->willReturn($result);

			$exporter = $this->createMock(IReportExporter::class);
			$exporter->expects($this->once())->method('setResult')->with($this->identicalTo($result))->willReturnSelf();
			$exporter->expects($this->once())->method('toString')->willReturn('<table>3</table>');
			$classMap = $this->createMock(IClassMap::class);
			$classMap->expects($this->once())->method('getInstanceByInterfaceName')
				->with(IReportExporter::class, $exporterName)->willReturn($exporter);

			$node = $this->makeNode($queryService, $classMap);
			$output = $this->execute($node, json_encode(['message' => 'Count result', 'type' => $type, 'query' => $query]));
			$this->assertSame([
				'response' => 'Count result',
				'report' => '<table>3</table>',
				'sql' => 'SELECT COUNT(*) FROM report',
				'columns' => [['name' => 'count', 'type' => 'int']]
			], $output);
		}
	}

	public function testMissingExporterIsReportedAsNodeError(): void {
		$queryService = $this->createStub(IQueryService::class);
		$queryService->method('executeQuery')->willReturn(new QueryResult([], [], null));
		$node = $this->makeNode($queryService, $this->createStub(IClassMap::class));

		$output = $this->execute($node, '{"type":"datatable","query":{"type":"select"}}');
		$this->assertStringContainsString('Report exporter is not available: datatablereportexporter', $output['error']);
	}

	public function testQueryAndExporterExceptionsAreReportedWithoutReturningPartialReport(): void {
		$queryService = $this->createStub(IQueryService::class);
		$queryService->method('executeQuery')->willThrowException(new \RuntimeException('Query backend unavailable'));
		$out = $this->execute($this->makeNode($queryService), '{"type":"table","query":{}}');
		$this->assertSame(['error'], array_keys($out));
		$this->assertStringContainsString('Report generation failed: Query backend unavailable', $out['error']);

		$workingQueryService = $this->createStub(IQueryService::class);
		$workingQueryService->method('executeQuery')->willReturn(new QueryResult([], [], null));
		$exporter = $this->createStub(IReportExporter::class);
		$exporter->method('setResult')->willReturnSelf();
		$exporter->method('toString')->willThrowException(new \RuntimeException('Rendering failed'));
		$classMap = $this->createStub(IClassMap::class);
		$classMap->method('getInstanceByInterfaceName')->willReturn($exporter);
		$out = $this->execute($this->makeNode($workingQueryService, $classMap), '{"type":"table","query":{}}');
		$this->assertSame(['error'], array_keys($out));
		$this->assertStringContainsString('Report generation failed: Rendering failed', $out['error']);
	}
}
