# DataHawk Agent Tool

## Purpose

`DataHawkAgentTool` provides a bounded, read-only reporting capability for MissionBay agents.

## Contracts

The class implements:

```text
MissionBay\Api\IAgentTool
Base3\Api\ISchemaProvider
Base3\Api\IOutputSchemaProvider
```

It consumes:

```text
ResourceFoundation\Api\IQueryService
ResourceFoundation\Api\IReportingScopeRegistry
ResourceFoundation\Api\IScopedQuerySchemaProvider
MissionBay\Api\IAgentConfigValueResolver
```

## Technical name

```text
datahawkagenttool
```

## Tool definitions

### `describe_reporting_data`

Category/tags identify this as a read-only reporting schema tool.

Arguments:

```text
search
  optional schema concept search

schema
  optional exact technical query-schema scope from discovery
  required together with table

table
  optional exact table name from a previous discovery result

limit
  optional candidate count, maximum 20
```

When only `search` is provided, the tool returns bounded table candidates and representative fields. Every candidate contains its exact technical `schema`. When an exact `table` is requested, the same `schema` is required so duplicate local table names across technical scopes remain unambiguous.

The search operates on schema metadata such as table names, labels, descriptions, tags and field metadata.

### `execute_datahawk_query`

Arguments:

```json
{
  "query": {}
}
```

`query` is required and must be an object.

The operation executes one validated read-only structured query through `IQueryService`.

## Tool semantics

Both tool definitions declare:

```text
readOnlyHint = true
mutation = false
requiresApproval = false
```

This metadata must remain consistent with the implementation. If future behavior mutates data, it must not retain these annotations.

## Output schemas

The class provides output schemas keyed by operation name. Both require at least:

```text
ok
operation
```

Additional structured result metadata is returned by the operation implementation.

## Reporting scope boundary

Each tool instance requires one `reportingScope` id. The tool resolves that id through `IReportingScopeRegistry` and reads only the reporting area's declared `querySchemaScopes` through `IScopedQuerySchemaProvider`.

The optional filters remain additional restrictions inside that reporting scope:

```text
domainFilter
categoryFilter
tagFilter
tableFilter
```

An empty filter means no additional restriction for that dimension. The tool does not fall back to DataHawk's global or default schema scope.

## Limits

Current defaults:

```text
describeLimit = 8
max describeLimit = 20
defaultLimit = 100
maxLimit = 1000
```

Every explicit SELECT limit is checked against `maxLimit`. Root queries without a limit receive the configured default.

## On-demand model

There is deliberately no giant reporting schema system prompt attached to every agent turn.

This reduces prompt size, avoids stale schema injection and lets the model discover only the tables relevant to the current reporting question.

## Example flow

```text
User: How many active course participants are there?

Agent
  -> describe_reporting_data(search="course participant")

Tool
  -> candidate table names and representative fields

Agent
  -> describe_reporting_data(schema="exact_returned_schema", table="exact_returned_table")

Tool
  -> full field metadata and query rules

Agent
  -> execute_datahawk_query(query={"type":"select","schema":"exact_returned_schema",...})

Tool
  -> rows/columns/count/sensitivity metadata
```

The agent should reuse schema information already returned in the current conversation instead of rediscovering the same table unnecessarily.
