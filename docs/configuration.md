# MissionBayReporting Configuration

## Purpose

`VizionReportAgentTool` and `DataHawkAgentTool` are configured as normal MissionBay component presets.

No separate MissionBayReporting settings group is required for the model-facing tool.

## Vizion report tool

Technical resource name:

```text
vizionreportagenttool
```

Use it as the preferred reporting tool when agents should consume finished Vizion reports.

Minimal component preset:

```text
reporting-vizion
  type: vizionreportagenttool
  reportingscope: ilias
```

Supported settings:

### `reportingscope`

Required exact id from `ResourceFoundation\Api\IReportingScopeRegistry`.

The tool exposes only Vizion report definitions referenced by that reporting scope's `reportScopes`.

### `priority`

Tool catalog priority.

```text
default = 70
```

### `describeLimit`

Default maximum candidate count returned by Vizion report discovery.

```text
default = 10
maximum = 30
```

`reportingscope`, `priority` and `describeLimit` are resolved through `IAgentConfigValueResolver`.

## DataHawk tool

Technical resource name:

```text
datahawkagenttool
```

Use it for ad-hoc analytical queries that are not represented by a suitable Vizion report.

## Schema

### `reportingscope`

Required exact id from `ResourceFoundation\Api\IReportingScopeRegistry`.

The reporting scope defines which technical query-schema scopes belong to this tool instance. Schema discovery and query validation are restricted to those scopes.

### `priority`

Tool catalog priority.

Default:

```text
60
```

### `domainFilter`

Only expose ResourceFoundation tables in these domains.

Empty means all otherwise visible domains.

### `categoryFilter`

Only expose tables in these categories.

### `tagFilter`

Only expose tables containing at least one requested tag.

### `tableFilter`

Only expose the listed exact ResourceFoundation table names.

### `describeLimit`

Maximum candidate count for schema search.

```text
default = 8
maximum = 20
```

### `defaultLimit`

Default root SELECT result limit when the agent omits one.

```text
default = 100
```

### `maxLimit`

Hard maximum for explicit SELECT limits.

```text
default = 1000
```

## Late value resolution

`reportingscope` and filters are passed through `IAgentConfigValueResolver`, so normal MissionBay config-value definitions can be used where the tool schema accepts them.

This allows project-specific scope values without adding a reporting-specific resolver.

## Multiple reporting scopes

Use multiple normal component presets when different agents need different reporting areas.

Example:

```text
reporting-users
  type: datahawkagenttool
  reportingscope: users

reporting-ai-usage
  type: datahawkagenttool
  reportingscope: ai-usage
```

Do not create a new routing/profile layer just to distinguish these tool instances. The component preset ID already provides configured instance identity.
