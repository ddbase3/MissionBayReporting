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
use ResourceFoundation\Api\IQueryService;
use ResourceFoundation\Api\IScopedQuerySchemaProvider;
use ResourceFoundation\Dto\FieldMetadata;
use ResourceFoundation\Dto\JoinMetadata;
use ResourceFoundation\Dto\TableMetadata;

/**
 * DataHawkAgentTool
 *
 * Provides one configured reporting resource with two read-only operations:
 * on-demand schema discovery and structured query execution.
 *
 * Reporting schema context is never injected into the agent prompt. The agent
 * retrieves only the metadata it needs by calling describe_reporting_data.
 */
final class DataHawkAgentTool extends AbstractAgentResource implements IAgentTool, ISchemaProvider, IOutputSchemaProvider {

	private const FN_DESCRIBE = 'describe_reporting_data';
	private const FN_QUERY = 'execute_datahawk_query';

	private const DEFAULT_PRIORITY = 60;
	private const DEFAULT_DESCRIBE_LIMIT = 8;
	private const MAX_DESCRIBE_LIMIT = 20;
	private const DEFAULT_QUERY_LIMIT = 100;
	private const DEFAULT_MAX_QUERY_LIMIT = 1000;

	private const HELP_TOPICS = [
		'fields',
		'filters',
		'aggregations',
		'grouping',
		'sorting',
		'pagination',
		'examples'
	];

	private const ALLOWED_ELEMENT_TYPES = [
		'fld',
		'fn',
		'op',
		'subquery',
		'case',
		'windowfn'
	];

	private const ALLOWED_FUNCTIONS = [
		'ABS',
		'AVG',
		'CAST',
		'CEIL',
		'COALESCE',
		'CONCAT',
		'CONVERT',
		'COUNT',
		'CURDATE',
		'CURRENT_DATE',
		'DATE',
		'DATE_FORMAT',
		'DATEDIFF',
		'DAY',
		'DENSE_RANK',
		'FLOOR',
		'FROM_UNIXTIME',
		'GROUP_CONCAT',
		'IF',
		'IFNULL',
		'LAG',
		'LEAD',
		'LENGTH',
		'LOWER',
		'MAKEDATE',
		'MAX',
		'MIN',
		'MONTH',
		'NOW',
		'NULLIF',
		'RANK',
		'ROUND',
		'ROW_NUMBER',
		'SUBSTRING',
		'SUM',
		'TIMESTAMPDIFF',
		'TRIM',
		'UNIX_TIMESTAMP',
		'UPPER',
		'YEAR'
	];

	private const ALLOWED_OPERATORS = [
		'=',
		'!=',
		'<>',
		'>',
		'>=',
		'<',
		'<=',
		'AND',
		'OR',
		'IN',
		'NOT IN',
		'IS NULL',
		'IS NOT NULL',
		'BETWEEN',
		'LIKE',
		'NOT LIKE',
		'+',
		'-',
		'*',
		'/'
	];

	private int $priority = self::DEFAULT_PRIORITY;
	private int $describeLimit = self::DEFAULT_DESCRIBE_LIMIT;
	private int $defaultLimit = self::DEFAULT_QUERY_LIMIT;
	private int $maxLimit = self::DEFAULT_MAX_QUERY_LIMIT;

	/** @var string[] */
	private array $domainFilter = [];

	/** @var string[] */
	private array $categoryFilter = [];

	/** @var string[] */
	private array $tagFilter = [];

	/** @var string[] */
	private array $tableFilter = [];

	/** @var array<string,TableMetadata>|null */
	private ?array $allowedTableMap = null;

	public function __construct(
		private readonly IQueryService $queryService,
		private readonly IScopedQuerySchemaProvider $schemaProvider,
		private readonly IAgentConfigValueResolver $resolver,
		?string $id = null
	) {
		parent::__construct($id);
	}

	public static function getName(): string {
		return 'datahawkagenttool';
	}

	public function getDescription(): string {
		return 'Provides on-demand reporting schema discovery and read-only structured data queries.';
	}

	/**
	 * Component-preset configuration for one reporting data set.
	 *
	 * @return array<string,mixed>
	 */
	public function getSchema(): array {
		return [
			'$schema' => 'https://json-schema.org/draft-2020-12/schema',
			'type' => 'object',
			'properties' => [
				'priority' => [
					'type' => 'integer',
					'description' => 'Tool priority in the active MissionBay tool catalog.',
					'default' => self::DEFAULT_PRIORITY
				],
				'domainFilter' => $this->stringListSchema('Only expose tables from these ResourceFoundation domains. Empty means all visible domains.'),
				'categoryFilter' => $this->stringListSchema('Only expose tables from these ResourceFoundation categories. Empty means all visible categories.'),
				'tagFilter' => $this->stringListSchema('Only expose tables that contain at least one of these tags. Empty means all visible tags.'),
				'tableFilter' => $this->stringListSchema('Only expose these exact ResourceFoundation table names. Empty means all otherwise allowed tables.'),
				'describeLimit' => [
					'type' => 'integer',
					'minimum' => 1,
					'maximum' => self::MAX_DESCRIBE_LIMIT,
					'description' => 'Maximum number of table candidates returned by one schema search.',
					'default' => self::DEFAULT_DESCRIBE_LIMIT
				],
				'defaultLimit' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Result limit added to root SELECT queries when the agent does not provide one.',
					'default' => self::DEFAULT_QUERY_LIMIT
				],
				'maxLimit' => [
					'type' => 'integer',
					'minimum' => 1,
					'description' => 'Hard maximum for every explicitly declared SELECT limit.',
					'default' => self::DEFAULT_MAX_QUERY_LIMIT
				]
			],
			'required' => [],
			'additionalProperties' => false
		];
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$this->priority = $this->resolveInt($config['priority'] ?? null, self::DEFAULT_PRIORITY, 0, 1000);
		$this->describeLimit = $this->resolveInt(
			$config['describeLimit'] ?? null,
			self::DEFAULT_DESCRIBE_LIMIT,
			1,
			self::MAX_DESCRIBE_LIMIT
		);
		$this->defaultLimit = $this->resolveInt(
			$config['defaultLimit'] ?? null,
			self::DEFAULT_QUERY_LIMIT,
			1,
			1000000
		);
		$this->maxLimit = $this->resolveInt(
			$config['maxLimit'] ?? null,
			self::DEFAULT_MAX_QUERY_LIMIT,
			1,
			1000000
		);

		if ($this->defaultLimit > $this->maxLimit) {
			$this->defaultLimit = $this->maxLimit;
		}

		$this->domainFilter = $this->normalizeStringList($this->resolver->resolveValue($config['domainFilter'] ?? null));
		$this->categoryFilter = $this->normalizeStringList($this->resolver->resolveValue($config['categoryFilter'] ?? null));
		$this->tagFilter = $this->normalizeStringList($this->resolver->resolveValue($config['tagFilter'] ?? null));
		$this->tableFilter = $this->normalizeStringList($this->resolver->resolveValue($config['tableFilter'] ?? null));
		$this->allowedTableMap = null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function getToolDefinitions(): array {
		return [
			[
				'type' => 'function',
				'label' => 'Reporting Schema',
				'category' => 'data',
				'tags' => ['reporting', 'analytics', 'schema', 'data', 'readonly'],
				'priority' => $this->priority,
				'readOnlyHint' => true,
				'mutation' => false,
				'requiresApproval' => false,
				'function' => [
					'name' => self::FN_DESCRIBE,
					'description' => 'Discover reporting tables and fields or request targeted DataHawk query help. Optional help topics: fields, filters, aggregations, grouping, sorting, pagination, examples. Use topic without search/table when query syntax is unclear. DataHawk resolves declared table relations automatically when fields from related returned tables are referenced. For schema discovery, search for concepts such as "user", "course", "certificate" or "learning progress", not data values such as a person name. Search results return exact reporting table names and representative field names. After choosing a candidate, request that exact table once for full metadata, then execute the query. Never invent physical/source table names. Aliases are result-column labels on fields entries and are never element types.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'topic' => [
								'type' => 'string',
								'enum' => self::HELP_TOPICS,
								'description' => 'Optional targeted query-help topic. Use without search/table. fields explains SELECT expressions and aliases; filters explains where/having; aggregations explains COUNT/SUM/AVG/MIN/MAX and distinct; grouping explains group_by/having; sorting explains order_by including aggregate sorting; pagination explains limit/offset; examples returns complete query patterns.'
							],
							'search' => [
								'type' => 'string',
								'description' => 'Optional schema search text matched against table names, labels, descriptions, tags and field metadata.'
							],
							'table' => [
								'type' => 'string',
								'description' => 'Optional exact table name returned by a previous discovery call. Never guess a table name. Returns full field and relation metadata plus query rules for that table.'
							],
							'limit' => [
								'type' => 'integer',
								'minimum' => 1,
								'maximum' => self::MAX_DESCRIBE_LIMIT,
								'description' => 'Optional candidate limit for schema search.'
							]
						],
						'required' => [],
						'additionalProperties' => false
					]
				]
			],
			[
				'type' => 'function',
				'label' => 'Database Report',
				'category' => 'data',
				'tags' => ['reporting', 'analytics', 'datahawk', 'query', 'readonly'],
				'priority' => $this->priority,
				'readOnlyHint' => true,
				'mutation' => false,
				'requiresApproval' => false,
				'function' => [
					'name' => self::FN_QUERY,
					'description' => 'Execute one read-only structured DataHawk SELECT query and return rows plus column metadata. Use describe_reporting_data first unless the relevant table and field names are already known from a previous reporting tool result. For COUNT/SUM/AVG, grouping or sorting syntax, request describe_reporting_data with topic="aggregations", "grouping" or "sorting". Aliases belong only to SELECT fields entries beside element; never use type="alias". When sorting or filtering an aggregate, repeat the underlying fn expression instead of referencing its result alias. The query argument must be an object, never a JSON string.',
					'parameters' => [
						'type' => 'object',
						'properties' => [
							'query' => [
								'type' => 'object',
								'description' => 'Structured DataHawk SELECT query object. Query syntax and allowed functions/operators are returned by describe_reporting_data.'
							]
						],
						'required' => ['query'],
						'additionalProperties' => false
					]
				]
			]
		];
	}

	/**
	 * @return array<string,mixed>
	 */
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
			self::FN_QUERY => $base
		];
	}

	public function callTool(string $name, array $arguments, IAgentContext $context): mixed {
		return match ($name) {
			self::FN_DESCRIBE => $this->callDescribe($arguments),
			self::FN_QUERY => $this->callQuery($arguments),
			default => throw new \InvalidArgumentException('Unsupported tool: ' . $name)
		};
	}

	/**
	 * @return array<string,mixed>
	 */
	private function callDescribe(array $arguments): array {
		try {
			$topic = strtolower(trim((string)($arguments['topic'] ?? '')));
			$tableName = trim((string)($arguments['table'] ?? ''));
			$search = trim((string)($arguments['search'] ?? ''));

			if ($topic !== '') {
				if ($tableName !== '' || $search !== '') {
					throw new \InvalidArgumentException('topic cannot be combined with search or table. Request query help or schema metadata in one call.');
				}
				return $this->describeHelpTopic($topic);
			}

			$limit = $this->resolveCallLimit($arguments['limit'] ?? null);

			if ($tableName !== '') {
				return $this->describeTable($tableName);
			}

			return $this->searchTables($search, $limit);
		} catch (\Throwable $e) {
			return $this->errorResult(self::FN_DESCRIBE, 'describe_failed', $e->getMessage());
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function describeHelpTopic(string $topic): array {
		if (!in_array($topic, self::HELP_TOPICS, true)) {
			return [
				'ok' => false,
				'operation' => self::FN_DESCRIBE,
				'error' => [
					'code' => 'unknown_help_topic',
					'message' => 'Unknown reporting help topic: ' . $topic
				],
				'available_topics' => self::HELP_TOPICS
			];
		}

		return [
			'ok' => true,
			'operation' => self::FN_DESCRIBE,
			'mode' => 'help',
			'topic' => $topic,
			'help' => $this->queryHelp($topic)
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function queryHelp(string $topic): array {
		return match ($topic) {
			'fields' => [
				'title' => 'SELECT fields and result aliases',
				'rules' => [
					'Every SELECT fields entry wraps one expression in element.',
					'Put alias beside element in the fields entry. There is no alias element and type=alias is invalid.',
					'Use type=fld for a field reference and type=fn for a function expression.',
					'Use only table and field names returned by schema discovery.'
				],
				'example' => [
					'fields' => [
						[
							'element' => ['type' => 'fld', 'table' => 'TABLE', 'field' => 'FIELD'],
							'alias' => 'result_name'
						],
						[
							'element' => ['type' => 'fn', 'function' => 'COUNT', 'params' => [1]],
							'alias' => 'row_count'
						]
					]
				]
			],
			'filters' => [
				'title' => 'WHERE and HAVING conditions',
				'rules' => [
					'where and having contain expression objects directly, not fields-entry wrappers.',
					'Use type=op with operator and params. Nested AND/OR conditions are also type=op.',
					'Omit where or having completely when no condition is required.',
					'Use having for conditions on aggregate expressions.'
				],
				'examples' => [
					'where' => [
						'type' => 'op',
						'operator' => '=',
						'params' => [
							['type' => 'fld', 'table' => 'TABLE', 'field' => 'STATUS_FIELD'],
							1
						]
					],
					'having' => [
						'type' => 'op',
						'operator' => '>',
						'params' => [
							['type' => 'fn', 'function' => 'COUNT', 'params' => [1]],
							5
						]
					]
				]
			],
			'aggregations' => [
				'title' => 'Aggregate functions',
				'rules' => [
					'Aggregates are normal fn elements. Common aggregate functions are COUNT, SUM, AVG, MIN, MAX and GROUP_CONCAT.',
					'COUNT with params=[1] counts rows. COUNT with a fld param counts non-null field values.',
					'Use distinct=true on the fn element for COUNT(DISTINCT field) or another DISTINCT aggregate.',
					'The result alias belongs to the outer fields entry, never inside the fn element.',
					'For top-N aggregate reports, add group_by and sort by repeating the same fn expression in order_by. Do not use type=alias.',
					'Fields from related returned tables may be referenced directly; DataHawk resolves declared relations automatically.'
				],
				'examples' => [
					'count_rows' => [
						'element' => ['type' => 'fn', 'function' => 'COUNT', 'params' => [1]],
						'alias' => 'row_count'
					],
					'count_distinct' => [
						'element' => [
							'type' => 'fn',
							'function' => 'COUNT',
							'params' => [
								['type' => 'fld', 'table' => 'RELATED_TABLE', 'field' => 'COUNT_FIELD']
							],
							'distinct' => true
						],
						'alias' => 'distinct_count'
					],
					'top_n_by_count' => $this->topNCountExample()
				]
			],
			'grouping' => [
				'title' => 'GROUP BY and HAVING',
				'rules' => [
					'group_by is an array of expression elements directly. Do not wrap group_by entries in element/alias field wrappers.',
					'Every selected non-aggregate grouping value should normally appear in group_by.',
					'having uses the same op/fn expression syntax as where and may contain aggregate functions.',
					'Repeat the aggregate expression in having. Result aliases are not expression elements.'
				],
				'example' => [
					'group_by' => [
						['type' => 'fld', 'table' => 'TABLE', 'field' => 'GROUP_FIELD']
					],
					'having' => [
						'type' => 'op',
						'operator' => '>',
						'params' => [
							[
								'type' => 'fn',
								'function' => 'COUNT',
								'params' => [
									['type' => 'fld', 'table' => 'RELATED_TABLE', 'field' => 'COUNT_FIELD']
								]
							],
							5
						]
					]
				]
			],
			'sorting' => [
				'title' => 'ORDER BY',
				'rules' => [
					'order_by is an array. Every entry contains element and optional direction ASC or DESC.',
					'To sort by a normal field, put a fld expression in element.',
					'To sort by an aggregate, put the fn expression itself in element.',
					'Do not reference a SELECT result alias with type=alias. Repeat the underlying fld/fn expression.'
				],
				'examples' => [
					'field' => [
						'element' => ['type' => 'fld', 'table' => 'TABLE', 'field' => 'SORT_FIELD'],
						'direction' => 'ASC'
					],
					'aggregate' => [
						'element' => [
							'type' => 'fn',
							'function' => 'COUNT',
							'params' => [
								['type' => 'fld', 'table' => 'RELATED_TABLE', 'field' => 'COUNT_FIELD']
							]
						],
						'direction' => 'DESC'
					]
				]
			],
			'pagination' => [
				'title' => 'LIMIT and OFFSET',
				'rules' => [
					'limit is the maximum number of result rows and must be at least 1.',
					'offset is a zero-based non-negative row offset.',
					'If the root query omits limit, the reporting tool adds its configured default limit.',
					'Explicit limits above the configured maximum are rejected.'
				],
				'example' => ['limit' => 25, 'offset' => 50]
			],
			'examples' => [
				'title' => 'Complete structured query patterns',
				'rules' => [
					'Replace TABLE/FIELD placeholders only with exact names returned by schema discovery.',
					'For related-table fields, reference the related returned table directly and let DataHawk resolve declared relations.',
					'Keep aliases only on fields entries.'
				],
				'examples' => [
					'simple_filter' => $this->simpleFilterExample(),
					'top_n_by_count' => $this->topNCountExample()
				]
			],
			default => []
		};
	}

	/**
	 * @return array<string,mixed>
	 */
	private function simpleFilterExample(): array {
		return [
			'type' => 'select',
			'table' => 'TABLE',
			'fields' => [
				[
					'element' => ['type' => 'fld', 'table' => 'TABLE', 'field' => 'ID_FIELD'],
					'alias' => 'id'
				],
				[
					'element' => ['type' => 'fld', 'table' => 'TABLE', 'field' => 'LABEL_FIELD'],
					'alias' => 'label'
				]
			],
			'where' => [
				'type' => 'op',
				'operator' => '=',
				'params' => [
					['type' => 'fld', 'table' => 'TABLE', 'field' => 'STATUS_FIELD'],
					1
				]
			],
			'order_by' => [
				[
					'element' => ['type' => 'fld', 'table' => 'TABLE', 'field' => 'LABEL_FIELD'],
					'direction' => 'ASC'
				]
			],
			'limit' => 25
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function topNCountExample(): array {
		$countExpression = [
			'type' => 'fn',
			'function' => 'COUNT',
			'params' => [
				['type' => 'fld', 'table' => 'RELATED_TABLE', 'field' => 'COUNT_FIELD']
			]
		];

		return [
			'type' => 'select',
			'table' => 'BASE_TABLE',
			'fields' => [
				[
					'element' => ['type' => 'fld', 'table' => 'BASE_TABLE', 'field' => 'GROUP_ID_FIELD'],
					'alias' => 'group_id'
				],
				[
					'element' => ['type' => 'fld', 'table' => 'BASE_TABLE', 'field' => 'GROUP_LABEL_FIELD'],
					'alias' => 'group_label'
				],
				[
					'element' => $countExpression,
					'alias' => 'item_count'
				]
			],
			'group_by' => [
				['type' => 'fld', 'table' => 'BASE_TABLE', 'field' => 'GROUP_ID_FIELD'],
				['type' => 'fld', 'table' => 'BASE_TABLE', 'field' => 'GROUP_LABEL_FIELD']
			],
			'order_by' => [
				[
					'element' => $countExpression,
					'direction' => 'DESC'
				]
			],
			'limit' => 5
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function callQuery(array $arguments): array {
		try {
			$query = $this->resolveQuery($arguments);
			$this->prepareQuery($query, true, '$');
			$result = $this->queryService->executeQuery($query);

			return [
				'ok' => true,
				'operation' => self::FN_QUERY,
				'columns' => $result->columns,
				'rows' => $result->rows,
				'count' => count($result->rows),
				'sensitive' => $result->sensitive,
				'limit' => $query['limit'] ?? null
			];
		} catch (\InvalidArgumentException $e) {
			return $this->errorResult(self::FN_QUERY, 'invalid_query', $e->getMessage());
		} catch (\Throwable $e) {
			return $this->errorResult(self::FN_QUERY, 'query_failed', $e->getMessage());
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function describeTable(string $tableName): array {
		$tables = $this->getAllowedTableMap();
		$table = $tables[$tableName] ?? null;

		if (!$table instanceof TableMetadata) {
			$suggestions = [];
			foreach ($this->rankTables($tableName) as $entry) {
				$suggestions[] = $entry['table']->name;
				if (count($suggestions) >= 5) {
					break;
				}
			}

			if (empty($suggestions)) {
				$suggestions = array_slice(array_keys($tables), 0, $this->describeLimit);
			}

			return [
				'ok' => false,
				'operation' => self::FN_DESCRIBE,
				'error' => [
					'code' => 'table_not_available',
					'message' => 'Table is not available in this reporting preset: ' . $tableName . '. Choose an exact table name returned by describe_reporting_data and do not guess physical/source table names.'
				],
				'suggestions' => $suggestions
			];
		}

		return [
			'ok' => true,
			'operation' => self::FN_DESCRIBE,
			'mode' => 'table',
			'table' => $this->tableDetail($table),
			'query_rules' => $this->queryRules(),
			'query_example' => $this->buildQueryExample($table)
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function searchTables(string $search, int $limit): array {
		$ranked = $this->rankTables($search);
		$total = count($ranked);
		$ranked = array_slice($ranked, 0, $limit);
		$tables = [];

		foreach ($ranked as $entry) {
			$tables[] = $this->tableSummary($entry['table'], $search);
		}

		return [
			'ok' => true,
			'operation' => self::FN_DESCRIBE,
			'mode' => $search === '' ? 'catalog' : 'search',
			'search' => $search,
			'available_table_count' => count($this->getAllowedTableMap()),
			'match_count' => $total,
			'truncated' => $total > count($tables),
			'tables' => $tables,
			'next_step' => $search === ''
				? 'Search for a schema concept or choose one exact table name from this catalog.'
				: 'Choose the best exact table name from these candidates, request that table once for full metadata, then execute the reporting query. Do not guess another table name.'
		];
	}

	/**
	 * @return array<int,array{table:TableMetadata,score:int}>
	 */
	private function rankTables(string $search): array {
		$search = $this->lower(trim($search));
		$tokens = $this->searchTokens($search);
		$result = [];

		foreach ($this->getAllowedTableMap() as $table) {
			$score = $search === '' ? 1 : $this->scoreTable($table, $search, $tokens);
			if ($score <= 0) {
				continue;
			}

			$result[] = [
				'table' => $table,
				'score' => $score
			];
		}

		usort($result, function(array $a, array $b): int {
			if ($a['score'] === $b['score']) {
				return strcmp($a['table']->name, $b['table']->name);
			}
			return $b['score'] <=> $a['score'];
		});

		return $result;
	}

	/**
	 * @param string[] $tokens
	 */
	private function scoreTable(TableMetadata $table, string $search, array $tokens): int {
		$tableText = $this->lower(implode(' ', array_filter([
			$table->name,
			$table->label,
			$table->description,
			$table->domain,
			$table->category,
			implode(' ', $table->tags)
		])));

		$score = 0;
		if ($search !== '' && str_contains($tableText, $search)) {
			$score += 100;
		}

		foreach ($tokens as $token) {
			if (str_contains($tableText, $token)) {
				$score += 15;
			}
		}

		foreach ($table->fields as $field) {
			if (!$field instanceof FieldMetadata) {
				continue;
			}

			$fieldText = $this->lower(implode(' ', array_filter([
				$field->name,
				$field->alias,
				$field->description,
				implode(' ', $field->tags)
			])));

			if ($search !== '' && str_contains($fieldText, $search)) {
				$score += 40;
			}
			foreach ($tokens as $token) {
				if (str_contains($fieldText, $token)) {
					$score += 8;
				}
			}
		}

		return $score;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tableSummary(TableMetadata $table, string $search): array {
		$summary = [
			'name' => $table->name,
			'label' => $table->label,
			'description' => $table->description,
			'domain' => $table->domain,
			'category' => $table->category,
			'tags' => array_values($table->tags),
			'sensitive' => $table->sensitive,
			'field_count' => count($table->fields)
		];

		if ($search !== '') {
			$summary['matched_fields'] = $this->matchedFieldSummaries($table, $search, 6);
			$summary['field_names'] = $this->representativeFieldNames($table, $search, 16);
		}

		return $summary;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tableDetail(TableMetadata $table): array {
		$fields = [];
		foreach ($table->fields as $field) {
			if (!$field instanceof FieldMetadata) {
				continue;
			}
			$fields[] = $this->fieldSummary($field);
		}

		$relations = [];
		$allowed = $this->getAllowedTableMap();
		foreach ($table->joins as $join) {
			if (!$join instanceof JoinMetadata || !isset($allowed[$join->targetTable])) {
				continue;
			}
			$relations[] = [
				'target_table' => $join->targetTable,
				'type' => $join->type,
				'default' => (bool)($join->meta['default'] ?? false),
				'on' => $join->on
			];
		}

		return [
			'name' => $table->name,
			'label' => $table->label,
			'description' => $table->description,
			'domain' => $table->domain,
			'category' => $table->category,
			'tags' => array_values($table->tags),
			'sensitive' => $table->sensitive,
			'fields' => $fields,
			'relations' => $relations
		];
	}

	/**
	 * @return string[]
	 */
	private function representativeFieldNames(TableMetadata $table, string $search, int $limit): array {
		$result = [];

		foreach ($this->matchedFieldSummaries($table, $search, $limit) as $field) {
			$name = trim((string)($field['name'] ?? ''));
			if ($name !== '' && !in_array($name, $result, true)) {
				$result[] = $name;
			}
		}

		foreach ($table->fields as $field) {
			if (!$field instanceof FieldMetadata || in_array($field->name, $result, true)) {
				continue;
			}

			$result[] = $field->name;
			if (count($result) >= $limit) {
				break;
			}
		}

		return array_slice($result, 0, $limit);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function matchedFieldSummaries(TableMetadata $table, string $search, int $limit): array {
		$search = $this->lower($search);
		$tokens = $this->searchTokens($search);
		$result = [];

		foreach ($table->fields as $field) {
			if (!$field instanceof FieldMetadata) {
				continue;
			}

			$text = $this->lower(implode(' ', array_filter([
				$field->name,
				$field->alias,
				$field->description,
				implode(' ', $field->tags)
			])));
			$matched = $search !== '' && str_contains($text, $search);
			if (!$matched) {
				foreach ($tokens as $token) {
					if (str_contains($text, $token)) {
						$matched = true;
						break;
					}
				}
			}
			if (!$matched) {
				continue;
			}

			$result[] = $this->fieldSummary($field);
			if (count($result) >= $limit) {
				break;
			}
		}

		return $result;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function fieldSummary(FieldMetadata $field): array {
		return [
			'name' => $field->name,
			'alias' => $field->alias,
			'type' => $field->type,
			'description' => $field->description,
			'primary_key' => $field->primaryKey,
			'foreign_key' => $field->foreignKey !== null,
			'nullable' => $field->nullable,
			'tags' => array_values($field->tags),
			'sensitive' => $field->sensitive
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function queryRules(): array {
		return [
			'read_only' => true,
			'query_type' => 'select',
			'help_topics' => self::HELP_TOPICS,
			'field_entry' => [
				'element' => ['type' => 'fld', 'table' => 'TABLE', 'field' => 'FIELD'],
				'alias' => 'RESULT_COLUMN'
			],
			'filter_example' => [
				'type' => 'op',
				'operator' => '=',
				'params' => [
					['type' => 'fld', 'table' => 'TABLE', 'field' => 'FIELD'],
					'value'
				]
			],
			'operators' => self::ALLOWED_OPERATORS,
			'functions' => self::ALLOWED_FUNCTIONS,
			'notes' => [
				'Use only table and field names returned by this tool.',
				'Omit where and having completely when they are not needed.',
				'Use type=fld for fields, type=fn for functions and type=op for conditions.',
				'Aliases are result-column labels on fields entries. There is no type=alias element. Repeat the underlying field or function expression in group_by, having and order_by.',
				'For exact COUNT/SUM/AVG, group_by and order_by patterns, call describe_reporting_data with topic=aggregations, grouping, sorting or examples.',
				'DataHawk resolves declared table relations automatically when fields from related tables are referenced.',
				'Personal and sensitive fields are queryable when they are present in the configured reporting scope.'
			]
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function buildQueryExample(TableMetadata $table): array {
		$fields = [];
		foreach ($table->fields as $field) {
			if (!$field instanceof FieldMetadata) {
				continue;
			}
			$fields[] = [
				'element' => [
					'type' => 'fld',
					'table' => $table->name,
					'field' => $field->name
				],
				'alias' => $field->name
			];
			if (count($fields) >= 5) {
				break;
			}
		}

		return [
			'type' => 'select',
			'table' => $table->name,
			'fields' => $fields,
			'limit' => min(25, $this->defaultLimit)
		];
	}

	/**
	 * @param array<string,mixed> $query
	 */
	private function prepareQuery(array &$query, bool $root, string $path): void {
		$type = strtolower(trim((string)($query['type'] ?? 'select')));
		if ($type !== 'select') {
			throw new \InvalidArgumentException($path . ': only SELECT queries are allowed.');
		}
		$query['type'] = 'select';
		$this->applyQuerySchemaScope($query, $path);

		$this->validateTechnicalNamespace($query['schema'] ?? null, $path . '.schema');
		$this->validateTechnicalNamespace($query['provider'] ?? null, $path . '.provider');

		$baseTable = $this->queryBaseTable($query);
		if ($baseTable !== null) {
			$this->requireAllowedTable($baseTable, $path . '.table');
		}

		if (array_key_exists('where', $query) && empty($query['where'])) {
			throw new \InvalidArgumentException($path . ': omit where when no filter is required.');
		}
		if (array_key_exists('having', $query) && empty($query['having'])) {
			throw new \InvalidArgumentException($path . ': omit having when no HAVING condition is required.');
		}

		if (isset($query['limit'])) {
			$limit = $this->strictNonNegativeInt($query['limit'], $path . '.limit');
			if ($limit < 1) {
				throw new \InvalidArgumentException($path . '.limit must be at least 1.');
			}
			if ($limit > $this->maxLimit) {
				throw new \InvalidArgumentException($path . '.limit exceeds the configured maximum of ' . $this->maxLimit . '.');
			}
			$query['limit'] = $limit;
		} elseif ($root) {
			$query['limit'] = $this->defaultLimit;
		}

		if (isset($query['offset'])) {
			$query['offset'] = $this->strictNonNegativeInt($query['offset'], $path . '.offset');
		}

		if (isset($query['order_by'])) {
			$this->validateOrderBy($query['order_by'], $path . '.order_by');
		}

		if (isset($query['union'])) {
			if (!is_array($query['union']) || !isset($query['union']['queries']) || !is_array($query['union']['queries']) || count($query['union']['queries']) < 2) {
				throw new \InvalidArgumentException($path . '.union must contain at least two SELECT queries.');
			}
			foreach ($query['union']['queries'] as $index => &$subQuery) {
				if (!is_array($subQuery)) {
					throw new \InvalidArgumentException($path . '.union.queries[' . $index . '] must be a query object.');
				}
				$this->prepareQuery($subQuery, false, $path . '.union.queries[' . $index . ']');
			}
			unset($subQuery);
		} else {
			if (empty($query['fields']) || !is_array($query['fields'])) {
				throw new \InvalidArgumentException($path . ': SELECT query must contain a non-empty fields array.');
			}
		}

		$this->validateStructuredValue($query, $baseTable, $path);
	}

	private function validateStructuredValue(mixed &$value, ?string $baseTable, string $path): void {
		if (!is_array($value)) {
			return;
		}

		$type = isset($value['type']) ? strtolower(trim((string)$value['type'])) : '';
		if ($type !== '') {
			if (in_array($type, self::ALLOWED_ELEMENT_TYPES, true)) {
				$this->validateElement($value, $type, $baseTable, $path);
				if ($type === 'subquery') {
					return;
				}
			} elseif ($type !== 'select') {
				if ($type === 'alias') {
					throw new \InvalidArgumentException(
						$path . ': alias is not an element type. Put alias beside element in a SELECT fields entry. ' .
						'For group_by, having or order_by, repeat the underlying fld/fn expression instead of referencing a result alias.'
					);
				}
				throw new \InvalidArgumentException(
					$path . ': unsupported reporting element type: ' . $type . '. Allowed element types: ' . implode(', ', self::ALLOWED_ELEMENT_TYPES)
				);
			}
		}

		foreach ($value as $key => &$child) {
			if (!is_array($child)) {
				continue;
			}
			$this->validateStructuredValue($child, $baseTable, $path . '.' . (string)$key);
		}
		unset($child);
	}

	/**
	 * @param array<string,mixed> $element
	 */
	private function validateElement(array &$element, string $type, ?string $baseTable, string $path): void {
		switch ($type) {
			case 'fld':
				$field = trim((string)($element['field'] ?? ''));
				if ($field === '') {
					throw new \InvalidArgumentException($path . ': field element requires a field name.');
				}
				$table = trim((string)($element['table'] ?? $baseTable ?? ''));
				if ($table !== '') {
					$this->requireAllowedField($table, $field, $path);
				}
				break;

			case 'fn':
			case 'windowfn':
				$function = strtoupper(trim((string)($element['function'] ?? '')));
				if ($function === '' || !in_array($function, self::ALLOWED_FUNCTIONS, true)) {
					throw new \InvalidArgumentException($path . ': unsupported reporting function: ' . ($function !== '' ? $function : '[empty]'));
				}
				if (!isset($element['params']) || !is_array($element['params'])) {
					throw new \InvalidArgumentException($path . ': function requires a params array.');
				}
				break;

			case 'op':
				$operator = strtoupper(trim((string)($element['operator'] ?? '')));
				if ($operator === '' || !in_array($operator, self::ALLOWED_OPERATORS, true)) {
					throw new \InvalidArgumentException($path . ': unsupported reporting operator: ' . ($operator !== '' ? $operator : '[empty]'));
				}
				if (!isset($element['params']) || !is_array($element['params'])) {
					throw new \InvalidArgumentException($path . ': operator requires a params array.');
				}
				break;

			case 'subquery':
				if (!isset($element['query']) || !is_array($element['query'])) {
					throw new \InvalidArgumentException($path . ': subquery requires a query object.');
				}
				$this->prepareQuery($element['query'], false, $path . '.query');
				break;

			case 'case':
				if (!isset($element['cases']) || !is_array($element['cases']) || $element['cases'] === []) {
					throw new \InvalidArgumentException($path . ': CASE element requires a non-empty cases array.');
				}
				break;
		}
	}

	private function validateOrderBy(mixed $orderBy, string $path): void {
		if (!is_array($orderBy)) {
			throw new \InvalidArgumentException($path . ' must be an array.');
		}

		foreach ($orderBy as $index => $entry) {
			if (!is_array($entry) || !isset($entry['element'])) {
				throw new \InvalidArgumentException($path . '[' . $index . '] requires an element.');
			}
			$direction = strtoupper(trim((string)($entry['direction'] ?? 'ASC')));
			if (!in_array($direction, ['ASC', 'DESC'], true)) {
				throw new \InvalidArgumentException($path . '[' . $index . '] has invalid direction: ' . $direction);
			}
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function resolveQuery(array $arguments): array {
		if (!array_key_exists('query', $arguments)) {
			throw new \InvalidArgumentException('Missing parameter: query');
		}
		if (!is_array($arguments['query'])) {
			throw new \InvalidArgumentException('Parameter query must be a structured object, not a JSON string or scalar.');
		}
		return $arguments['query'];
	}

	private function queryBaseTable(array $query): ?string {
		$table = trim((string)($query['table'] ?? $query['from'] ?? ''));
		return $table !== '' ? $table : null;
	}

	private function applyQuerySchemaScope(array &$query, string $path): void {
		if (trim((string)($query['schema'] ?? $query['provider'] ?? '')) !== '') {
			return;
		}

		$tableName = $this->queryBaseTable($query);
		if ($tableName === null) {
			return;
		}

		$matches = [];
		foreach ($this->schemaProvider->getScopes() as $scope) {
			if ($this->schemaProvider->getTableForScope($scope, $tableName) instanceof TableMetadata) {
				$matches[] = $scope;
			}
		}

		if (count($matches) === 1) {
			$query['schema'] = $matches[0];
			return;
		}

		if (count($matches) > 1) {
			throw new \InvalidArgumentException(
				$path . ': table is available in multiple query schema scopes: ' . $tableName
			);
		}
	}

	private function requireAllowedTable(string $tableName, string $path): TableMetadata {
		$table = $this->getAllowedTableMap()[$tableName] ?? null;
		if (!$table instanceof TableMetadata) {
			throw new \InvalidArgumentException($path . ': table is not available in this reporting preset: ' . $tableName);
		}
		return $table;
	}

	private function requireAllowedField(string $tableName, string $fieldName, string $path): void {
		$table = $this->requireAllowedTable($tableName, $path);
		if ($fieldName === '*') {
			return;
		}

		foreach ($table->fields as $field) {
			if ($field instanceof FieldMetadata && $field->name === $fieldName) {
				return;
			}
		}

		throw new \InvalidArgumentException($path . ': field is not available on table ' . $tableName . ': ' . $fieldName);
	}

	/**
	 * @return array<string,TableMetadata>
	 */
	private function getAllowedTableMap(): array {
		if ($this->allowedTableMap !== null) {
			return $this->allowedTableMap;
		}

		$tables = [];
		foreach ($this->queryService->listTables() as $table) {
			if (!$table instanceof TableMetadata || !$this->matchesScope($table)) {
				continue;
			}
			$tables[$table->name] = $table;
		}
		ksort($tables);

		$this->allowedTableMap = $tables;
		return $this->allowedTableMap;
	}

	private function matchesScope(TableMetadata $table): bool {
		if (!$this->matchesScalarFilter($table->domain, $this->domainFilter)) {
			return false;
		}
		if (!$this->matchesScalarFilter($table->category, $this->categoryFilter)) {
			return false;
		}
		if (!$this->matchesTagFilter($table->tags, $this->tagFilter)) {
			return false;
		}
		if (!$this->matchesScalarFilter($table->name, $this->tableFilter)) {
			return false;
		}
		return true;
	}

	/**
	 * @param string[] $filter
	 */
	private function matchesScalarFilter(string $value, array $filter): bool {
		if ($filter === []) {
			return true;
		}
		foreach ($filter as $allowed) {
			if (strcasecmp($allowed, $value) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string[] $tags
	 * @param string[] $filter
	 */
	private function matchesTagFilter(array $tags, array $filter): bool {
		if ($filter === []) {
			return true;
		}

		$tagSet = [];
		foreach ($tags as $tag) {
			$tagSet[$this->lower((string)$tag)] = true;
		}
		foreach ($filter as $wanted) {
			if (isset($tagSet[$this->lower($wanted)])) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return string[]
	 */
	private function normalizeStringList(mixed $value): array {
		if ($value === null) {
			return [];
		}
		if (is_string($value)) {
			$value = trim($value);
			return $value === '' ? [] : [$value];
		}
		if (!is_array($value)) {
			return [];
		}

		$result = [];
		foreach ($value as $item) {
			if (!is_scalar($item)) {
				continue;
			}
			$item = trim((string)$item);
			if ($item !== '') {
				$result[] = $item;
			}
		}
		return array_values(array_unique($result));
	}

	private function resolveInt(mixed $config, int $default, int $minimum, int $maximum): int {
		$value = $this->resolver->resolveValue($config);
		if ($value === null || $value === '') {
			return $default;
		}
		if (!is_numeric($value)) {
			return $default;
		}
		$value = (int)$value;
		return max($minimum, min($maximum, $value));
	}

	private function resolveCallLimit(mixed $value): int {
		if ($value === null || $value === '') {
			return $this->describeLimit;
		}
		if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
			throw new \InvalidArgumentException('limit must be an integer.');
		}
		return max(1, min(self::MAX_DESCRIBE_LIMIT, (int)$value));
	}

	private function strictNonNegativeInt(mixed $value, string $path): int {
		if (is_int($value)) {
			$result = $value;
		} elseif (is_string($value) && ctype_digit($value)) {
			$result = (int)$value;
		} else {
			throw new \InvalidArgumentException($path . ' must be an integer.');
		}
		if ($result < 0) {
			throw new \InvalidArgumentException($path . ' must not be negative.');
		}
		return $result;
	}

	private function validateTechnicalNamespace(mixed $value, string $path): void {
		if ($value === null || $value === '') {
			return;
		}
		if (!is_string($value) || preg_match('/^[A-Za-z0-9_.-]+$/', $value) !== 1) {
			throw new \InvalidArgumentException($path . ' contains an invalid technical name.');
		}
	}

	/**
	 * @return string[]
	 */
	private function searchTokens(string $search): array {
		if ($search === '') {
			return [];
		}
		$parts = preg_split('/[^\p{L}\p{N}_]+/u', $search) ?: [];
		$result = [];
		foreach ($parts as $part) {
			$part = trim($part);
			if ($part === '' || strlen($part) < 2) {
				continue;
			}
			$result[] = $part;
		}
		return array_values(array_unique($result));
	}

	private function lower(string $value): string {
		return function_exists('mb_strtolower')
			? mb_strtolower($value, 'UTF-8')
			: strtolower($value);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function stringListSchema(string $description): array {
		return [
			'type' => 'array',
			'items' => ['type' => 'string'],
			'uniqueItems' => true,
			'description' => $description,
			'default' => []
		];
	}

	/**
	 * @return array<string,mixed>
	 */
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
}
