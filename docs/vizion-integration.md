# MissionBayReporting Vizion Integration

## Purpose

This document describes the Vizion integrations in MissionBayReporting.

For model-facing access to finished report data, use `VizionReportAgentTool`. The canvas tool documented below is retained as a separate DataHawk-based visualization path.

## VizionReportAgentTool

Technical name:

```text
vizionreportagenttool
```

Tool functions:

```text
describe_vizion_reports
execute_vizion_report
search_vizion_tree
```

The tool delegates to `Vizion\Api\IReportDataService`, so the agent consumes the same report definition, filters, tree filters, sorting, paging and secured query path as the ModularGrid web report.

See [vizion-report-agent-tool.md](vizion-report-agent-tool.md).

## VizionCanvasAgentTool

Technical name:

```text
vizioncanvasagenttool
```

Tool function:

```text
vizion_report_canvas
```

## Arguments

```json
{
  "canvas_id": "main",
  "title": "Report",
  "open": true,
  "config": {
    "type": "datatable",
    "query": {}
  }
}
```

`config` is required. It may be supplied as an already decoded object or as a JSON string by the current implementation.

## Exporter mapping

```text
table -> htmltablereportexporter
datatable -> datatablereportexporter
piechart -> piechartreportexporter
barchart -> barchartreportexporter
other -> htmltablereportexporter
```

The tool executes the structured query through `ResourceFoundation\Api\IQueryService` and resolves the configured `ResourceFoundation\Api\IReportExporter` implementation through `IClassMap`.

## Canvas events

If the agent context contains an `eventstream`, the tool can emit:

```text
canvas.open
canvas.render
```

The render event replaces the target canvas with one HTML block containing the report exporter output.

The current implementation marks that HTML block with `sanitize = false` because the selected report exporter is expected to generate trusted HTML. This is an important integration assumption and should be revisited if exporter trust boundaries change.

## Return value

The tool returns:

```text
ok
canvas_id
sql
columns
```

or an error.

## VizionMemoryAgentResource

Technical name:

```text
vizionmemoryagentresource
```

It implements `IAgentMemory` as a static/system-prompt contributor. `loadNodeHistory()` returns one system message containing canvas policy, Vizion tool rules and a schema-derived example. Append, feedback and reset are no-op/not supported.

The resource can filter its schema-derived example by domain/category/tag.

## Recommended use

Use `VizionReportAgentTool` for data already represented by a finished Vizion report. Use `DataHawkAgentTool` for ad-hoc analysis. Use `VizionCanvasAgentTool` only when the current DataHawk-based canvas rendering workflow is explicitly desired.
