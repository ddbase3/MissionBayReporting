<?php declare(strict_types=1);

namespace Test\MissionBayReporting\MissionBay;

final class VizionCanvasPushFailingStream {

	public function isDisconnected(): bool {
		return false;
	}

	public function push(string $event, array $payload): void {
		throw new \RuntimeException('Canvas stream rejected the event');
	}
}
