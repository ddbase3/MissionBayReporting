# MissionBayReporting

**MissionBayReporting** is an extension for the [MissionBay](https://github.com/ddbase3/MissionBay) plugin in the BASE3 Framework. It equips chat agents with reporting-oriented tools and memory resources so they can query structured data with **DataHawk** and render visual reports through **Vizion**.

The plugin is designed for agent-driven reporting workflows. Instead of hard-coding report logic into the chatbot itself, MissionBayReporting exposes reusable tools and prompt resources that help an agent:

* generate valid DataHawk query objects,
* execute structured reporting queries,
* render report results into the chatbot canvas,
* and stay aligned with the available reporting schema.

## Purpose

MissionBayReporting acts as the reporting bridge between:

* **MissionBay** as the agent runtime,
* **DataHawk** as the structured query engine,
* **Vizion** as the report rendering layer.

In practice, this means a chat agent can answer reporting requests in two different ways:

1. **Return raw data in chat** by executing a DataHawk query.
2. **Open a visual report in the canvas** by rendering a Vizion report based on a DataHawk query.

## Features

* MissionBay-compatible agent tools for reporting workflows
* Structured DataHawk query execution through a tool interface
* Vizion canvas rendering for tables and charts
* Memory resources that inject reporting rules into the agent prompt
* Schema-aware prompt generation based on `IQuerySchemaProvider`
* Optional schema filtering by domain, category, and tags
* Separation between data retrieval and visual rendering

## Included Components

## Agent Tools

### `DataHawkAgentTool`

Provides a callable agent tool for executing structured DataHawk queries.

**Tool function:** `execute_datahawk_query`

The tool accepts a `query` argument as either:

* a JSON object, or
* a JSON string.

It executes the query through `ResourceFoundation\Api\IQueryService` and returns:

* `columns`
* `rows`
* `count`
* `query_received`

This tool is intended for requests where the user wants data, records, or aggregated results directly in the chat.

### `VizionCanvasAgentTool`

Provides a callable agent tool for rendering report output into the chatbot canvas.

**Tool function:** `vizion_report_canvas`

The tool accepts:

* `canvas_id`
* `title`
* `open`
* `config`

The `config` object must contain at least:

* `type`
* `query`

Supported report types currently mapped by the tool are:

* `table`
* `datatable`
* `piechart`
* `barchart`

Internally, the tool uses `DataHawk\Api\IReportExporterFactory` to build a report exporter, generate HTML, and push canvas events through the active MissionBay event stream.

This tool is intended for requests where the user wants a visual report instead of plain chat output.

## Memory Resources

### `DataHawkMemoryAgentResource`

Provides a system-prompt memory block that teaches the agent how to build valid **DataHawk structured queries**.

It injects:

* DataHawk query rules,
* safety constraints,
* allowed element types,
* query structure guidance,
* and a schema-derived list of allowed tables, fields, and relations.

The resource reads schema metadata from `ResourceFoundation\Api\IQuerySchemaProvider` and can optionally filter tables by:

* `domainFilter`
* `categoryFilter`
* `tagFilter`

This helps the agent generate schema-safe reporting queries.

### `VizionMemoryAgentResource`

Provides a system-prompt memory block for **Vizion canvas usage**.

It teaches the agent:

* when to use `execute_datahawk_query`,
* when to use `vizion_report_canvas`,
* how canvas output should be handled,
* and how to structure a report config for visual rendering.

In addition, it generates a schema-derived example payload based on the currently available reporting schema.

This resource is focused on report presentation and canvas behavior, rather than raw query construction.

## How It Works

A typical reporting flow looks like this:

1. The user asks the chatbot for data or a report.
2. MissionBay loads one or both memory resources into the agent context.
3. The agent receives schema-aware instructions for DataHawk and Vizion usage.
4. The agent chooses one of the available tools:

   * `execute_datahawk_query` for direct data output
   * `vizion_report_canvas` for visual report rendering
5. The result is either returned in chat or pushed into the chatbot canvas.

## Architecture

MissionBayReporting does not replace MissionBay, DataHawk, or Vizion. It extends them.

### Responsibilities

* **MissionBay** handles agent execution, tool calling, context, memory, and event streaming.
* **MissionBayReporting** provides reporting-specific tools and prompt resources.
* **DataHawk** executes structured queries and provides report exporters.
* **Vizion** is used indirectly through exporter-driven HTML rendering into the canvas.

## Dependencies

Based on the current implementation, MissionBayReporting depends on the following interfaces and systems:

* `MissionBay\Api\IAgentTool`
* `MissionBay\Api\IAgentMemory`
* `MissionBay\Api\IAgentContext`
* `MissionBay\Api\IAgentConfigValueResolver`
* `ResourceFoundation\Api\IQueryService`
* `ResourceFoundation\Api\IQuerySchemaProvider`
* `DataHawk\Api\IReportExporterFactory`

For canvas rendering, the MissionBay context must also expose an `eventstream` runtime variable.

## Example Use Cases

### 1. Chat-based data retrieval

A user asks:

> Show me the latest repositories with their default branch.

The agent can construct a valid DataHawk query and call `execute_datahawk_query`.

### 2. Visual report rendering

A user asks:

> Open this as an interactive table.

The agent can switch to `vizion_report_canvas` and render a `datatable` into the chatbot canvas.

### 3. Guided schema-aware querying

Because the schema is injected into memory, the agent can stay constrained to known tables and fields instead of inventing unsupported query structures.

## Configuration Notes

Both memory resources support optional filtering through config values resolved by `IAgentConfigValueResolver`.

Supported filters:

* `domainFilter`
* `categoryFilter`
* `tagFilter`
* `priority`

This makes it possible to restrict the visible reporting schema for a given agent, node, or use case.

## Project Structure

```text
src/
├── MissionBay/
│   ├── DataHawkAgentTool.php
│   ├── DataHawkMemoryAgentResource.php
│   ├── VizionCanvasAgentTool.php
│   └── VizionMemoryAgentResource.php
└── MissionBayReportingPlugin.php
```

## Position in the BASE3 Ecosystem

MissionBayReporting is a focused extension layer for agent-driven reporting.

It connects:

* **MissionBay** for agent runtime and tool execution
* **DataHawk** for structured querying
* **Vizion** for visual report output
* **ResourceFoundation** for query services and schema access

This keeps reporting logic modular and reusable across chatbot and agent scenarios.

## Status

Current implementation includes:

* one query execution tool,
* one visual canvas rendering tool,
* one DataHawk memory resource,
* one Vizion memory resource.

This makes the plugin a practical starting point for schema-aware reporting agents inside MissionBay.

## License

GPL-3.0 License

