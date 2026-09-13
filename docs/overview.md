# MissionBayReporting Overview

## Purpose

MissionBayReporting contributes reporting-specific MissionBay resources and one reporting flow node.

Current source classes:

```text
MissionBayReportingPlugin
VizionReportAgentTool
DataHawkAgentTool
VizionCanvasAgentTool
VizionMemoryAgentResource
DataHawkReportNode
```

## Preferred curated-report path

When the requested data already exists as a Vizion report:

```text
VizionReportAgentTool
  -> Vizion IReportDataService
  -> ResourceFoundation IQueryService
```

This path keeps the report vdef, filters, tree filters and backend security identical to the web report.

## Ad-hoc analytical path

When no suitable Vizion report exists:

```text
DataHawkAgentTool
  -> describe_reporting_data
  -> execute_datahawk_query
  -> ResourceFoundation IQueryService
```

This path keeps schema discovery bounded and query execution structured/read-only.

## Optional visualization path

When a chatbot/canvas environment is available:

```text
VizionCanvasAgentTool
  -> ResourceFoundation IQueryService
  -> IClassMap / IReportExporter
  -> eventstream
  -> canvas.open / canvas.render
```

## Legacy/flow path

`DataHawkReportNode` remains available for AgentFlow definitions that execute structured queries through `IQueryService` and render the resulting `QueryResult` through discoverable `IReportExporter` implementations.

## Discovery

The plugin itself only registers its own instance. Resources and nodes are found by `IClassMap` through their stable `getName()` values.
