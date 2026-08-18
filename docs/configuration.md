# MissionBayReporting Configuration

## Purpose

`DataHawkAgentTool` is configured as a normal MissionBay component preset.

No separate MissionBayReporting settings group is required for the model-facing tool.

## Schema

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

Filters are passed through `IAgentConfigValueResolver`, so normal MissionBay config-value definitions can be used where the tool schema accepts them.

This allows project-specific scope values without adding a reporting-specific resolver.

## Multiple reporting scopes

Use multiple normal component presets when different agents need different reporting areas.

Example:

```text
reporting-users
  type: datahawkagenttool
  tableFilter: user-oriented reporting tables

reporting-ai-usage
  type: datahawkagenttool
  tableFilter: AI usage reporting tables
```

Do not create a new routing/profile layer just to distinguish these tool instances. The component preset ID already provides configured instance identity.
