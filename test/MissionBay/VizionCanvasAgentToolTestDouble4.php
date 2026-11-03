<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IClassMap;
use MissionBayReporting\MissionBay\VizionCanvasAgentTool;
use PHPUnit\Framework\TestCase;
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Api\IReportExporter;
use ResourceFoundation\Dto\QueryResult;

final class VizionCanvasAgentToolTestDouble4 {
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
		}
