# MissionBayReporting Query Contract and Security

## Purpose

This document describes the validation boundary applied by `DataHawkAgentTool` before a model-generated reporting query reaches `IQueryService`.

## Read-only root

Only structured SELECT queries are accepted.

The model does not submit raw SQL.

## Allowed element types

```text
fld
fn
op
subquery
case
windowfn
```

Other element types are rejected.

## Allowed functions

Current allowlist:

```text
ABS
AVG
CAST
CEIL
COALESCE
CONCAT
CONVERT
COUNT
CURDATE
CURRENT_DATE
DATE
DATE_FORMAT
DATEDIFF
DAY
DENSE_RANK
FLOOR
FROM_UNIXTIME
GROUP_CONCAT
IF
IFNULL
LAG
LEAD
LENGTH
LOWER
MAKEDATE
MAX
MIN
MONTH
NOW
NULLIF
RANK
ROUND
ROW_NUMBER
SUBSTRING
SUM
TIMESTAMPDIFF
TRIM
UNIX_TIMESTAMP
UPPER
YEAR
```

Functions outside the allowlist are rejected before query execution.

## Allowed operators

```text
=
!=
<>
>
>=
<
<=
AND
OR
IN
NOT IN
IS NULL
IS NOT NULL
BETWEEN
LIKE
NOT LIKE
+
-
*
/
```

## Table and field validation

The tool validates referenced tables against the allowed table map built from ResourceFoundation metadata and configured scope filters.

Referenced fields must belong to allowed tables. The agent cannot bypass table scope by guessing a physical source table name.

## Nested structures

Validation applies recursively to:

* root queries
* UNION branches
* nested subqueries
* expressions
* ordering
* functions/operators
* explicit limits

A nested subquery is not an escape from the root read-only/table scope.

## Result limits

The tool enforces a configurable hard maximum.

If the model omits the root limit, `defaultLimit` is added. If it declares an excessive limit, the request is rejected or normalized according to the implementation's validation path rather than passed through unrestricted.

## Sensitivity

ResourceFoundation metadata can mark tables/fields as sensitive. MissionBayReporting preserves those markers in discovery/results.

The current tool does not automatically hide every sensitive field. The project query backend and configured tool scope must therefore expose only data the caller is allowed to query.

This distinction is important:

```text
MissionBayReporting
  validates model query shape and configured reporting scope

query backend / project authorization
  decides which reporting data is actually available to this runtime/user
```

Do not treat `readOnlyHint` as an authorization mechanism.

## Generated SQL

The model-facing `DataHawkAgentTool` does not need raw generated SQL in its normal response. Query compilation belongs behind `IQueryService`/ResourceFoundation.

The older DataHawk report node and Vizion exporter path can expose SQL for diagnostic/report-export reasons, which is a separate integration surface.
