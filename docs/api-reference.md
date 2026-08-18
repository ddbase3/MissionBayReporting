# MissionBayReporting API and Component Reference

## Purpose

MissionBayReporting does not currently define its own `src/Api/` interfaces. This reference therefore lists its discoverable extension classes and their direct framework contracts.

## `DataHawkAgentTool`

File: `src/MissionBay/DataHawkAgentTool.php`

Technical name: `datahawkagenttool`

```php
class DataHawkAgentTool extends AbstractAgentResource implements IAgentTool, ISchemaProvider, IOutputSchemaProvider
```

## `VizionCanvasAgentTool`

File: `src/MissionBay/VizionCanvasAgentTool.php`

Technical name: `vizioncanvasagenttool`

```php
class VizionCanvasAgentTool extends AbstractAgentResource implements IAgentTool
```

## `VizionMemoryAgentResource`

File: `src/MissionBay/VizionMemoryAgentResource.php`

Technical name: `vizionmemoryagentresource`

```php
class VizionMemoryAgentResource extends AbstractAgentResource implements IAgentMemory
```

## `MissionBayReportingPlugin`

File: `src/MissionBayReportingPlugin.php`

Technical name: `missionbayreportingplugin`

```php
class MissionBayReportingPlugin implements IPlugin
```

## `DataHawkReportNode`

File: `src/Node/Data/DataHawkReportNode.php`

Technical name: `datahawkreportnode`

```php
class DataHawkReportNode extends AbstractAgentNode
```
