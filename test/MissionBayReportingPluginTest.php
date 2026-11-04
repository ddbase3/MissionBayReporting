<?php declare(strict_types=1);

namespace Test\MissionBayReporting;

use Base3\Api\IContainer;
use MissionBayReporting\MissionBayReportingPlugin;
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
