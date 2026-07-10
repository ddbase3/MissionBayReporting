<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of MissionBayReporting for BASE3 Framework.
 *
 * MissionBayReporting extends the BASE3 framework with reporting tools
 * for MissionBay chat agents using DataHawk and Vizion.
 * It provides query execution and visual report rendering.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/missionbayreporting
 * https://github.com/ddbase3/MissionBayReporting
 **********************************************************************/

namespace MissionBayReporting;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;

class MissionBayReportingPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return 'missionbayreportingplugin';
	}

	// Implementation of IPlugin

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED);
	}
}
