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

use MissionBay\Resource\AbstractAgentResource;
use MissionBay\Api\IAgentTool;
use AssistantFoundation\Api\IAgentContext;
use ResourceFoundation\Api\IQueryService;

class DataHawkAgentTool extends AbstractAgentResource implements IAgentTool {

	private IQueryService $qs;

	public function __construct(IQueryService $qs) {
		$this->qs = $qs;
	}

	public static function getName(): string {
		return 'datahawkagenttool';
	}

	public function getDescription(): string {
		return 'Executes structured DataHawk queries provided as JSON.';
	}

	/**
	 * Expose tool to the LLM.
	 */
	public function getToolDefinitions(): array {
		return [[
			'type' => 'function',
			'label' => 'Database Report',
			'category' => 'data',
			'tags' => ['datahawk', 'query', 'structured_query', 'analytics'],
			'priority' => 50,
			'function' => [
				'name' => 'execute_datahawk_query',
				'description' => 'Executes a structured DataHawk query. Returns rows, columns and metadata.',
				'parameters' => [
					'type' => 'object',
					'properties' => [
						'query' => [
							'type' => 'object',
							'description' => 'Structured DataHawk query object (may also be passed as a JSON string).'
						]
					],
					'required' => ['query']
				]
			]
		]];
	}

	/**
	 * Executes the tool function call.
	 */
	public function callTool(string $name, array $arguments, IAgentContext $context): mixed {
		if ($name !== 'execute_datahawk_query') {
			throw new \InvalidArgumentException("Unsupported tool: " . $name);
		}

		try {
			$query = $this->resolveQuery($arguments);
		} catch (\Throwable $e) {
			return ['error' => $e->getMessage()];
		}

		try {
			$result = $this->qs->executeQuery($query);
		} catch (\Throwable $e) {
			return ['error' => 'Query execution failed: ' . $e->getMessage()];
		}

		return [
			'columns' => $result->columns,
			'rows' => $result->rows,
			'count' => count($result->rows),
			'query_received' => $query
		];
	}

	/**
	 * Accepts either an already-decoded query array or a JSON string.
	 */
	private function resolveQuery(array $arguments): array {
		if (!array_key_exists('query', $arguments)) {
			throw new \InvalidArgumentException('Missing parameter: query');
		}

		$query = $arguments['query'];

		// Case 1: JSON string
		if (is_string($query)) {
			$query = trim($query);
			if ($query === '') {
				throw new \InvalidArgumentException('Empty query string.');
			}
			$decoded = json_decode($query, true);
			if (!is_array($decoded)) {
				throw new \InvalidArgumentException('Invalid JSON in query parameter.');
			}
			return $decoded;
		}

		// Case 2: already a decoded array/object
		if (is_array($query)) {
			return $query;
		}

		throw new \InvalidArgumentException('Invalid type for "query" parameter. Expected JSON string or object.');
	}
}
