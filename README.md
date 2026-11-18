# Jardis ACL

![Build Status](https://github.com/jardisSupport/acl/actions/workflows/ci.yml/badge.svg)
[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE.md)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.3-777BB4.svg)](https://www.php.net/)
[![PHPStan Level](https://img.shields.io/badge/PHPStan-Level%208-brightgreen.svg)](phpstan.neon)
[![PSR-12](https://img.shields.io/badge/Code%20Style-PSR--12-blue.svg)](phpcs.xml)

> Part of **[Jardis](https://jardis.io)** — the Domain-Driven Design platform for PHP. You model your domain; Jardis generates the production-ready hexagonal code (DTOs, Command/Query handlers, repositories, persistence). This package is part of the open-source foundation that generated code runs on.

**Per-record access entries without framework coupling.** One entry says: group G may reach record R of information kind K up to level L (`view`, `edit`, `all`). A focused toolkit for PHP that grants and revokes entries, answers "which level does this person hold?", restricts a record query to the records a person may see, and backfills records that exist already — built from `jardissupport/dbquery` and `jardissupport/repository`. No HTTP layer, no group or user model, no transaction of its own. Pure support package.

---

## Why This Package?

- **Three classes to learn** — `AclStore`, `AclFilter`, `AclBackfill`. Everything else is data
- **Numbers only** — kind, record, group and level are integers; who a group is stays your domain
- **A three-valued answer** — `check()` returns `Granted`, `NotVisible` or `TooLow`; you branch, you never compare levels
- **Filters instead of loops** — `AclFilter` builds a `dbquery` condition, so a list shows exactly the visible records in one query
- **MySQL, MariaDB, PostgreSQL, SQLite** — one DDL file per dialect

---

## Installation

```bash
composer require jardissupport/acl
```

Create the table once with the file of your dialect from `resources/sql/` (`mysql.sql`, `pgsql.sql`, `sqlite.sql`), or the equivalent in your own migration.

---

## Quick Start

### Grant, Check, Revoke (`AclStore`)

```php
use JardisSupport\Acl\AclStore;
use JardisSupport\Acl\Data\AccessDecision;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\Acl\Data\Entry;

$store = new AclStore($connection);          // DbConnectionInterface; table name optional, default 'acl_entries'

$store->grant(7, 1001, 5, AccessLevel::All); // kind 7, record 1001, group 5
$store->grantAll(7, 1001, [new Entry(9, AccessLevel::View), new Entry(12, AccessLevel::Edit)]);

$store->levelOf(7, 1001, [9, 12]);           // AccessLevel::Edit (highest of the groups); null = no entry

match ($store->check(7, 1001, [9], AccessLevel::Edit)) {
    AccessDecision::Granted    => $proceed(),
    AccessDecision::TooLow     => $answerForbidden(),
    AccessDecision::NotVisible => $answerNotFound(),
};

$store->revoke(7, 1001, 12);
$store->entriesOf(7, 1001);                  // list<Entry>, ordered by group
$store->isLastFullEntry(7, 1001, 5);         // true: group 5 is the only one holding "all"
$store->removeRecord(7, 1001);               // returns the number of removed entries
$store->hasEntriesOfGroup(9);                // false when the group may be deleted
$store->reassignGroup(9, 10);                // moves all entries of group 9 to 10, the higher level stays
```

`grantAll()` and `reassignGroup()` run several statements and open no transaction. Bracket them with `jardissupport/repository` `RunInTransaction` when they must succeed or fail together.

### Restrict a Query to Visible Records (`AclFilter`)

```php
use JardisSupport\Acl\AclFilter;
use JardisSupport\Acl\Data\AccessLevel;
use JardisSupport\DbQuery\Data\Expression;
use JardisSupport\DbQuery\DbQuery;

$filter = new AclFilter();                   // needs no connection, runs nothing

// Form 1: single-value subquery bound to the key column of the outer row
$query = (new DbQuery())->select('d.*')->from('documents', 'd')
    ->where(Expression::integer(1))->equals($filter->rowCondition(7, [9, 12], AccessLevel::View, 'd.id'));

// Form 2: key set
$query = (new DbQuery())->select('d.*')->from('documents', 'd')
    ->where('d.id')->in($filter->recordIds(7, [9, 12], AccessLevel::View));
```

`AclFilter::COUNT_CAP` (`int`, `10000`) is the fixed limit of the capped total of a filtered list: count it as `where(key)->in($filter->recordIds(...))`, sorted like the page, `limit(AclFilter::COUNT_CAP + 1)`, counted from outside with `COUNT(*)`. The total is at most `COUNT_CAP`; when the count exceeds it, report `COUNT_CAP` and mark the total as capped.

Both forms give the same set as `AclStore::levelOf`. An empty group list sees nothing. The filter may stand behind other conditions (e.g. a tenant column) — this needs `jardissupport/dbquery` 1.3.1 or newer.

### Backfill Existing Records (`AclBackfill`)

```php
use JardisSupport\Acl\AclBackfill;

$result = (new AclBackfill($connection))->run(
    kind: 7,
    sourceTable: 'documents',
    keyColumn: 'id',
    creatorColumn: 'created_by',
    tenantColumn: 'tenant_id',
    groupOfCreator: fn (?string $tenant, array $creators): array => $groupLookup($tenant, $creators), // creator => group
    fallbackGroup: 1,
);

$result->written();    // entries written
$result->toFallback(); // of those, given to the fallback group
$result->skipped();    // records left without an entry (no creator group and no fallback group)
```

Every record without an entry gets one entry `all`. Records that have an entry are never touched, so a second run writes nothing.

---

## The Table

One row per group and record. Created by `resources/sql/{mysql,pgsql,sqlite}.sql` (`CREATE ... IF NOT EXISTS`); the default name is `acl_entries`, set per constructor.

| Column | Type (MySQL, PostgreSQL / SQLite) | Meaning |
|---|---|---|
| `kind` | `INT` / `INTEGER`, not null | information kind |
| `record_id` | `BIGINT` / `INTEGER`, not null | record number |
| `group_id` | `BIGINT` / `INTEGER`, not null | group |
| `level` | `SMALLINT` / `INTEGER`, not null | 1 = view, 2 = edit, 3 = all |

| Key | Columns | Serves |
|---|---|---|
| Primary key | `(kind, record_id, group_id)` | one entry per group and record |
| `idx_acl_entries_group` | `(kind, group_id, level, record_id)` | `AclFilter`, `levelOf`, `check` |
| `idx_acl_entries_group_id` | `(group_id)` | `hasEntriesOfGroup`, `reassignGroup` |

**Existing tables are not upgraded.** `CREATE TABLE IF NOT EXISTS` leaves a table that already exists as it is, and the package runs no migration: a table created from an earlier DDL file does not get `idx_acl_entries_group_id` by itself. Add it yourself:

```sql
CREATE INDEX idx_acl_entries_group_id ON acl_entries (group_id);
```

---

## Architecture

The user sees three orchestrators. Internally, each delegates to invokable handlers (Closure-Orchestrator Pattern):

```
AclStore (Orchestrator)
  ├── Handler/Entry/FindHighestLevel     highest level of the groups on a record, or null
  ├── Handler/Entry/DecideAccess         level + required level → AccessDecision
  ├── Handler/Entry/GrantEntry           one upsert
  ├── Handler/Entry/GrantEntries         one upsert per distinct group
  ├── Handler/Entry/RevokeEntry          delete one entry
  ├── Handler/Entry/IsLastFullEntry      the group is the only one holding "all"
  ├── Handler/Entry/RemoveRecord         delete every entry of a record
  ├── Handler/Entry/ReassignGroup        move all entries of a group, in batches
  ├── Handler/Entry/MoveEntries          one batch; the higher level stays
  ├── Handler/Entry/UpsertEntries        insert or overwrite one group on several records
  ├── Handler/Entry/HasEntriesOfGroup    any entry carries the group
  └── Handler/Entry/FindEntries          all entries of a record

AclFilter (Orchestrator)
  ├── Handler/Filter/BuildRowCondition   single-value subquery bound to the outer key
  └── Handler/Filter/BuildRecordIds      key set

AclBackfill (Orchestrator)
  ├── Handler/Backfill/FindRecordsWithoutEntry  next batch of records without an entry
  └── Handler/Backfill/BackfillEntries          creator group, else fallback group, else skip

Handler/Table/AssertTableName, AssertColumnName   plain identifiers only
Data/  AccessLevel, AccessDecision, Entry, BackfillResult, AclRule
```

---

## What This Package Does NOT Do

- **No transaction** — `grantAll`, `reassignGroup` and `AclBackfill::run` run several statements; bracket them with `RunInTransaction`
- **No group, user, tenant or person model** — the package knows integers; who holds which group is your domain
- **No HTTP layer, no session, no events** — nothing is dispatched
- **No rejection** — `check()` returns `AccessDecision`; the status code is yours. `AclRule` only names the rule "the last entry with level all stays" (`NAME`, `MESSAGE_KEY`) for your 422
- **No table creation or migration** — you run the DDL; existing tables are not upgraded (see "The Table")
- **No query execution in `AclFilter`** — it builds `dbquery` objects only
- **No level beyond `view`, `edit`, `all`**, and no deny entries
- **No locking** — there is no row lock around the read in `check()` or `isLastFullEntry()`

---

## Development

```bash
cp .env.example .env    # One-time setup
make install             # Install dependencies
make phpunit             # Run tests (starts MariaDB, MySQL and PostgreSQL services; SQLite needs no service)
make phpstan             # Static analysis (Level 8)
make phpcs               # Coding standards (PSR-12)
```

---

## Documentation

Full documentation, guides, and API reference:

**[docs.jardis.io/en/support/acl](https://docs.jardis.io/en/support/acl)**

---

## License

[MIT License](LICENSE.md) — free for any use, including commercial.

<!-- BEGIN jardis/dev-skills README block — do not edit by hand -->
## AI-Assisted Development

This package ships with a skill for Claude Code, Cursor, Continue, and Aider. Install it in your consuming project:

```bash
composer require --dev jardis/dev-skills
```

More details: <https://docs.jardis.io/en/skills>
<!-- END jardis/dev-skills README block -->
