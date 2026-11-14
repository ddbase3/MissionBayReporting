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

namespace Base3\MissionBayReporting\Test;

use Base3\CoreFoundation\Api\IContainer;
use Base3\MissionBayReporting\MissionBayReportingPlugin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MissionBayReportingPlugin::class)]
final class MissionBayReportingPluginTest extends TestCase {

	public function testPluginRegistersOnlyItsExistingSharedInstance(): void {
		$container = $this->createMock(IContainer::class);
		$plugin = new MissionBayReportingPlugin($container);

		$container->expects($this->once())
			->method('set')
			->with('missionbayreportingplugin', $this->identicalTo($plugin), IContainer::SHARED)
			->willReturnSelf();

		$this->assertSame('missionbayreportingplugin', MissionBayReportingPlugin::getName());
		$this->assertNull($plugin->init());
	}
}
