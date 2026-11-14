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

use Base3\CoreFoundation\Api\IClassMap;
use Base3\MissionBayReporting\MissionBay\VizionCanvasAgentTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Base3\ResourceFoundation\Api\IQueryService;

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
