# MissionBayReporting FAQ

## Purpose

This FAQ describes the current MissionBayReporting plugin as implemented in this source package. It focuses on reporting-oriented agent tools, curated Vizion report access, ad-hoc structured reporting queries, canvas rendering, schema-derived agent memory, AgentFlow reporting, configuration, data scope, and security boundaries.

MissionBayReporting is an integration plugin. It does not own the reporting database, the agent runtime, or the browser canvas. It connects MissionBay agent execution to reporting contracts and report services supplied by the surrounding BASE3 composition.

For technical data-processing details, see [../PRIVACY.md](../PRIVACY.md).

## 1. What is MissionBayReporting?

MissionBayReporting adds reporting-specific MissionBay resources and one AgentFlow node.

The current source package contains these discoverable classes:

| Technical name | Class role | Main purpose |
| --- | --- | --- |
| `vizionreportagenttool` | Agent tool | Read-only discovery and execution of curated Vizion reports. |
| `datahawkagenttool` | Agent tool | On-demand reporting schema discovery and structured ad-hoc queries. |
| `vizioncanvasagenttool` | Agent tool | Execute a structured reporting query and render the result into a chatbot canvas. |
| `vizionmemoryagentresource` | Agent memory | Add reporting and canvas guidance plus one schema-derived example to the system prompt. |
| `datahawkreportnode` | AgentFlow node | Execute a structured query and render a report through a discoverable exporter. |
| `missionbayreportingplugin` | BASE3 plugin | Register the plugin instance in the BASE3 container. |

The package intentionally provides several reporting paths because they serve different use cases and have different trust boundaries.

## 2. Which reporting path should be used first?

For model-facing reporting, the preferred order is:

1. use `VizionReportAgentTool` when the question is already represented by a finished Vizion report;
2. use `DataHawkAgentTool` for ad-hoc analytical questions that are not represented by an appropriate finished report;
3. use `VizionCanvasAgentTool` only when a DataHawk-based visual table or chart is explicitly needed in a canvas;
4. use `DataHawkReportNode` for deliberate AgentFlow/report-rendering workflows rather than as a substitute for the validated model-facing query tool.

The distinction matters because the curated Vizion path preserves report semantics, while the ad-hoc DataHawk path validates model-generated query structure directly.

## 3. What does `MissionBayReportingPlugin::init()` do?

The plugin initialization is intentionally small. It registers the plugin object itself as a shared BASE3 service.

It does not:

- create a reporting service registry;
- execute reporting queries;
- load reporting data;
- create database tables;
- seed settings;
- register a second container; or
- perform provider or network calls.

The reporting resources and node are discovered through the normal BASE3 class map.

## 4. Does MissionBayReporting define its own database schema?

No. The current plugin contains no migrations and no repository implementation of its own.

Reporting data, report definitions, query compilation, authorization, and any persistence belong to the concrete services used by the surrounding installation.

## 5. Does MissionBayReporting store reports or query results itself?

No persistent report store is implemented in this package.

The current classes operate on data returned by injected services and return or render results during execution. Any longer-term storage can occur elsewhere, for example in agent conversation memory, tool auditing, logs, reporting backends, or downstream workflow nodes, but those stores are not implemented by MissionBayReporting itself.

## 6. What is `VizionReportAgentTool`?

`VizionReportAgentTool` is the preferred model-facing integration for finished Vizion reports.

It depends on:

```text
Vizion\Api\IReportDataService
MissionBay\Api\IAgentConfigValueResolver
```

The tool does not build a low-level query AST itself. It asks Vizion to describe, search, and execute a configured report.

## 7. Which functions does `VizionReportAgentTool` expose?

It exposes three functions:

```text
describe_vizion_reports
execute_vizion_report
search_vizion_tree
```

All three are declared as read-only, non-mutating, and not requiring approval.

## 8. What does `describe_vizion_reports` return?

The function can operate in two modes.

Without an exact report id, it lists or searches reports in the configured reporting scope. With an exact report id, it returns the report description supplied by `IReportDataService`.

The detailed report description is expected to expose report-facing concepts such as:

- field aliases;
- ordinary filters;
- tree filters;
- sorting capabilities;
- paging defaults; and
- report metadata.

The tool does not intentionally expose the underlying DataHawk query AST as its model-facing contract.

## 9. How is the Vizion reporting scope enforced?

Each `VizionReportAgentTool` instance requires a `reportingscope` configuration value.

When the model supplies an unqualified report id, the tool prefixes the configured scope. If the model supplies a qualified id such as `scope:report`, the prefix must match the configured scope. A report id from a different scope is rejected.

This is a hard tool-level boundary around curated report identifiers.

## 10. How does `execute_vizion_report` validate model arguments?

Before execution, the tool requests the report description and derives allowed field, filter, tree-filter, and sortable-field maps from that description.

It rejects:

- unknown ordinary filter keys;
- unknown tree-filter keys;
- unknown projected fields;
- sort fields that are not configured as sortable; and
- malformed sort structures.

The validated payload is then passed to `IReportDataService::executeReport()`.

## 11. Can the model request arbitrary report fields?

No. When the optional `fields` projection is supplied, every requested field must be a configured report field alias returned by the report description.

Omitting `fields` lets the report service return the report's normal field set.

## 12. How are tree filters handled?

The model should use `search_vizion_tree` to resolve exact node identifiers for a configured tree filter.

The function accepts:

- an exact report id;
- an exact tree-filter key;
- an optional text search; and
- a bounded result limit.

The returned node identifiers can then be passed to `execute_vizion_report.treeFilters`.

## 13. Are internal Vizion row identifiers exposed to the model?

The tool removes row keys whose names begin with `__` from the returned data before the result is handed back to the agent runtime.

This specifically prevents internal row metadata such as `__row_key` from becoming part of the normal model-facing report data result.

This is not a general sensitive-data filter. Ordinary report fields remain visible when the report service returns them.

## 14. What paging limits apply to curated report execution?

The tool schema allows `pageSize` values up to 250.

The exact paging behavior is ultimately implemented by the Vizion report service, but the agent tool communicates and constrains the model-facing page-size contract accordingly.

## 15. Which Vizion display type is currently supported by the headless report service path?

The package documentation identifies `modulargridreportdisplay` as the currently supported headless report-display path.

Other visual display types should not be assumed to be executable through the same agent tool unless their data execution has been extracted behind reusable Vizion services.

## 16. What is `DataHawkAgentTool`?

`DataHawkAgentTool` is the bounded model-facing integration for ad-hoc reporting analysis.

It depends on the neutral ResourceFoundation query contracts:

```text
ResourceFoundation\Api\IQueryService
ResourceFoundation\Api\IScopedQuerySchemaProvider
```

It also uses `IAgentConfigValueResolver` for configurable filters and limits.

## 17. Which functions does `DataHawkAgentTool` expose?

It exposes:

```text
describe_reporting_data
execute_datahawk_query
```

Both functions are declared read-only, non-mutating, and not requiring approval.

## 18. Why is schema discovery performed on demand?

The plugin deliberately avoids injecting the complete reporting schema into every model call.

Instead, the model can search schema metadata only when it needs reporting information. This reduces prompt size and avoids continuously exposing unrelated schema descriptions and field names to the model.

The recommended pattern is:

```text
question
  -> schema search
  -> exact table description
  -> structured query
  -> result rows
```

## 19. Does schema discovery search business data values?

No. `describe_reporting_data` searches metadata such as:

- table names;
- labels;
- descriptions;
- domains;
- categories;
- tags;
- field names;
- field aliases; and
- field descriptions.

It does not run a value search through reporting rows merely because a schema search string was supplied.

## 20. What targeted query help can `describe_reporting_data` return?

The tool supports these help topics:

```text
fields
filters
aggregations
grouping
sorting
pagination
examples
```

A help topic cannot be combined with a schema `search` or exact `table` request in the same call.

## 21. What metadata is returned for reporting tables and fields?

Table summaries can include:

- technical name;
- label;
- description;
- domain;
- category;
- tags;
- sensitivity flag; and
- field count.

Detailed table metadata additionally includes field metadata and declared relations to other allowed tables.

Field metadata can include:

- technical name;
- alias;
- type;
- description;
- primary-key flag;
- foreign-key flag;
- nullable flag;
- tags; and
- sensitivity flag.

## 22. Are sensitive tables or fields automatically hidden?

No. Sensitivity metadata is preserved and returned, but sensitivity is not an automatic exclusion rule in `DataHawkAgentTool`.

If a sensitive table or field is visible through the injected reporting/query composition and passes the configured filters, the model can request it.

The surrounding query service and project authorization therefore remain responsible for deciding what the current caller is actually allowed to query.

## 23. What query types can `DataHawkAgentTool` execute?

Only structured `select` queries are accepted by the model-facing tool.

The validator normalizes the query type to `select` and rejects other root or nested query types.

Raw SQL strings are not part of the tool contract.

## 24. Which structured element types are accepted?

The allowlist is:

```text
fld
fn
op
subquery
case
windowfn
```

Unknown structured element types are rejected recursively.

An `alias` is not an element type. Aliases belong beside a SELECT field expression as result-column labels.

## 25. Which operators are allowed?

The current allowlist is:

```text
=
!=
<>
>
>=
<
<=
AND
OR
IN
NOT IN
IS NULL
IS NOT NULL
BETWEEN
LIKE
NOT LIKE
+
-
*
/
```

An operator outside this list is rejected before the query reaches `IQueryService`.

## 26. Which functions are allowed?

The current function allowlist includes common aggregation, date, string, numeric, conditional, and window functions such as:

```text
COUNT, SUM, AVG, MIN, MAX
DATE, YEAR, MONTH, DAY, DATEDIFF, TIMESTAMPDIFF
LOWER, UPPER, TRIM, CONCAT, SUBSTRING
COALESCE, IFNULL, NULLIF, IF
ROUND, ABS, FLOOR, CEIL
ROW_NUMBER, RANK, DENSE_RANK, LAG, LEAD
```

The source contains the complete allowlist. Functions outside that allowlist are rejected.

## 27. Are nested subqueries and UNIONs validated too?

Yes. Validation recurses through nested structured values.

Subqueries are passed through the same `prepareQuery()` logic. UNIONs must contain at least two query objects, and every branch is prepared and validated recursively.

A nested structure therefore does not bypass the SELECT-only rule or the explicit function/operator allowlists.

## 28. How are table and field names constrained?

The tool builds an allowed table map from the tables visible through `IQueryService::listTables()` after applying the configured domain, category, tag, and table filters.

Referenced base tables must be present in that allowed map. Explicit field references are checked against metadata for the referenced allowed table.

The tool therefore prevents the model from switching to an unrelated table merely by guessing a physical backend name.

## 29. What component filters can be configured for `DataHawkAgentTool`?

The current source supports:

```text
priority
domainFilter
categoryFilter
tagFilter
tableFilter
describeLimit
defaultLimit
maxLimit
```

The filter arrays and numeric settings are resolved through `IAgentConfigValueResolver`.

An empty domain, category, tag, or table filter means no additional restriction for that dimension.

## 30. What query result limits are enforced?

The current defaults are:

```text
defaultLimit = 100
maxLimit = 1000
```

If a root query omits a limit, the configured default is added. An explicit limit above the configured maximum is rejected. Offset values must be non-negative integers.

The configuration can raise the maximum up to the implementation's numeric clamp, so deployments should choose a value appropriate for data volume, response size, and model exposure.

## 31. Does `DataHawkAgentTool` expose generated SQL to the model?

Not in its successful normal query result.

The result contains:

```text
columns
rows
count
sensitive
limit
```

The `debugSql` value available on the underlying `QueryResult` is intentionally not copied into this model-facing response.

## 32. Can error messages still expose backend details?

Potentially yes.

Both model-facing tools convert caught exceptions into structured error results that include `Throwable::getMessage()`.

If an underlying service includes SQL fragments, table names, paths, provider details, or other sensitive diagnostics in an exception message, those details can become visible to the agent runtime and potentially the configured language model.

Backends should therefore avoid placing secrets or unnecessary sensitive data in exception messages.

## 33. Important current-source note: does `DataHawkAgentTool` currently have a `reportingscope` option?

No. The current PHP implementation in this package does not define a `reportingscope` property in `DataHawkAgentTool::getSchema()` and does not depend on `IReportingScopeRegistry`.

This differs from parts of the existing README and older package documentation that describe a required DataHawk `reportingscope` setting.

In the current source, visible tables come from the injected `IQueryService`, optional component filters, and the injected `IScopedQuerySchemaProvider` used for technical schema resolution.

This FAQ follows the implementation in the current source package.

## 34. How is a technical query schema selected in the current `DataHawkAgentTool` implementation?

If the structured query already contains `schema` or `provider`, the tool validates the technical name format and leaves the supplied value in place.

If neither is supplied and a base table is present, the tool asks `IScopedQuerySchemaProvider` which scopes contain that table name:

- if exactly one scope contains the table, that scope is inserted into `query.schema`;
- if more than one scope contains the table, the query is rejected as ambiguous;
- if no scope contains the table, no automatic schema is inserted.

Because table discovery in the current class is keyed primarily by table name, installations should avoid ambiguous local table names across visible scopes unless the calling flow supplies an unambiguous technical schema.

## 35. What is `VizionCanvasAgentTool`?

`VizionCanvasAgentTool` is a separate visualization integration for rendering structured query results into a chatbot canvas.

It is not the same security contract as `VizionReportAgentTool` or `DataHawkAgentTool`.

The function name is:

```text
vizion_report_canvas
```

## 36. What does the canvas tool accept?

It accepts:

- `canvas_id`;
- `title`;
- `open`; and
- `config`.

`config` must contain at least:

```text
type
query
```

The implementation accepts `config` either as an array or as a JSON string.

## 37. Does the canvas tool apply the same read-only query validation as `DataHawkAgentTool`?

No.

The current `VizionCanvasAgentTool` checks that `config.query` is an array, then passes it directly to `IQueryService::executeQuery()`.

It does not call the `DataHawkAgentTool` validator and does not independently enforce the SELECT-only element/function/operator allowlists.

This makes the configured `IQueryService`, the tool assignment, and the surrounding agent policy important trust boundaries for this older visualization path.

## 38. Which report renderers can the canvas tool select?

The mapping is:

| Requested type | Exporter technical name |
| --- | --- |
| `table` | `htmltablereportexporter` |
| `datatable` | `datatablereportexporter` |
| `piechart` | `piechartreportexporter` |
| `barchart` | `barchartreportexporter` |
| other | `htmltablereportexporter` |

The exporter is resolved through `IClassMap` as an implementation of `IReportExporter`.

## 39. How is canvas content delivered?

The tool expects an `eventstream` variable in the agent context.

When available and connected, it can emit:

```text
canvas.open
canvas.render
```

The render event replaces the target canvas with one HTML block containing the exporter result.

## 40. Is the canvas HTML sanitized?

The current event payload sets:

```text
sanitize = false
```

The source comment states that the report exporter is expected to generate trusted HTML.

This is an explicit trust assumption. If exporter output can contain untrusted HTML, unsafely rendered data values, or active markup, the boundary must be addressed at the exporter or canvas-rendering layer rather than hidden with an additional fallback path.

## 41. Does the canvas tool expose generated SQL?

Yes. A successful tool call returns:

```text
ok
canvas_id
sql
columns
```

The `sql` value comes from `QueryResult::debugSql`.

That makes the canvas path diagnostically richer, but it also means SQL text and query literals can enter the agent tool result.

## 42. What is `VizionMemoryAgentResource`?

`VizionMemoryAgentResource` is an `IAgentMemory` implementation used as a system-prompt contributor.

Despite the memory interface, it does not store conversation history. `appendNodeHistory()` and `resetNodeHistory()` are no-ops, and `setFeedback()` returns `false`.

`loadNodeHistory()` returns one generated system message containing reporting rules and a schema-derived example.

## 43. Does the memory resource place real reporting rows into the prompt?

No. Its dynamic example is built from schema metadata, not from executed business rows.

It selects one non-sensitive table and up to four non-sensitive fields, then constructs an example query payload using their technical names.

Tables or fields marked `sensitive` are skipped for this generated example.

## 44. Can the memory example be filtered?

Yes. The current resource accepts configurable:

```text
priority
domainFilter
categoryFilter
tagFilter
```

These filters affect selection of the schema-derived example.

## 45. What is `DataHawkReportNode`?

`DataHawkReportNode` is a lower-level MissionBay AgentFlow node.

Its technical name is:

```text
datahawkreportnode
```

It accepts one required `config` string containing JSON with at least `type` and `query`.

## 46. What does the report node return?

On success it can return:

```text
response
report
columns
sql
```

The optional `message` from the input config becomes `response`. The report exporter produces the `report` string. `sql` is taken from `QueryResult::debugSql`.

## 47. Does `DataHawkReportNode` have the same model-facing validator as `DataHawkAgentTool`?

No.

The node verifies that `query` is an array, but it does not apply the explicit SELECT-only allowlist and field/table validation implemented by `DataHawkAgentTool`.

It passes the structured query directly to `IQueryService::executeQuery()`.

For direct LLM query generation, the validated agent tool is therefore the safer interface. The report node should be used only where the AgentFlow definition and query source are appropriately trusted.

## 48. Does MissionBayReporting perform user authorization itself?

Not as a general user/RBAC layer.

The plugin controls report identifiers, structured query shape, component filters, and some model-facing argument validation. It does not independently decide whether a particular authenticated user may see a particular business row.

For curated reports, `VizionReportAgentTool` deliberately delegates execution to `IReportDataService` so the normal Vizion report and backend security path can apply.

For ad-hoc queries, the active query service and project composition must expose only the data the caller is permitted to access.

## 49. Are `readOnlyHint`, `mutation=false`, and `requiresApproval=false` authorization controls?

No.

These are tool metadata for agent orchestration. They describe intended behavior and approval semantics.

They do not replace backend authorization, reporting scope restrictions, row-level filtering, or access checks.

## 50. Does MissionBayReporting call an LLM provider directly?

No direct model-provider client exists in this package.

However, tool definitions, schema metadata, tool arguments, query results, report data, errors, and SQL returned by certain paths can become part of the MissionBay agent turn. MissionBay may then send that content to the configured model provider according to the active agent runtime.

That model-provider boundary must therefore be considered when deciding which reporting fields may be exposed through these tools.

## 51. Does MissionBayReporting make direct HTTP requests?

The current package does not contain its own HTTP client or external network integration.

Any network boundary is introduced by injected services or the wider runtime, for example a remote reporting backend, a model provider, or a browser event-stream transport.

## 52. Does MissionBayReporting use browser storage?

No browser-side JavaScript or storage implementation is present in this package.

The canvas tool publishes events to an existing event stream. Any browser state, canvas persistence, caching, or download behavior belongs to the consuming UI implementation.

## 53. Does MissionBayReporting write application logs?

No logger is injected into the current plugin classes, and the package does not directly write log entries.

Underlying query services, report services, exporters, agent runtimes, HTTP infrastructure, or model-provider clients may log their own activity.

## 54. Are query results cached by MissionBayReporting?

The package contains no persistent result cache.

`DataHawkAgentTool` keeps its computed allowed-table map in memory on the resource instance and resets that map when its configuration is set. This is schema metadata, not business result rows.

## 55. What should be reviewed before enabling reporting tools for an agent?

At minimum, verify:

1. the injected reporting/query service enforces the intended user and data scope;
2. the agent is given only the reporting components it actually needs;
3. sensitive tables and fields are excluded at the backend or component scope when they are not required;
4. `defaultLimit` and `maxLimit` are appropriate for the model and data volume;
5. exception messages do not leak secrets or unnecessary backend details;
6. the direct-query canvas and report-node paths are used only where their weaker validation boundary is acceptable;
7. exporter HTML is trusted before canvas rendering with `sanitize=false`;
8. SQL exposure through canvas/report-node outputs is acceptable;
9. the configured model provider is allowed to process the resulting report data; and
10. conversation, tool-audit, provider, and infrastructure retention policies are documented outside this plugin where those systems persist the results.

## 56. Which package documentation should be read next?

Use the existing detailed documents for implementation-specific topics:

- [overview.md](overview.md)
- [vizion-report-agent-tool.md](vizion-report-agent-tool.md)
- [datahawk-agent-tool.md](datahawk-agent-tool.md)
- [query-contract-and-security.md](query-contract-and-security.md)
- [configuration.md](configuration.md)
- [vizion-integration.md](vizion-integration.md)
- [report-node.md](report-node.md)
- [api-reference.md](api-reference.md)

Where an older package document differs from the PHP source, the current source code is the authoritative description of runtime behavior.
