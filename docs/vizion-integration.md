# MissionBayReporting Vizion Integration

## Purpose

This document describes the optional report-to-canvas integration retained in MissionBayReporting.

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

The tool delegates to DataHawk `IReportExporterFactory`.

## Canvas events

If the agent context contains an `eventstream`, the tool can emit:

```text
canvas.open
canvas.render
```

The render event replaces the target canvas with one HTML block containing the report exporter output.

The current implementation marks that HTML block with `sanitize = false` because the DataHawk report exporter is expected to generate trusted HTML. This is an important integration assumption and should be revisited if exporter trust boundaries change.

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

If the chatbot already supports structured client-side table/chart response extensions, `DataHawkAgentTool` may be enough for data retrieval. Use `VizionCanvasAgentTool` only when the canvas rendering workflow is desired.
