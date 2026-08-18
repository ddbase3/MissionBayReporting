# MissionBayReporting Overview

## Purpose

MissionBayReporting contributes reporting-specific MissionBay resources and one reporting flow node.

Current source classes:

```text
MissionBayReportingPlugin
DataHawkAgentTool
VizionCanvasAgentTool
VizionMemoryAgentResource
DataHawkReportNode
```

## Current preferred model-facing path

The preferred reporting path for an agent is:

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
  -> DataHawk exporter
  -> eventstream
  -> canvas.open / canvas.render
```

## Legacy/flow path

`DataHawkReportNode` remains available for AgentFlow definitions that construct rendered reports through `IReportExporterFactory`.

## Discovery

The plugin itself only registers its own instance. Resources and nodes are found by `IClassMap` through their stable `getName()` values.
