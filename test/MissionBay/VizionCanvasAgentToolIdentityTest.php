<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use Base3\Api\IClassMap;
use MissionBayReporting\MissionBay\VizionCanvasAgentTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;

#[CoversClass(VizionCanvasAgentTool::class)]
final class VizionCanvasAgentToolIdentityTest extends TestCase {

	public function testConstructorInitializesItsInheritedResourceIdentity(): void {
		$tool = new VizionCanvasAgentTool(
			$this->createStub(IQueryService::class),
			$this->createStub(IClassMap::class)
		);
		$this->assertStringStartsWith('resource_', $tool->getId());
		$this->assertSame($tool->getId(), $tool->id());
		$tool->setId('report-canvas');
		$this->assertSame('report-canvas', $tool->getId());
	}
}
