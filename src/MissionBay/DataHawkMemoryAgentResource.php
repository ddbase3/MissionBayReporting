<?php declare(strict_types=1);

namespace MissionBayReporting\MissionBay;

use MissionBay\Api\IAgentMemory;
use MissionBay\Api\IAgentContext;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Resource\AbstractAgentResource;
use ResourceFoundation\Api\IQuerySchemaProvider;
use ResourceFoundation\Dto\TableMetadata;
use ResourceFoundation\Dto\FieldMetadata;
use ResourceFoundation\Dto\JoinMetadata;

final class DataHawkMemoryAgentResource extends AbstractAgentResource implements IAgentMemory {

	private IAgentConfigValueResolver $resolver;
	private IQuerySchemaProvider $schemaProvider;

	private int $priority = 55;

	/** @var string[] */
	private array $domainFilter = [];

	/** @var string[] */
	private array $categoryFilter = [];

	/** @var string[] */
	private array $tagFilter = [];

	public function __construct(
		IAgentConfigValueResolver $resolver,
		IQuerySchemaProvider $schemaProvider,
		?string $id = null
	) {
		parent::__construct($id);
		$this->resolver = $resolver;
		$this->schemaProvider = $schemaProvider;
	}

	public static function getName(): string {
		return 'datahawkmemoryagentresource';
	}

	public function getDescription(): string {
		return 'Provides DataHawk structured query rules and the generated schema as a system prompt.';
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$this->priority = (int)($this->resolver->resolveValue($config['priority'] ?? null) ?? 55);

		$this->domainFilter = $this->normalizeStringList($this->resolver->resolveValue($config['domainFilter'] ?? null));
		$this->categoryFilter = $this->normalizeStringList($this->resolver->resolveValue($config['categoryFilter'] ?? null));
		$this->tagFilter = $this->normalizeStringList($this->resolver->resolveValue($config['tagFilter'] ?? null));
	}

	// ------- IAgentMemory -------

	public function loadNodeHistory(string $nodeId): array {
		return [[
			'role' => 'system',
			'content' => $this->buildPrompt()
		]];
	}

	public function appendNodeHistory(string $nodeId, array $message): void {
		// no-op
	}

	public function setFeedback(string $nodeId, string $messageId, ?string $feedback): bool {
		return false;
	}

	public function resetNodeHistory(string $nodeId): void {
		// no-op
	}

	public function getPriority(): int {
		return $this->priority;
	}

	// ------- Prompt Builder -------

	private function buildPrompt(): string {
		$parts = [];
		$parts[] = $this->getDataHawkRulesBlock();
		$parts[] = $this->getSchemaReminderBlock();
		$parts[] = $this->renderSchemaBlock();

		return trim(implode("\n\n", array_filter($parts)));
	}

	private function getDataHawkRulesBlock(): string {
		return trim(<<<'TXT'
## DataHawk Structured Query Rules

You generate structured JSON queries for DataHawk. These queries can be used:

* Directly via `execute_datahawk_query({ "query": <QUERY_OBJECT> })`, or
* Inside a Vizion config as `"query": <QUERY_OBJECT>`.

### Query Structure

Each query is a JSON object, for example:

```json
{
  "type": "select",
  "table": "some_table",
  "fields": [ ... ],
  "where": { ... },
  "order_by": [ ... ],
  "group_by": [ ... ],
  "limit": 10,
  "offset": 0,
  "distinct": false
}
```

### Allowed Element Types

Only these element types are allowed:

* `"fld"` – table field  
  `{ "type": "fld", "table": "some_table", "field": "name" }`

* `"op"` – operator (`=`, `>`, `<`, `AND`, `OR`, `IN`, etc.)  
  `{ "type": "op", "operator": "=", "params": [ <lhs>, <rhs> ] }`

* `"fn"` – function (`count`, `avg`, `sum`, `group_concat`, etc.)
* `"subquery"` – nested query with the same structure
* Scalars – strings, numbers or booleans used directly (there is no `"scalar"` type).

### Fields Array

Each entry in `fields` has this form:

```json
{
  "element": { "type": "fld", "table": "some_table", "field": "some_field" },
  "alias": "some_alias"
}
```

* `alias` is optional but strongly recommended.
* Always provide aliases for functions, complex expressions, or calculated columns.

### Operators

Example with nested conditions:

```json
{
  "type": "op",
  "operator": "AND",
  "params": [
    {
      "type": "op",
      "operator": "=",
      "params": [
        { "type": "fld", "table": "t1", "field": "flag" },
        true
      ]
    },
    {
      "type": "op",
      "operator": "=",
      "params": [
        { "type": "fld", "table": "t2", "field": "name" },
        "Example"
      ]
    }
  ]
}
```

* `AND` and `OR` accept an array of conditions in `params`.

### Functions

```json
{
  "element": {
    "type": "fn",
    "function": "count",
    "params": [
      { "type": "fld", "table": "some_table", "field": "id" }
    ]
  },
  "alias": "row_count"
}
```

* Aggregate functions may require a matching `group_by`.
* `group_by` is an array of `"fld"` elements.
* `order_by` is an array of objects like:

```json
{
  "element": { "type": "fld", "table": "some_table", "field": "name" },
  "direction": "asc"
}
```

* `distinct` can be set to `true` on the query level.

### Joins

* You only specify tables via fields and the `table` attribute.
* Do not describe joins explicitly.
* DataHawk resolves joins automatically based on the known schema.

### Query Safety Rules

* If there is no filter, the `where` property must be omitted entirely. Do not send `"where": {}` or `"where": null`.
* The same applies to `having`: if there is no having-condition, omit `having` entirely.
* Only include `group_by` and `order_by` when they contain at least one valid element.
* Every query must contain at least one valid field in `fields`.

### Key markers

* `PK` = Primary Key
* `FK` = Foreign Key
* `NULLABLE` = can be null
* `SENSITIVE` = contains personal or time-sensitive information
TXT);
	}

	private function getSchemaReminderBlock(): string {
		return trim(<<<'TXT'
## Schema Reminder

IMPORTANT:
Use only the tables and fields listed below.
Use field names exactly as specified. Do not invent additional fields.
If a requested piece of information cannot be expressed with this schema, reformulate the query or state it is not possible with the current schema.

Type codes:

* `int` = integer
* `str` = string / varchar
* `text` = text
* `bool` = boolean
* `dt` = datetime
TXT);
	}

	private function renderSchemaBlock(): string {
		$tables = $this->schemaProvider->getSchema();
		$tables = $this->filterTables($tables);

		usort($tables, function (TableMetadata $a, TableMetadata $b): int {
			return strcmp($a->name, $b->name);
		});

		$out = [];
		$out[] = '## Allowed Tables and Fields';
		$out[] = '';

		foreach ($tables as $table) {
			$out[] = $this->renderSingleTable($table);
			$out[] = '';
		}

		return trim(implode("\n", $out));
	}

	private function renderSingleTable(TableMetadata $table): string {
		$label = $table->label ? ' — ' . $table->label : '';
		$lines = [];
		$lines[] = '### Table: `' . $table->name . '`' . $label;

		if (!empty($table->description)) {
			$lines[] = '';
			$lines[] = '**Description:** ' . trim((string)$table->description);
		}

		$metaBits = [];
		if (!empty($table->domain)) {
			$metaBits[] = 'domain: ' . $table->domain;
		}
		if (!empty($table->category)) {
			$metaBits[] = 'category: ' . $table->category;
		}
		if (!empty($table->tags)) {
			$metaBits[] = 'tags: ' . implode(', ', array_values($table->tags));
		}
		if ($table->sensitive) {
			$metaBits[] = 'SENSITIVE';
		}

		if (!empty($metaBits)) {
			$lines[] = '';
			$lines[] = '**Meta:** ' . implode(' | ', $metaBits);
		}

		$lines[] = '';
		$lines[] = '**Fields:**';

		/** @var FieldMetadata[] $fields */
		$fields = is_array($table->fields) ? $table->fields : [];
		foreach ($fields as $field) {
			$lines[] = $this->renderFieldLine($field);
		}

		/** @var JoinMetadata[] $joins */
		$joins = is_array($table->joins) ? $table->joins : [];
		if (!empty($joins)) {
			$lines[] = '';
			$lines[] = '**Relations:**';
			foreach ($joins as $join) {
				$lines[] = $this->renderJoinLine($table->name, $join);
			}
		}

		return implode("\n", $lines);
	}

	private function renderFieldLine(FieldMetadata $field): string {
		$type = $this->mapType($field->type);

		$markers = [];
		if ($field->primaryKey) {
			$markers[] = 'PK';
		}
		if ($field->foreignKey !== null) {
			$markers[] = 'FK';
		}
		if ($field->nullable) {
			$markers[] = 'NULLABLE';
		}
		if ($field->sensitive) {
			$markers[] = 'SENSITIVE';
		}

		$markerText = '';
		if (!empty($markers)) {
			$markerText = ', ' . implode(', ', $markers);
		}

		$desc = '';
		if (!empty($field->description)) {
			$desc = ' — ' . trim((string)$field->description);
		}

		return '* `' . $field->name . '` (' . $type . $markerText . ')' . $desc;
	}

	private function renderJoinLine(string $sourceTable, JoinMetadata $join): string {
		$type = strtoupper(trim((string)$join->type));
		$default = (bool)($join->meta['default'] ?? false);

		$onParts = [];
		foreach ((array)$join->on as $left => $right) {
			$onParts[] = $left . ' = ' . $right;
		}

		$onText = !empty($onParts) ? ' on ' . implode(', ', $onParts) : '';
		return '* `' . $sourceTable . '` -> `' . $join->targetTable . '` (' . $type . ', default: ' . ($default ? 'true' : 'false') . ')' . $onText;
	}

	// ------- Filtering -------

	/**
	 * @param TableMetadata[] $tables
	 * @return TableMetadata[]
	 */
	private function filterTables(array $tables): array {
		$out = [];

		foreach ($tables as $table) {
			if (!$table instanceof TableMetadata) {
				continue;
			}

			if (!$this->matchFilter($table->domain, $this->domainFilter)) {
				continue;
			}
			if (!$this->matchFilter($table->category, $this->categoryFilter)) {
				continue;
			}
			if (!$this->matchTags($table->tags, $this->tagFilter)) {
				continue;
			}

			$out[] = $table;
		}

		return $out;
	}

	/**
	 * @param string[] $filter
	 */
	private function matchFilter(string $value, array $filter): bool {
		if (empty($filter)) {
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
	private function matchTags(array $tags, array $filter): bool {
		if (empty($filter)) {
			return true;
		}
		if (empty($tags)) {
			return false;
		}

		$tagSet = [];
		foreach ($tags as $t) {
			$tagSet[strtolower((string)$t)] = true;
		}

		foreach ($filter as $wanted) {
			if (isset($tagSet[strtolower($wanted)])) {
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
			if ($value === '') {
				return [];
			}
			return [$value];
		}

		if (is_array($value)) {
			$out = [];
			foreach ($value as $v) {
				if (!is_scalar($v)) {
					continue;
				}
				$s = trim((string)$v);
				if ($s !== '') {
					$out[] = $s;
				}
			}
			return $out;
		}

		return [];
	}

	private function mapType(string $type): string {
		$t = strtolower(trim($type));

		return match ($t) {
			'integer', 'int' => 'int',
			'string', 'varchar' => 'str',
			'text' => 'text',
			'boolean', 'bool' => 'bool',
			'datetime', 'date', 'timestamp' => 'dt',
			default => 'str'
		};
	}
}
