# MissionBayReporting Privacy and Data Processing

> This document describes the technical data-processing behavior visible in the current MissionBayReporting source package. It is not a legal privacy notice and does not define the controller, processor, legal basis, retention obligation, or contractual terms of a concrete installation. Reporting backends, model providers, host authorization, logging, conversation storage, tool auditing, and browser infrastructure must be assessed in the actual runtime composition.

## 1. Scope

MissionBayReporting connects MissionBay agents and AgentFlow execution to reporting services.

Depending on the selected resource, it can process:

- report definitions and schema metadata;
- report search terms;
- structured query definitions;
- ordinary report filters;
- tree-filter selections;
- sorting and paging parameters;
- reporting rows and column metadata;
- sensitivity metadata;
- rendered report HTML;
- generated/debug SQL in selected legacy or visualization paths;
- report and query errors; and
- schema-derived system-prompt content.

The plugin itself does not define the underlying business database or its authorization model.

For functional details, see [docs/faq.md](docs/faq.md).

## 2. Main technical data-flow model

The current package contains four important data-flow patterns.

### 2.1 Curated Vizion report path

```text
agent/model
  -> VizionReportAgentTool
  -> Vizion IReportDataService
  -> configured report definition
  -> reporting/query backend
  -> report rows and metadata
  -> VizionReportAgentTool
  -> MissionBay agent runtime
  -> model/provider as part of the tool turn
```

This path is intended to preserve the semantics and security constraints of the finished report.

### 2.2 Ad-hoc DataHawk query path

```text
agent/model
  -> DataHawkAgentTool
  -> schema metadata discovery
  -> validated structured SELECT query
  -> ResourceFoundation IQueryService
  -> rows, columns, sensitivity metadata
  -> MissionBay agent runtime
  -> model/provider as part of the tool turn
```

### 2.3 Canvas rendering path

```text
agent/model
  -> VizionCanvasAgentTool
  -> structured query
  -> IQueryService
  -> QueryResult
  -> IReportExporter
  -> rendered HTML
  -> eventstream
  -> browser canvas

  plus tool result:
  -> debug SQL and column metadata
  -> MissionBay agent runtime
```

### 2.4 AgentFlow report-node path

```text
AgentFlow input
  -> DataHawkReportNode
  -> structured query
  -> IQueryService
  -> QueryResult
  -> IReportExporter
  -> rendered report, columns and debug SQL
  -> downstream AgentFlow nodes
```

These paths have different validation and exposure characteristics and should not be treated as interchangeable security boundaries.

## 3. Data categories processed by the plugin

### 3.1 Reporting schema metadata

Schema discovery can expose technical metadata such as:

- table names;
- labels;
- descriptions;
- domains;
- categories;
- tags;
- field names;
- field aliases;
- field descriptions;
- key metadata;
- relation metadata; and
- table/field sensitivity flags.

This metadata is usually less sensitive than business row data, but it can still reveal internal data models, business terminology, entity relationships, or the existence of sensitive fields.

### 3.2 Report definitions

Curated report discovery and description can expose report-facing metadata such as:

- report identifiers;
- labels and descriptions;
- available fields;
- filter keys;
- tree-filter keys;
- sortable fields;
- paging defaults; and
- report scope identifiers.

Report descriptions should therefore be treated as application metadata rather than assumed to be public information.

### 3.3 Search terms

The plugin can process search strings for:

- reporting schema discovery;
- Vizion report discovery;
- report-wide search; and
- tree-node search.

Users or models can place personal data into these strings, for example a person's name or organizational term, even when schema search is intended for metadata concepts.

Schema discovery itself does not search business values, but the raw search string still passes through the tool and agent runtime.

### 3.4 Structured query definitions

A DataHawk-style query can contain:

- table and field names;
- filter values;
- dates;
- identifiers;
- grouping definitions;
- expressions;
- sort keys;
- limits and offsets;
- subqueries; and
- UNION branches.

Filter literals can themselves contain personal or confidential information.

### 3.5 Report filters and tree selections

Curated report execution can process:

- ordinary filter values;
- report-wide search strings;
- category or hierarchy node identifiers;
- tree search strings;
- field projections;
- sorting instructions; and
- paging state.

These values can reveal what a user is investigating even when the returned report rows are not retained by this plugin.

### 3.6 Reporting result rows

Result rows can contain any data exposed by the active reporting backend, including personal data, organizational data, performance metrics, identifiers, communication data, or other domain content.

MissionBayReporting does not automatically pseudonymize result values before they become tool output.

### 3.7 Column and sensitivity metadata

Query results can contain column definitions and a result-level `sensitive` flag.

Sensitivity metadata is descriptive. It does not itself remove, redact, encrypt, or deny access to the corresponding data.

### 3.8 Rendered HTML

The canvas and report-node paths can render query results into HTML or other exporter-defined strings.

The rendered content can contain all values present in the query result and can therefore be as sensitive as the underlying report rows.

### 3.9 SQL diagnostics

`VizionCanvasAgentTool` and `DataHawkReportNode` return `QueryResult::debugSql`.

Depending on the query compiler, debug SQL can contain:

- physical table names;
- physical column names;
- joins;
- filter literals;
- identifiers;
- names;
- date ranges; and
- other values derived from the query.

Debug SQL must therefore be treated as potentially sensitive diagnostic data.

## 4. MissionBayReporting does not own persistent business storage

The current plugin contains:

- no database migration provider;
- no local business-data repository;
- no message/history table;
- no file store;
- no vector store; and
- no dedicated state-store keys.

Its reporting rows and rendered outputs are processed in memory during a call.

This does not mean the data is necessarily ephemeral across the whole system. Tool results can be retained by other parts of the runtime, including conversation memory, tool auditing, provider logs, HTTP infrastructure, or downstream flow nodes.

## 5. Model-provider exposure

MissionBayReporting does not call a language-model provider directly.

However, the plugin is specifically designed to produce MissionBay tool results. Tool definitions, tool arguments, schema metadata, report descriptions, query rows, error messages, and selected diagnostic outputs can become part of the agent turn.

The MissionBay runtime can then send that information to the configured model provider.

This means the effective privacy boundary is:

```text
reporting backend
  -> MissionBayReporting tool result
  -> MissionBay runtime
  -> configured model provider
```

A deployment must therefore make sure that the reporting data exposed through these tools is appropriate for the configured model-processing boundary.

## 6. Curated Vizion report privacy boundary

`VizionReportAgentTool` is the preferred path for finished reports because it delegates execution to `IReportDataService`.

The agent tool:

- enforces one configured report scope;
- validates report-facing field, filter, and tree-filter keys;
- uses the report service for actual execution; and
- strips result keys beginning with `__` before returning rows to the agent.

It does not independently reimplement row-level authorization.

The effective authorization boundary remains the Vizion report service and the underlying query/backend composition.

## 7. Report-scope enforcement

A configured `VizionReportAgentTool` instance stores one `reportingscope` value.

Unqualified report ids are prefixed with that scope. Qualified report ids are rejected if their prefix differs from the configured scope.

This prevents an agent from switching to a different curated reporting scope by naming a foreign scoped report id.

This scope check applies to the curated Vizion tool. It is not a general authorization mechanism for the entire plugin.

## 8. Curated report argument validation

Before report execution, `VizionReportAgentTool` validates model-facing argument keys against the current report description.

It checks:

- ordinary filter keys;
- tree-filter keys;
- requested field aliases; and
- sortable field aliases.

This reduces accidental access to unconfigured report dimensions and prevents the model from inventing report-facing keys.

Actual filter values are still processed by the report service and can contain personal or confidential values.

## 9. Internal Vizion row metadata removal

`VizionReportAgentTool` removes any returned row key beginning with `__` before exposing report rows to the agent.

This protects internal row metadata from the normal model-facing report result.

It is not a generic privacy sanitizer. A configured ordinary report field remains in the result unless the caller projected it out or the report service omitted it.

## 10. DataHawk model-facing read-only boundary

`DataHawkAgentTool` applies the strongest explicit query-shape validation in this package.

It enforces:

- SELECT-only query type;
- structured query objects rather than raw SQL strings;
- an allowlist of element types;
- an allowlist of functions;
- an allowlist of operators;
- allowed-table checks;
- allowed-field checks for explicit field references;
- recursive validation of nested subqueries and UNION branches;
- bounded result limits; and
- technical-name validation for schema/provider names.

This is a query-shape and scope boundary, not a substitute for backend data authorization.

## 11. DataHawk component filters

The current source supports optional component-level filters:

```text
domainFilter
categoryFilter
tagFilter
tableFilter
```

They restrict the metadata and tables visible to the model-facing tool instance.

Empty arrays mean no additional restriction for that dimension.

These filters can be useful for data minimization, but they should not be the only authorization mechanism when access depends on the current user or row-level permissions.

## 12. Sensitivity metadata is not automatic redaction

ResourceFoundation metadata can mark a table or field as sensitive.

`DataHawkAgentTool` preserves these flags in discovery and result metadata, but it does not automatically exclude every sensitive field.

Therefore:

```text
sensitive=true
```

means "this data is marked sensitive", not "this data cannot be queried".

If sensitive data must not reach the model, the active query/reporting scope must exclude it or the backend must enforce the appropriate authorization and projection.

## 13. Current source discrepancy around DataHawk `reportingscope`

The current `DataHawkAgentTool` PHP implementation does not expose a `reportingscope` configuration property and does not inject `IReportingScopeRegistry`.

Some existing package documentation still describes such a setting. That description does not match the current class implementation.

The current source obtains visible tables from the injected `IQueryService`, applies optional domain/category/tag/table filters, and uses `IScopedQuerySchemaProvider` for technical schema resolution.

Privacy reviews must use the actual runtime composition rather than assuming an additional reporting-scope boundary that is not present in the current class.

## 14. Technical schema resolution and ambiguity

If a DataHawk query does not contain `schema` or `provider`, the current tool checks which technical schema scopes contain the base table.

Exactly one match is inserted automatically. Multiple matches cause an error.

This behavior can reveal technical scope names through error messages, and ambiguous local table names should be avoided or explicitly resolved in trusted configuration/calling logic.

## 15. Schema metadata exposure to the model

`describe_reporting_data` deliberately returns only selected schema metadata on demand instead of injecting the complete schema into every prompt.

This reduces the amount of internal metadata continuously exposed to the model provider.

However, once requested, table/field names, descriptions, tags, relations, and sensitivity markers become part of the tool result and may be transmitted to the configured model provider.

Descriptions and labels should therefore not contain secrets.

## 16. Schema search is metadata-only but search text can still be sensitive

A schema search is ranked against metadata and does not query business row values.

Nevertheless, a user or model can enter a person's name or another sensitive value as the search string. That string can still be included in MissionBay tool arguments, tool audits, conversation context, or provider calls even though it is not used to search row data.

Users should be encouraged to search by business concepts such as "course", "invoice", or "certificate" rather than by personal values when discovering schema.

## 17. Query result limits and data minimization

The model-facing DataHawk tool adds a default root limit when one is omitted and rejects an explicit limit above the configured maximum.

Defaults in the current source are:

```text
defaultLimit = 100
maxLimit = 1000
```

Deployments can configure other values.

A smaller limit can materially reduce unnecessary data transfer to the model. Increasing limits should be treated as a privacy and cost decision, not only as a performance decision.

## 18. Generated SQL in the validated DataHawk path

A successful `execute_datahawk_query` result intentionally omits `QueryResult::debugSql`.

This reduces exposure of backend physical structure and literal query values in the normal model-facing ad-hoc reporting path.

The query result still contains rows, columns, count, sensitivity metadata, and the effective limit.

## 19. Exception and error-message exposure

Both `DataHawkAgentTool` and `VizionReportAgentTool` convert caught exceptions into structured tool errors containing the exception message.

The canvas tool and report node also return exception messages in their error results.

An underlying backend can therefore unintentionally expose:

- SQL fragments;
- connection or provider details;
- physical table names;
- file paths;
- identifiers; or
- sensitive values

if those details are embedded in exception messages.

Backends used with agent-facing reporting should return controlled diagnostic messages and keep secrets out of exceptions.

## 20. Canvas query path has a weaker validation boundary

`VizionCanvasAgentTool` does not invoke the `DataHawkAgentTool` query validator.

The implementation validates only that the report config contains a `type` and an array-valued `query`, then calls:

```text
IQueryService::executeQuery(query)
```

directly.

Accordingly, the actual query capability of the canvas path is whatever the injected `IQueryService` accepts.

The canvas tool must not be treated as read-only merely because its purpose is report rendering. Its current tool definition also does not add the explicit `readOnlyHint`, `mutation=false`, and `requiresApproval=false` metadata used by the two newer model-facing tools.

## 21. AgentFlow report node also has a weaker validation boundary

`DataHawkReportNode` accepts a JSON configuration and passes the decoded `query` array directly to `IQueryService::executeQuery()`.

It does not apply the explicit model-facing SELECT/function/operator/table validation from `DataHawkAgentTool`.

This is appropriate only when the AgentFlow and query-producing step are trusted for the capabilities exposed by the active `IQueryService`.

If a model generates arbitrary node config, the flow owner must treat that as a separate security review point.

## 22. SQL exposure in the canvas tool

After query execution, `VizionCanvasAgentTool` returns:

```text
sql = QueryResult::debugSql
columns = QueryResult::columns
```

That tool result can become model-visible through the MissionBay runtime.

Because debug SQL can contain literals and backend structure, deployments should decide whether this older canvas integration is acceptable for sensitive reporting domains.

## 23. SQL exposure in `DataHawkReportNode`

The report node returns `QueryResult::debugSql` as its `sql` output.

A downstream flow node can persist, render, log, send, or otherwise process that value.

The node itself does not redact SQL literals.

## 24. Canvas HTML and `sanitize=false`

The canvas tool renders the exporter result inside an HTML block and explicitly sets:

```text
sanitize = false
```

The implementation assumes the selected `IReportExporter` generates trusted HTML.

Privacy and security implications include:

- every rendered data value can reach the browser canvas;
- active or unsafe markup from an exporter can cross directly into the UI;
- HTML escaping of individual business values is therefore an exporter responsibility; and
- a compromised or unsuitable exporter can turn report data into an injection risk.

The correct architecture boundary is the exporter/canvas trust contract, not an additional compensating renderer path.

## 25. Event-stream exposure

The canvas path requires an `eventstream` object in the agent context.

It can emit:

```text
canvas.open
canvas.render
```

The render event contains the complete generated report HTML.

The event-stream implementation, network transport, browser client, and any event logging can therefore process the same personal or confidential data contained in the report.

MissionBayReporting itself does not persist those events.

## 26. Canvas title and identifier

`canvas_id` and `title` are supplied by the tool arguments and forwarded into event payloads.

They are not business data by definition, but a model or user can put personal or confidential text into the title. Event logs and browser UI should therefore not be assumed to contain only harmless metadata.

## 27. Schema-derived memory resource

`VizionMemoryAgentResource` contributes one system message through `IAgentMemory::loadNodeHistory()`.

The message includes:

- reporting-tool usage rules;
- canvas behavior rules; and
- one schema-derived example.

It does not store conversation messages. `appendNodeHistory()` and `resetNodeHistory()` are no-ops, and feedback storage is unsupported.

## 28. Sensitive metadata handling in the memory example

When generating its example, `VizionMemoryAgentResource` deliberately skips:

- tables marked sensitive; and
- fields marked sensitive.

It chooses up to four non-sensitive fields from a non-sensitive table.

This limits accidental sensitive-schema exposure in the always-present example prompt.

The fixed reporting instructions themselves still name the available reporting tools and their intended behavior.

## 29. Memory-resource filters

The schema-derived example can be narrowed by domain, category, and tag filters.

These filters are useful for reducing irrelevant schema exposure in the prompt.

They affect only the generated example. They do not automatically change the separate query service authorization boundary.

## 30. Conversation retention is outside this plugin

MissionBayReporting does not implement conversation storage.

Tool calls and results can nevertheless become part of MissionBay conversation memory, tool audit records, provider request history, or host logs.

Retention and deletion rules for those stores must be documented in the corresponding component and installation configuration.

Deleting a report or changing reporting permissions does not automatically imply deletion of historical conversation content that already contains report rows.

## 31. Tool auditing is outside this plugin but can materially extend retention

MissionBay can be configured with tool-use auditing.

If active, a reporting tool call can cause the following to be persisted elsewhere:

- function name;
- tool arguments;
- structured query;
- search/filter values;
- result rows;
- errors; and possibly
- diagnostic output.

MissionBayReporting itself does not control that retention.

A deployment handling sensitive reporting data should review the MissionBay audit configuration together with this plugin.

## 32. Logging

The current MissionBayReporting classes do not inject `ILogger` and do not explicitly write application logs.

Logging can still occur in:

- `IQueryService` implementations;
- Vizion report services;
- report exporters;
- MissionBay tool auditing;
- model-provider clients;
- event-stream infrastructure;
- PHP/web-server error logs; and
- database or proxy infrastructure.

Full structured queries, result rows, and debug SQL should not be written to general logs without a defined operational purpose.

## 33. Browser storage

The package contains no JavaScript code and does not directly use:

- cookies;
- `localStorage`;
- `sessionStorage`;
- IndexedDB; or
- browser caches.

Browser-side persistence can still be introduced by the consuming chatbot/canvas UI. The canvas event payload itself can contain report data even though this plugin does not decide how the browser stores or displays it.

## 34. Cookies and sessions

MissionBayReporting does not directly read or write cookies or PHP sessions in the current source package.

Identity and authorization context can be carried indirectly by injected query/report services or by the MissionBay runtime.

The plugin therefore assumes that the surrounding composition supplies an appropriate caller context where authorization is user-dependent.

## 35. Direct network calls

No HTTP client or external API client is implemented in MissionBayReporting.

Network transfer can still occur through the surrounding runtime, including:

- a remote model provider receiving tool context;
- a remote query or report service implementation;
- an event-stream connection to a browser; or
- reverse proxies and observability infrastructure.

Those boundaries are installation-specific.

## 36. Authorization responsibilities

MissionBayReporting provides several useful restrictions but does not define a complete user authorization system.

Responsibilities are split as follows:

### MissionBayReporting

- validates curated report identifiers against the configured Vizion scope;
- validates curated report-facing keys;
- validates the model-facing DataHawk query shape;
- optionally filters visible DataHawk metadata/tables by component configuration; and
- limits result size in the validated DataHawk tool.

### Reporting/query backend and project composition

- determines which tables exist for the runtime;
- enforces row-level or tenant-level restrictions;
- applies user-specific access rules;
- prevents unauthorized field access;
- supplies secure report definitions; and
- controls any direct-query capabilities outside the validated tool path.

### MissionBay runtime

- decides which tools are attached to an agent;
- sends tool results to the configured model provider;
- may persist conversation/tool-audit data; and
- applies any orchestration policy around tool calls.

## 37. Tool metadata is not an access-control system

`VizionReportAgentTool` and `DataHawkAgentTool` declare:

```text
readOnlyHint = true
mutation = false
requiresApproval = false
```

These flags inform orchestration behavior. They are not proof that every injected backend is harmless and do not grant or deny data access.

The actual implementation must remain consistent with those declarations. Any future mutation capability must use a separately reviewed contract rather than silently keeping read-only metadata.

## 38. Data minimization recommendations

For sensitive reporting use cases, prefer the following measures at the existing architectural boundaries:

1. expose finished Vizion reports when a curated report already answers the question;
2. make report definitions contain only fields needed for their business purpose;
3. restrict the injected query/reporting service to the caller's permitted data scope;
4. use `domainFilter`, `categoryFilter`, `tagFilter`, and `tableFilter` to narrow ad-hoc discovery where useful;
5. configure conservative query limits;
6. project only required fields from curated reports;
7. avoid including direct identifiers when aggregate results are sufficient;
8. keep sensitivity metadata accurate;
9. keep exception messages free of secrets;
10. avoid the direct canvas/report-node query paths where their broader capability is not required; and
11. avoid SQL exposure where it is not operationally needed.

## 39. Retention

MissionBayReporting itself defines no retention jobs because it owns no persistent reporting-data store.

The following retention areas must be reviewed in the full installation:

- conversation history;
- MissionBay tool audit records;
- model-provider request retention;
- report/query backend logs;
- database logs;
- event-stream diagnostics;
- web-server/proxy logs;
- exported/downloaded reports produced elsewhere; and
- downstream AgentFlow storage.

A privacy deletion request may therefore require action in systems outside this plugin.

## 40. Deletion and permission changes

Changing backend permissions affects future queries only to the extent that the active reporting/query service enforces the new authorization state.

It does not automatically erase historical data that has already been:

- included in a conversation;
- sent to a model provider;
- persisted in a tool audit;
- copied into a downstream flow result;
- rendered into a browser canvas; or
- written to infrastructure logs.

Deployments should define how those downstream copies are retained and deleted.

## 41. Data export and rendered output

MissionBayReporting itself does not implement a user-facing download endpoint in this source package.

The exporter contracts used by the canvas and report node can produce report strings that may later be displayed, copied, persisted, or exported by other components.

Once data is passed to those downstream components, their storage and download controls become part of the privacy boundary.

## 42. Configuration data

The agent resources can receive component configuration such as priorities, filters, result limits, reporting scope, and related values through `IAgentConfigValueResolver`.

These values are not persisted by MissionBayReporting itself.

They can nevertheless reveal internal reporting structure and should be protected according to the configuration store and administration interface that owns them.

## 43. Current source/documentation mismatch must not be hidden

Parts of the existing package documentation describe `DataHawkAgentTool` as requiring a ResourceFoundation `reportingscope` resolved through `IReportingScopeRegistry`.

The current PHP class does not contain that configuration property or dependency.

The implementation currently relies on the injected `IQueryService`, optional metadata/table filters, and `IScopedQuerySchemaProvider`.

This matters for privacy because an installation must not assume a scope boundary that the actual class does not enforce.

The appropriate resolution is to keep configuration and source aligned at the responsible architecture boundary, not to add a second fallback scope mechanism around the discrepancy.

## 44. Security and privacy review checklist

Before enabling MissionBayReporting in a production agent, verify at least the following:

- [ ] The active `IQueryService` exposes only data the caller is permitted to query.
- [ ] Curated Vizion reports apply the intended report and backend security constraints.
- [ ] The configured model provider is permitted to process the report data returned to the agent.
- [ ] Sensitive fields are excluded when they are not required.
- [ ] DataHawk component filters reflect the intended analytical domain.
- [ ] Query limits are low enough to avoid unnecessary bulk disclosure.
- [ ] Error messages from query/report backends do not contain secrets.
- [ ] Conversation-memory retention is documented.
- [ ] MissionBay tool-audit retention is documented.
- [ ] Provider-side request retention and training use are documented where external model services are used.
- [ ] The canvas event-stream transport is appropriately protected.
- [ ] Report exporter HTML is trusted before using the `sanitize=false` canvas path.
- [ ] SQL exposure through `VizionCanvasAgentTool` is acceptable.
- [ ] SQL exposure through `DataHawkReportNode` is acceptable.
- [ ] Direct query paths are not assumed to have the `DataHawkAgentTool` read-only validator.
- [ ] Any ambiguity between existing documentation and the current DataHawk source has been resolved in the deployed configuration.

## 45. Summary

MissionBayReporting is primarily a runtime bridge. Its privacy impact is determined by what reporting services expose and what the MissionBay runtime does with the resulting tool data.

The safest model-facing paths are intentionally structured:

```text
finished report
  -> VizionReportAgentTool

ad-hoc analytics
  -> DataHawkAgentTool
```

The older canvas and report-node paths are more permissive integrations and expose diagnostic SQL in addition to rendered/report data.

No persistent reporting store is implemented by this plugin, but tool results can cross into model providers, conversation memory, tool audits, event streams, browser rendering, logs, and downstream flow processing. Those surrounding boundaries must be reviewed together with the reporting backend authorization model.
