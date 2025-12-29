<?php declare(strict_types=1);

namespace MissionBayReporting\MissionBay;

use MissionBay\Api\IAgentMemory;
use MissionBay\Api\IAgentContext;
use MissionBay\Api\IAgentConfigValueResolver;
use MissionBay\Resource\AbstractAgentResource;
use ResourceFoundation\Api\IQuerySchemaProvider;
use ResourceFoundation\Dto\TableMetadata;
use ResourceFoundation\Dto\FieldMetadata;

final class VizionMemoryAgentResource extends AbstractAgentResource implements IAgentMemory {

	private IAgentConfigValueResolver $resolver;
	private IQuerySchemaProvider $schemaProvider;

	private int $priority = 56;

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
		return 'vizionmemoryagentresource';
	}

	public function getDescription(): string {
		return 'Provides Vizion canvas/reporting rules (and a schema-derived example) as a system prompt.';
	}

	public function setConfig(array $config): void {
		parent::setConfig($config);

		$this->priority = (int)($this->resolver->resolveValue($config['priority'] ?? null) ?? 56);

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
		$parts[] = $this->getCanvasPolicyBlock();
		$parts[] = $this->getVizionRulesBlock();
		$parts[] = $this->buildDynamicExampleBlock();

		return trim(implode("\n\n", array_filter($parts)));
	}

	private function getCanvasPolicyBlock(): string {
		return trim(<<<'TXT'
## Canvas Output Policy

* Canvas content is controlled only via UI events: `canvas.open`, `canvas.render`, `canvas.close`.
* Never repeat, mirror, or describe canvas content in the chat.
* After canvas actions (for example after calling `vizion_report_canvas`), reply in chat with one short confirmation sentence only.
* Only when the user explicitly asks for it, summarize or explain canvas content in chat.
TXT);
	}

	private function getVizionRulesBlock(): string {
		return trim(<<<'TXT'
## Vizion Report Tool Rules (`vizion_report_canvas`)

The `vizion_report_canvas` tool executes a DataHawk report and renders the HTML result into the chatbot canvas using a single HTML block.

### Tool Call Shape

You call the tool with arguments like this:

```jsonc
{
  "canvas_id": "main",
  "title": "Report",
  "open": true,
  "config": {
    "message": "Optional user-facing explanation.",
    "type": "datatable",
    "query": { ... }
  }
}
```

### Config Object

The `config` object must be present and must contain at least:

* `type` – the visualization type: `table`, `datatable`, `barchart`, `piechart`
* `query` – a valid DataHawk query object (see DataHawk rules)

`config.query` should be a JSON object, not a JSON string.

### Tool Choice

* If the user wants data explained directly in chat: call `execute_datahawk_query`.
* If the user wants a visual table or chart in the canvas: call `vizion_report_canvas`.

After calling `vizion_report_canvas`, respond in chat with one short confirmation sentence.
TXT);
	}

	private function buildDynamicExampleBlock(): string {
		$table = $this->pickExampleTable();
		if ($table === null) {
			return '';
		}

		$fields = $this->pickExampleFields($table, 4);
		if (empty($fields)) {
			return '';
		}

		$fieldJson = [];
		foreach ($fields as $f) {
			$fieldJson[] = [
				'element' => [
					'type' => 'fld',
					'table' => $table->name,
					'field' => $f->name
				],
				'alias' => $table->name . '_' . $f->name
			];
		}

		$query = [
			'type' => 'select',
			'table' => $table->name,
			'fields' => $fieldJson,
			'limit' => 50
		];

		$payload = [
			'canvas_id' => 'main',
			'title' => ($table->label ?: $table->name),
			'open' => true,
			'config' => [
				'message' => 'Example: an interactive table based on the current schema.',
				'type' => 'datatable',
				'query' => $query
			]
		];

		$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		if (!is_string($json)) {
			return '';
		}

		return trim(
			"## Example (schema-derived)\n\n" .
			"```jsonc\n" . $json . "\n```\n"
		);
	}

	private function pickExampleTable(): ?TableMetadata {
		$tables = $this->schemaProvider->getSchema();
		$tables = $this->filterTables($tables);

		usort($tables, function (TableMetadata $a, TableMetadata $b): int {
			return strcmp($a->name, $b->name);
		});

		foreach ($tables as $table) {
			if ($table->sensitive) {
				continue;
			}
			if (!is_array($table->fields) || count($table->fields) < 1) {
				continue;
			}
			return $table;
		}

		return null;
	}

	/**
	 * @return FieldMetadata[]
	 */
	private function pickExampleFields(TableMetadata $table, int $max): array {
		$out = [];

		/** @var FieldMetadata[] $fields */
		$fields = is_array($table->fields) ? $table->fields : [];

		foreach ($fields as $field) {
			if (!$field instanceof FieldMetadata) {
				continue;
			}
			if ($field->sensitive) {
				continue;
			}
			$out[] = $field;
			if (count($out) >= $max) {
				break;
			}
		}

		return $out;
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
}
