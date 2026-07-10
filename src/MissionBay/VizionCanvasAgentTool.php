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

namespace MissionBayReporting\MissionBay;

use DataHawk\Api\IReportExporterFactory;
use MissionBay\Api\IAgentTool;
use AssistantFoundation\Api\IAgentContext;
use MissionBay\Resource\AbstractAgentResource;

class VizionCanvasAgentTool extends AbstractAgentResource implements IAgentTool {

	/** @var IReportExporterFactory */
	private IReportExporterFactory $reportExporterFactory;

	public function __construct(IReportExporterFactory $reportExporterFactory) {
		$this->reportExporterFactory = $reportExporterFactory;
	}

	public static function getName(): string {
		return 'vizioncanvasagenttool';
	}

	public function getDescription(): string {
		return 'Renders a DataHawk-based report into the chatbot canvas using a single HTML block.';
	}

	public function getToolDefinitions(): array {
		return [[
			'type' => 'function',
			'label' => 'Vizion Report Canvas',
			'category' => 'reporting',
			'tags' => ['vizion', 'report', 'canvas', 'datatable', 'chart'],
			'priority' => 50,
			'function' => [
				'name' => 'vizion_report_canvas',
				'description' => 'Executes a DataHawk report and renders the HTML result into the chatbot canvas.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'canvas_id' => [
							'type' => 'string',
							'description' => 'Target canvas id (default: "main").'
						],
						'title' => [
							'type' => 'string',
							'description' => 'Canvas title (default: "Report").'
						],
						'open' => [
							'type' => 'boolean',
							'description' => 'Whether to open/focus the canvas before rendering (default: true).'
						],
						'config' => [
							'type' => 'object',
							'description' => 'Report configuration object containing "type" and "query".'
						]
					],
					'required' => ['config']
				]
			]
		]];
	}

	public function callTool(string $toolName, array $arguments, IAgentContext $context): array {

		if ($toolName !== 'vizion_report_canvas') {
			throw new \InvalidArgumentException("Unsupported tool: {$toolName}");
		}

		$canvasId = $this->resolveCanvasId($arguments);
		$title = $this->resolveTitle($arguments);
		$open = $this->resolveOpenFlag($arguments);

		$config = $this->resolveConfig($arguments);

		if (!isset($config['type']) || !isset($config['query'])) {
			return [
				'ok' => false,
				'error' => 'Missing "type" or "query" in report config.'
			];
		}

		$stream = $context->getVar('eventstream');
		if (!$stream) {
			return [
				'ok' => false,
				'error' => 'Missing eventstream in context.'
			];
		}

		try {
			$exporterType = $this->mapExporterType((string)$config['type']);

			$exporter = $this->reportExporterFactory->createExporter($exporterType);
			$exporter->setExportQuery($config['query']);

			$reportHtml = $exporter->toString();
			$sql = $exporter->toSql();

			$columns = null;
			$result = $exporter->getResult();
			if ($result !== null) {
				$columns = $result->columns;
			}

			if ($open && !$stream->isDisconnected()) {
				$stream->push('canvas.open', [
					'id' => $canvasId,
					'title' => $title,
					'focus' => true
				]);
			}

			if (!$stream->isDisconnected()) {
				$stream->push('canvas.render', [
					'id' => $canvasId,
					'mode' => 'replace',
					'title' => $title,
					'blocks' => [
						[
							'type' => 'html',
							'html' => '<div>'.$reportHtml.'</div>',
							// report exporter already generates trusted HTML
							'sanitize' => false
						]
					]
				]);
			}

			return [
				'ok' => true,
				'canvas_id' => $canvasId,
				'sql' => $sql,
				'columns' => $columns
			];

		} catch (\Throwable $e) {
			return [
				'ok' => false,
				'error' => 'Report generation or canvas push failed: ' . $e->getMessage()
			];
		}
	}

	private function resolveCanvasId(array $arguments): string {
		$canvasId = trim((string)($arguments['canvas_id'] ?? 'main'));
		if ($canvasId === '') {
			$canvasId = 'main';
		}
		return $canvasId;
	}

	private function resolveTitle(array $arguments): string {
		$title = trim((string)($arguments['title'] ?? 'Report'));
		if ($title === '') {
			$title = 'Report';
		}
		return $title;
	}

	private function resolveOpenFlag(array $arguments): bool {
		$open = $arguments['open'] ?? true;
		$open = filter_var($open, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
		return $open !== null ? $open : true;
	}

	/**
	 * Accepts either an already-decoded config array or a JSON string.
	 */
	private function resolveConfig(array $arguments): array {
		$config = $arguments['config'] ?? null;

		if (is_string($config)) {
			$decoded = json_decode($config, true);
			if (!is_array($decoded)) {
				throw new \InvalidArgumentException('Could not parse config JSON.');
			}
			return $decoded;
		}

		if (!is_array($config)) {
			throw new \InvalidArgumentException('Missing or invalid "config" argument.');
		}

		return $config;
	}

	private function mapExporterType(string $type): string {
		$type = strtolower($type);

		return match ($type) {
			'table' => 'htmltablereportexporter',
			'datatable' => 'datatablereportexporter',
			'piechart' => 'piechartreportexporter',
			'barchart' => 'barchartreportexporter',
			default => 'htmltablereportexporter'
		};
	}
}
