# Vizion Report Agent Tool

## Purpose

`VizionReportAgentTool` gives MissionBay agents read-only access to finished Vizion report definitions.

It is the preferred path when a user asks for data that already exists as a curated Vizion report. `DataHawkAgentTool` remains available for ad-hoc analysis that is not represented by a report definition.

Technical resource name:

```text
vizionreportagenttool
```

## Architecture

```text
MissionBay agent
  -> VizionReportAgentTool
  -> Vizion IReportDataService
  -> report vdef
  -> ModularGridReportQueryBuilder
  -> ResourceFoundation IQueryService
```

The tool does not construct DataHawk queries itself. Search, ordinary filters, tree filters, sorting, paging and ResourceFoundation security constraints are resolved inside Vizion through the same query path used by the ModularGrid web display.

## Configuration

One tool instance is bound to one ResourceFoundation reporting scope.

```json
{
  "reportingscope": "ilias",
  "priority": 70,
  "describeLimit": 10
}
```

`reportingscope` is required. A report identifier from another reporting scope is rejected even if it is otherwise known to Vizion.

## Tool functions

### `describe_vizion_reports`

Discovers executable Vizion reports or describes one exact report.

Search mode:

```json
{
  "search": "course progress"
}
```

Exact description:

```json
{
  "report": "course_report_rows"
}
```

The detail response exposes configured fields, ordinary filters, tree filters and paging/sort defaults. It does not expose the underlying DataHawk query AST.

### `search_vizion_tree`

Resolves exact tree node ids for a report tree filter.

```json
{
  "report": "course_report_rows",
  "treeFilter": "category",
  "search": "Deutschland Vertrieb"
}
```

Multiple search words are matched as an AND search across the node label and breadcrumb path.

The returned node id can then be used in `execute_vizion_report.treeFilters`.

### `execute_vizion_report`

Executes a finished report.

```json
{
  "report": "course_report_rows",
  "filters": {
    "learning_progress_status_label": "COMPLETED"
  },
  "treeFilters": {
    "category": "2785"
  },
  "fields": [
    "course_title",
    "fullname_de",
    "learning_progress_status_label"
  ],
  "page": 1,
  "pageSize": 50
}
```

Only field aliases configured by the report may be projected. The agent tool rejects unknown ordinary filters, tree filters and non-sortable sort fields before execution. Valid values are then normalized and executed by the same Vizion services used by the browser report.

The returned data is raw report data. UI-only cell renderers and internal `__row_key` values are not exposed to the agent.

## Supported report displays

The first headless implementation supports:

```text
modulargridreportdisplay
```

Chart, metric, matrix and AI-summary displays are not silently emulated. They should be added only after their data execution has been extracted into reusable Vizion services.

## DataHawk fallback

Use `DataHawkAgentTool` when the user asks for an analysis that is not represented by a suitable Vizion report.

The intended distinction is:

```text
VizionReportAgentTool = curated reporting
DataHawkAgentTool = ad-hoc analytical queries
```
