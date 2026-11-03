<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IClassMap;
use MissionBayReporting\MissionBay\VizionCanvasAgentTool;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Api\IReportExporter;
use ResourceFoundation\Dto\QueryResult;

final class VizionCanvasAgentToolTestDouble1 {
			public function isDisconnected(): bool {
				return false;
			}

			public function push(string $event, array $payload): void {}
		}
