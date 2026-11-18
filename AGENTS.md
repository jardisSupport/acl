# jardissupport/acl

Per-record access entries (information kind, record, group, level) on a SQL table — grant, revoke, level lookup, three-valued access check, group reassignment, dbquery filters that restrict a record query to visible records, and backfill of existing records. No HTTP layer, no group or user model, no framework coupling, no transaction of its own. Three Orchestrators following the Closure-Orchestrator Pattern: `AclStore`, `AclFilter`, `AclBackfill`.

## Usage essentials

- **Strict Orchestrator Pattern:** Three Orchestrators in `src/` root compose themselves from Closures (`src/Handler/{Category}/`) via `->__invoke(...)` binding in the constructor. Orchestrators have **zero business logic** — only delegation.
- **`AclStore($connection, $table = 'acl_entries')`** (`DbConnectionInterface`): `levelOf($kind, $recordId, $groups): ?AccessLevel` (highest level of the groups, `null` = no entry), `check(..., AccessLevel $required): AccessDecision` (`Granted` / `NotVisible` / `TooLow` — the caller branches, never compares), `grant`, `grantAll(list<Entry>)`, `revoke`, `isLastFullEntry`, `removeRecord(): int`, `reassignGroup($from, $to): int` (higher level stays on collision), `hasEntriesOfGroup`, `entriesOf(): list<Entry>`. `$kind`, `$recordId`, `$group` are `int`, `$groups` is `list<int>`.
- **`AclFilter($table = 'acl_entries')`** needs no connection and runs nothing: `existsQuery($kind, $groups, $min, $keyColumn)` builds the subquery for `->exists(...)` (name the key column with the outer alias, `d.id`). Empty group list sees nothing. Needs `jardissupport/dbquery` >= 1.3.1.
- **`AclBackfill($connection, $table)->run($kind, $sourceTable, $keyColumn, ?$creatorColumn, ?$tenantColumn, Closure $groupOfCreator, ?$fallbackGroup, $batch = 1000): BackfillResult`** writes one entry `all` per record without an entry; `$groupOfCreator(?string $tenant, list<string> $creators): array<string, int>`; no group found and no fallback → record skipped. Second run writes nothing.
- **`AccessLevel`** is an int enum (`View = 1`, `Edit = 2`, `All = 3`); the words `view`, `edit`, `all` via `word()` / `fromWord()` (anything else, also other casing → `null`).
- **No transaction of its own:** `grantAll`, `reassignGroup` and `AclBackfill::run` run several statements; the caller brackets them (`jardissupport/repository` `RunInTransaction`).
- **Table:** `resources/sql/{mysql,pgsql,sqlite}.sql` (`acl_entries`; primary key `(kind, record_id, group_id)`; indexes `idx_acl_entries_group_record (kind, group_id, record_id, level)` and `idx_acl_entries_group_id (group_id)`). The package does not run the DDL; an existing table does not get a new index by itself.
- **Rejection is the caller's:** `AclRule::NAME` / `AclRule::MESSAGE_KEY` name the rule "the last entry with level all stays"; `acl` only answers `isLastFullEntry`.

## Full reference

https://docs.jardis.io/en/support/acl
