# MissionBayReporting DataHawk Report Node

## Purpose

`DataHawkReportNode` is a discoverable MissionBay AgentFlow node for generating rendered DataHawk reports.

Technical name:

```text
datahawkreportnode
```

## Dependency

The node depends directly on:

```text
DataHawk\Api\IReportExporterFactory
```

This is acceptable here because MissionBayReporting is an explicit integration package for DataHawk/Vizion reporting.

## Input

One required input:

```text
config: string
```

The string must decode to a JSON object containing at least:

```text
type
query
```

An optional `message` becomes the `response` output.

## Exporter mapping

```text
table -> htmltablereportexporter
datatable -> datatablereportexporter
piechart -> piechartreportexporter
barchart -> barchartreportexporter
other -> htmltablereportexporter
```

## Outputs

```text
response
report
columns
sql
error
```

This is a lower-level/report-rendering flow integration. Unlike `DataHawkAgentTool`, it can return generated SQL and rendered HTML/report strings.

## Security distinction

Do not assume this node has the same model-facing validation contract as `DataHawkAgentTool` merely because both ultimately use DataHawk.

For direct LLM tool access, prefer `DataHawkAgentTool` because it performs explicit read-only query validation and schema scoping.
