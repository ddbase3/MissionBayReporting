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

use AssistantFoundation\Api\IAgentContext;
use Base3\Api\IOutputSchemaProvider;
use Base3\Api\ISchemaProvider;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Api\IAgentTool;
use MissionBay\Resource\AbstractAgentResource;
use Vizion\Api\IReportDataService;

/**
 * Read-only access to curated Vizion reports.
 *
 * The tool deliberately exposes report semantics instead of DataHawk query
 * construction. DataHawkAgentTool remains available for free analytical
 * queries that are not represented by a finished Vizion report.
 */
final class VizionReportAgentTool extends AbstractAgentResource implements IAgentTool, ISchemaProvider, IOutputSchemaProvider {

	private const FN_DESCRIBE = 'describe_vizion_reports';
	private const FN_EXECUTE = 'execute_vizion_report';
	private const FN_TREE = 'search_vizion_tree';

	private const DEFAULT_PRIORITY = 70;
	private const DEFAULT_DESCRIBE_LIMIT = 10;
	private const MAX_DESCRIBE_LIMIT = 30;
	private const DEFAULT_TREE_LIMIT = 20;
	private const MAX_TREE_LIMIT = 100;

	private string $reportingScope = '';
	private int $priority = self::DEFAULT_PRIORITY;
	private int $describeLimit = self::DEFAULT_DESCRIBE_LIMIT;

	public function __construct(
		private readonly IReportDataService $reportDataService,
		private readonly IAgentConfigValueResolver $resolver,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'vizionreportagenttool';
	}

	public function getDescription(): string {
		return 'Provides read-only discovery, filtering and execution of curated Vizion reports.';
	}

	/** @return array<string,mixed> */
	public function getSchema(): array {
		return [
			'$schema' => 'https://json-schema.org/draft-2020-12/schema',
			'type' => 'object',
			'properties' => [
				'reportingscope' => [
					'title' => 'reportingScope',
					'type' => 'string',
					'description' => 'User-facing ResourceFoundation reporting scope exposed by this tool instance, for example "ilias".'
				],
				'priority' => [
					'type' => 'integer',
					'description' => 'Tool priority in the active MissionBay tool catalog.',
					'default' => self::DEFAULT_PRIORITY
				],
				'describeLimit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_DESCRIBE_LIMIT,
					'description' => 'Maximum number of report candidates returned by discovery.',
					'default' => self::DEFAULT_DESCRIBE_LIMIT
				]
			],
			'required' => ['reportingscope'],
			'additionalProperties' => false
		];
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$scope = $this->resolver->resolveValue($config['reportingscope'] ?? null);
		$this->reportingScope = is_scalar($scope) ? trim((string)$scope) : '';
		if($this->reportingScope === '') {
			throw new \InvalidArgumentException('VizionReportAgentTool requires reportingscope.');
		}

		$this->priority = $this->resolveInt($config['priority'] ?? null, self::DEFAULT_PRIORITY, 0, 1000);
		$this->describeLimit = $this->resolveInt(
			$config['describeLimit'] ?? null,
			self::DEFAULT_DESCRIBE_LIMIT,
			1,
			self::MAX_DESCRIBE_LIMIT
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function getToolDefinitions(): array {
		return [
			[
				'type' => 'function',
				'label' => 'Vizion Reports',
				'category' => 'reporting',
				'tags' => ['vizion', 'reporting', 'reports', 'readonly'],
				'priority' => $this->priority,
				'readOnlyHint' => true,
				'mutation' => false,
				'requiresApproval' => false,
				'function' => [
					'name' => self::FN_DESCRIBE,
					'description' => 'Discover finished Vizion reports in the configured reporting scope or describe one exact report. Prefer this tool over DataHawk when the user asks for data already represented by a named report. Search by business concepts such as courses, users, certificates, progress or organisation units. Use the exact report id returned here for execution.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'search' => [
								'type' => 'string',
								'description' => 'Optional business-concept search across report descriptions, fields and tree filters.'
							],
							'report' => [
								'type' => 'string',
								'description' => 'Optional exact report id returned by a previous discovery call. Returns the report fields, ordinary filters, tree filters and defaults.'
							],
							'limit' => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => self::MAX_DESCRIBE_LIMIT,
								'description' => 'Optional candidate limit for report discovery.'
							]
						],
						'required' => [],
						'additionalProperties' => false
					]
				]
			],
			[
				'type' => 'function',
				'label' => 'Vizion Report Data',
				'category' => 'reporting',
				'tags' => ['vizion', 'reporting', 'data', 'filters', 'readonly'],
				'priority' => $this->priority,
				'readOnlyHint' => true,
				'mutation' => false,
				'requiresApproval' => false,
				'function' => [
					'name' => self::FN_EXECUTE,
					'description' => 'Execute one finished Vizion report with its configured search, ordinary filters, tree filters, sorting and paging. Use describe_vizion_reports before this call unless the exact report contract is already known. Never invent field aliases, filter keys or tree node ids. Use search_vizion_tree to resolve category or organisation-unit ids.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'report' => [
								'type' => 'string',
								'description' => 'Exact report id returned by describe_vizion_reports.'
							],
							'search' => [
								'type' => 'string',
								'description' => 'Optional report-wide text search.'
							],
							'filters' => [
								'type' => 'object',
								'description' => 'Optional ordinary report filter values keyed by configured field alias.'
							],
							'treeFilters' => [
								'type' => 'object',
								'description' => 'Optional tree selections keyed by configured tree-filter key. Values are exact node ids returned by search_vizion_tree.'
							],
							'sort' => [
								'type' => 'array',
								'description' => 'Optional sort array. The first entry contains key and dir.',
								'items' => [
									'type' => 'object',
									'properties' => [
										'key' => ['type' => 'string'],
										'dir' => ['type' => 'string', 'enum' => ['asc', 'desc']]
									],
									'required' => ['key'],
									'additionalProperties' => false
								]
							],
							'fields' => [
								'type' => 'array',
								'description' => 'Optional projection of configured field aliases. Omit to return all report fields.',
								'items' => ['type' => 'string']
							],
							'page' => [
								'type' => 'integer',
								'minimum' => 1,
								'description' => 'Result page, starting at 1.'
							],
							'pageSize' => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => 250,
								'description' => 'Rows per page. Vizion enforces a maximum of 250.'
							]
						],
						'required' => ['report'],
						'additionalProperties' => false
					]
				]
			],
			[
				'type' => 'function',
				'label' => 'Vizion Tree Search',
				'category' => 'reporting',
				'tags' => ['vizion', 'reporting', 'tree', 'category', 'orgunit', 'readonly'],
				'priority' => $this->priority,
				'readOnlyHint' => true,
				'mutation' => false,
				'requiresApproval' => false,
				'function' => [
					'name' => self::FN_TREE,
					'description' => 'Search one configured Vizion tree filter and resolve exact node ids plus breadcrumb paths. Search terms separated by spaces are matched as an AND search. Use returned ids in execute_vizion_report.treeFilters.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'report' => [
								'type' => 'string',
								'description' => 'Exact report id returned by describe_vizion_reports.'
							],
							'treeFilter' => [
								'type' => 'string',
								'description' => 'Exact tree-filter key from the report description, for example category or orgunit.'
							],
							'search' => [
								'type' => 'string',
								'description' => 'Optional node-label or breadcrumb search. Multiple words are ANDed.'
							],
							'limit' => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => self::MAX_TREE_LIMIT,
								'description' => 'Maximum number of matching tree nodes.'
							]
						],
						'required' => ['report', 'treeFilter'],
						'additionalProperties' => false
					]
				]
			]
		];
	}

	/** @return array<string,mixed> */
	public function getOutputSchemas(): array {
		$base = [
			'type' => 'object',
			'properties' => [
				'ok' => ['type' => 'boolean'],
				'operation' => ['type' => 'string']
			],
			'required' => ['ok', 'operation']
		];

		return [
			self::FN_DESCRIBE => $base,
			self::FN_EXECUTE => $base,
			self::FN_TREE => $base
		];
	}

	public function callTool(string $name, array $arguments, IAgentContext $context): mixed {
		return match ($name) {
			self::FN_DESCRIBE => $this->callDescribe($arguments),
			self::FN_EXECUTE => $this->callExecute($arguments),
			self::FN_TREE => $this->callTreeSearch($arguments),
			default => throw new \InvalidArgumentException('Unsupported tool: ' . $name)
		};
	}

	/** @return array<string,mixed> */
	private function callDescribe(array $arguments): array {
		try {
			$report = trim((string)($arguments['report'] ?? ''));
			if($report !== '') {
				return [
					'ok' => true,
					'operation' => self::FN_DESCRIBE,
					'mode' => 'report',
					'report' => $this->reportDataService->describeReport($this->qualifyReport($report))
				];
			}

			$search = trim((string)($arguments['search'] ?? ''));
			$limit = $this->resolveInt(
				$arguments['limit'] ?? null,
				$this->describeLimit,
				1,
				self::MAX_DESCRIBE_LIMIT
			);

			return [
				'ok' => true,
				'operation' => self::FN_DESCRIBE,
				'mode' => $search === '' ? 'catalog' : 'search',
				'catalog' => $this->reportDataService->listReports($this->reportingScope, $search, $limit)
			];
		}
		catch(\Throwable $exception) {
			return $this->errorResult(self::FN_DESCRIBE, 'describe_failed', $exception->getMessage());
		}
	}

	/** @return array<string,mixed> */
	private function callExecute(array $arguments): array {
		try {
			$report = trim((string)($arguments['report'] ?? ''));
			if($report === '') {
				throw new \InvalidArgumentException('Missing report.');
			}

			$qualifiedReport = $this->qualifyReport($report);
			$this->validateExecutionArguments($qualifiedReport, $arguments);

			$payload = [];
			foreach(['search', 'filters', 'treeFilters', 'sort', 'fields', 'page', 'pageSize'] as $key) {
				if(array_key_exists($key, $arguments)) {
					$payload[$key] = $arguments[$key];
				}
			}

			$result = $this->reportDataService->executeReport($qualifiedReport, $payload);
			$result['data'] = $this->stripInternalRowMetadata($result['data'] ?? []);

			return [
				'ok' => true,
				'operation' => self::FN_EXECUTE,
				'result' => $result
			];
		}
		catch(\Throwable $exception) {
			return $this->errorResult(self::FN_EXECUTE, 'execute_failed', $exception->getMessage());
		}
	}

	/** @return array<string,mixed> */
	private function callTreeSearch(array $arguments): array {
		try {
			$report = trim((string)($arguments['report'] ?? ''));
			$key = trim((string)($arguments['treeFilter'] ?? ''));
			if($report === '' || $key === '') {
				throw new \InvalidArgumentException('Missing report or treeFilter.');
			}

			$search = trim((string)($arguments['search'] ?? ''));
			$limit = $this->resolveInt(
				$arguments['limit'] ?? null,
				self::DEFAULT_TREE_LIMIT,
				1,
				self::MAX_TREE_LIMIT
			);

			return [
				'ok' => true,
				'operation' => self::FN_TREE,
				'result' => $this->reportDataService->searchTree(
					$this->qualifyReport($report),
					$key,
					$search,
					$limit
				)
			];
		}
		catch(\Throwable $exception) {
			return $this->errorResult(self::FN_TREE, 'tree_search_failed', $exception->getMessage());
		}
	}

	private function validateExecutionArguments(string $report, array $arguments): void {
		$description = $this->reportDataService->describeReport($report);
		$fieldMap = [];
		$sortableMap = [];
		$filterMap = [];
		$treeFilterMap = [];

		foreach(($description['fields'] ?? []) as $field) {
			if(!is_array($field)) {
				continue;
			}
			$key = trim((string)($field['key'] ?? ''));
			if($key === '') {
				continue;
			}
			$fieldMap[$key] = true;
			if((bool)($field['sortable'] ?? false)) {
				$sortableMap[$key] = true;
			}
		}

		foreach(($description['filters'] ?? []) as $filter) {
			if(is_array($filter)) {
				$key = trim((string)($filter['key'] ?? ''));
				if($key !== '') {
					$filterMap[$key] = true;
				}
			}
		}

		foreach(($description['treeFilters'] ?? []) as $filter) {
			if(is_array($filter)) {
				$key = trim((string)($filter['key'] ?? ''));
				if($key !== '') {
					$treeFilterMap[$key] = true;
				}
			}
		}

		$this->assertKnownObjectKeys($arguments['filters'] ?? null, $filterMap, 'filter');
		$this->assertKnownObjectKeys($arguments['treeFilters'] ?? null, $treeFilterMap, 'tree filter');

		if(array_key_exists('fields', $arguments)) {
			if(!is_array($arguments['fields'])) {
				throw new \InvalidArgumentException('fields must be an array of configured field aliases.');
			}
			foreach($arguments['fields'] as $field) {
				$field = is_scalar($field) ? trim((string)$field) : '';
				if($field === '' || !isset($fieldMap[$field])) {
					throw new \InvalidArgumentException('Unknown report field: ' . $field);
				}
			}
		}

		if(array_key_exists('sort', $arguments)) {
			if(!is_array($arguments['sort'])) {
				throw new \InvalidArgumentException('sort must be an array.');
			}
			$first = reset($arguments['sort']);
			if($first !== false) {
				if(!is_array($first)) {
					throw new \InvalidArgumentException('sort entries must be objects.');
				}
				$key = trim((string)($first['key'] ?? ''));
				if($key === '' || !isset($sortableMap[$key])) {
					throw new \InvalidArgumentException('Unknown or non-sortable report field: ' . $key);
				}
			}
		}
	}

	/**
	 * @param array<string,bool> $allowed
	 */
	private function assertKnownObjectKeys(mixed $value, array $allowed, string $label): void {
		if($value === null) {
			return;
		}
		if(!is_array($value)) {
			throw new \InvalidArgumentException($label . ' values must be an object.');
		}

		foreach(array_keys($value) as $key) {
			$key = (string)$key;
			if(!isset($allowed[$key])) {
				throw new \InvalidArgumentException('Unknown ' . $label . ': ' . $key);
			}
		}
	}

	private function qualifyReport(string $report): string {
		$report = trim($report);
		if($report === '') {
			throw new \InvalidArgumentException('Missing report identifier.');
		}

		if(str_contains($report, ':')) {
			[$scope, $localReport] = explode(':', $report, 2);
			if($scope !== $this->reportingScope || trim($localReport) === '') {
				throw new \InvalidArgumentException('Report is outside the configured reporting scope.');
			}
			return $report;
		}

		return $this->reportingScope . ':' . $report;
	}

	/** @return array<int,array<string,mixed>> */
	private function stripInternalRowMetadata(mixed $rows): array {
		if(!is_array($rows)) {
			return [];
		}

		$result = [];
		foreach($rows as $row) {
			if(!is_array($row)) {
				continue;
			}
			foreach(array_keys($row) as $key) {
				if(str_starts_with((string)$key, '__')) {
					unset($row[$key]);
				}
			}
			$result[] = $row;
		}

		return $result;
	}

	/** @return array<string,mixed> */
	private function errorResult(string $operation, string $code, string $message): array {
		return [
			'ok' => false,
			'operation' => $operation,
			'error' => [
				'code' => $code,
				'message' => $message
			]
		];
	}

	private function resolveInt(mixed $value, int $default, int $min, int $max): int {
		$value = $this->resolver->resolveValue($value);
		if(!is_numeric($value)) {
			return $default;
		}

		return max($min, min($max, (int)$value));
	}
}
