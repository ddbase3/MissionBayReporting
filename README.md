# MissionBayReporting

**MissionBayReporting** extends MissionBay with reporting-oriented agent resources.

The DataHawk reporting tool is intentionally **on demand**: reporting schema metadata is not injected into every agent call. When a user asks a reporting question, the agent first discovers the relevant reporting tables and fields through the tool and then executes a read-only structured query.

## DataHawkAgentTool

`MissionBayReporting\MissionBay\DataHawkAgentTool` is one configurable MissionBay agent resource.

It exposes two read-only functions:

* `describe_reporting_data`
* `execute_datahawk_query`

The implementation depends on `ResourceFoundation\Api\IQueryService` for reporting data and schema metadata. It does not depend on DataHawk implementation classes for data access.

### `describe_reporting_data`

Use this operation when a reporting request requires schema knowledge.

The operation can:

* search available reporting tables by table metadata and field metadata,
* return representative field names for each search candidate so the agent can choose a table without repeated discovery calls,
* return full metadata for one exact table,
* expose field names, aliases, types, descriptions and sensitivity markers,
* expose available declared relations,
* return the supported read-only DataHawk operators and functions,
* return a small query example for the selected table.

The search result is deliberately bounded. It does not return the complete reporting schema unless the configured scope is already very small.

Example discovery call:

```json
{
  "search": "user"
}
```

After a candidate was found, the agent should request that exact table name once for full field metadata. It must not invent physical/source table names that were not returned by discovery:

```json
{
  "table": "user_report_rows"
}
```

Search terms should describe schema concepts such as `user`, `course`, `certificate` or `learning progress`. Person names and other data values are not schema search terms.

### `execute_datahawk_query`

Executes one structured read-only DataHawk SELECT query through `IQueryService`.

The `query` argument must be a JSON object. JSON strings are not accepted.

Example:

```json
{
  "query": {
    "type": "select",
    "table": "user_report_rows",
    "fields": [
      {
        "element": {
          "type": "fld",
          "table": "user_report_rows",
          "field": "usr_id"
        },
        "alias": "usr_id"
      },
      {
        "element": {
          "type": "fld",
          "table": "user_report_rows",
          "field": "firstname"
        },
        "alias": "firstname"
      }
    ],
    "where": {
      "type": "op",
      "operator": "=",
      "params": [
        {
          "type": "fld",
          "table": "user_report_rows",
          "field": "firstname"
        },
        "Daniel"
      ]
    },
    "limit": 100
  }
}
```

The tool returns structured result data:

* columns,
* rows,
* row count,
* sensitivity metadata,
* effective root result limit.

It does not return generated SQL or echo the submitted query back to the model.

## Read-only boundary

The tool accepts SELECT queries only.

Before a query reaches `IQueryService`, MissionBayReporting validates:

* root queries,
* UNION queries,
* nested subqueries,
* referenced tables,
* referenced fields,
* functions,
* operators,
* ordering directions,
* explicit limits.

Write operations such as `insert`, `update`, `delete`, DDL operations and transactions are rejected at the tool boundary.

Personal and sensitive fields are not hidden by MissionBayReporting. If such fields are part of the configured reporting scope returned by `IQueryService`, the agent may select and filter them. Sensitivity markers remain available in metadata and query results.

## Component preset configuration

`DataHawkAgentTool` implements `ISchemaProvider`. Therefore normal MissionBay component presets can configure one reporting data set without another profile or settings system.

Available settings:

* `priority`
* `domainFilter`
* `categoryFilter`
* `tagFilter`
* `tableFilter`
* `describeLimit`
* `defaultLimit`
* `maxLimit`

All configured filters apply to both schema discovery and query execution. There is no separate prompt scope and execution scope.

Example conceptual preset configuration:

```json
{
  "domainFilter": ["ilias_materialized"],
  "tableFilter": [
    "user_report_rows",
    "course_report_rows",
    "certificate_report_rows"
  ],
  "describeLimit": 8,
  "defaultLimit": 100,
  "maxLimit": 1000
}
```

A second reporting area such as AI usage reporting should be another normal configured instance of the same `datahawkagenttool` implementation with another scope. No additional routing or memory model is required.

## No DataHawk reporting memory

`DataHawkMemoryAgentResource` has been removed. Do not attach a separate DataHawk memory or context resource to an assistant node. The agent gets reporting context only when it calls `describe_reporting_data` for a reporting request.

This prevents large reporting schemas from being added to unrelated conversations and keeps the tool contract small enough for normal model orchestration.

## Existing Vizion resources

The existing Vizion canvas resources remain available for installations that still use that workflow. They are independent of the DataHawk reporting tool described above.

For a chatbot that already has its own graphical/table output capabilities, only `DataHawkAgentTool` is needed for data retrieval.

## Dependencies

The on-demand data tool uses:

* `MissionBay\Api\IAgentTool`
* `MissionBay\Api\IAgentConfigValueResolver`
* `AssistantFoundation\Api\IAgentContext`
* `ResourceFoundation\Api\IQueryService`
* `Base3\Api\ISchemaProvider`
* `Base3\Api\IOutputSchemaProvider`

The reporting data path therefore consumes the ResourceFoundation contract rather than DataHawk implementation classes.

## Project structure

```text
src/
├── MissionBay/
│   ├── DataHawkAgentTool.php
│   ├── VizionCanvasAgentTool.php
│   └── VizionMemoryAgentResource.php
└── MissionBayReportingPlugin.php
```

## Version

Current package version: `4.2.2`.

## License

GPL-3.0 License
