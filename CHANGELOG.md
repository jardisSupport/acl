# Changelog

All notable changes to `jardissupport/acl` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [Unreleased]

## [1.2.2] - 2026-11-25

### Fixed

- **README only, no code change.** The `keepsFullEntryWith()` example now states its real result (`true`: group 11 holds
  `all`), the installation no longer asks for the GitHub source (the package is listed on Packagist), and the progress
  example imports `BackfillResult`.

## [1.2.1] - 2026-11-25

### Fixed

- **Backfill: `0` in the creator column counts as "no creator"**, like null and the empty text. A whole-number
  "created by" column holds `0` for "no signed-in person"; it was taken for a creator, never resolved, and `judge()`
  refused the pass although a fallback group existed. Now such records go to the fallback group (or are skipped
  without one) and are not counted by `BackfillResult::withCreator()`. No new parameter, no API change; one
  condition in `Handler/Backfill/BackfillEntries`. The creator arrives as text, so a text creator column holding
  `'0'` also counts as "no creator".

## [1.2.0] - 2026-11-25

### Added

- **`AclBackfill::run(..., int $batch = 1000, ?Closure $progress = null)`** — optional last argument
  `Closure(BackfillResult): void`. After each page that was read and written, the closure gets a `BackfillResult` with
  the running totals up to and including that page (the last call equals the returned result). It names no total and no
  duration: the number of records is not known beforehand. Without a closure `run` behaves as before; an empty source
  does not call it; an exception thrown by it flies through and is not swallowed. Closure `Handler/Backfill/BackfillEntries`.

## [1.1.0] - 2026-11-24

### Added

- **`AclStore::keepsFullEntryWith(int $kind, int $recordId, list<Entry> $entries): bool`** — the verdict over the state
  AFTER setting the entries: true exactly when at least one group then holds level `all` on the record, in the list or
  outside it (a group named twice counts with its last level, as `grantAll`). Reads, writes nothing. Closure
  `Handler/Entry/KeepsFullEntryWith`.
- **`AclStore::keepsFullEntryWithout(int $kind, int $recordId, list<int> $groups): bool`** — the verdict over the state
  AFTER removing the groups: true exactly when a group outside the list holds level `all`; `[]` asks for the present
  state. Closure `Handler/Entry/KeepsFullEntryWithout`. For a call naming several groups `isLastFullEntry` is not enough:
  when two groups hold `all` and the call names both, neither is "the last", yet no `all` stays. The caller puts verdict
  and write into ONE transaction.
- **`AclStore::revokeAll(int $kind, int $recordId, list<int> $groups): void`** — removes the entries of several groups;
  a group without an entry or named twice is harmless, `[]` writes nothing; one statement per 500 groups, no transaction.
  Closure `Handler/Entry/RevokeEntries`.
- **`AclStore::entriesOfWords(array<int, string> $words): WordEntries`** — turns pairs group -> level word into
  `list<Entry>` and names the groups without a valid word (`''`, other casing, any other word are invalid); throws
  nothing, needs no database. Closure `Handler/Entry/ResolveWords`.
- **`Data\WordEntries`** (`final readonly`) with `list<Entry> $entries` and `list<int> $invalid`.
- **`AclBackfill::judge(BackfillResult ...$results): void`** — the verdict over all runs of one pass: records with a
  creator were met and not one creator resolved to a group (sum over the results) -> `BackfillUnresolved`. No record
  with a creator, or one resolved creator, lets the pass stand. Closure `Handler/Backfill/JudgeBackfill`; writes and
  brackets nothing. `run` never throws it; the caller puts the runs and the verdict into ONE transaction
  (`jardissupport/repository` `RunInTransaction`) so the exception rolls every row of the pass back.
- **`Exception\BackfillUnresolved`** (`RuntimeException`) with the public `$withCreator` (records with a creator, summed).
- **`BackfillResult::withCreator()` and `resolved()`** — records met that carry a creator (not `null`, not `''`), and of
  those the ones that got the group of their creator. Two new constructor arguments at the end, default `0`.
- **Fallback group per tenant:** `AclBackfill::run` takes `$fallbackGroup` as `int|Closure|null`; the closure
  `(?string $tenant): ?int` names the group of a tenant (`null`: none, that tenant's records are skipped). It is asked
  at most once per tenant and batch, and only when a record of that tenant needs it.

All additive: `run` with a number or `null` as fallback group behaves as in 1.0.0. A second pass over records that stayed
without a group (no fallback group) sees only them and is refused again; it writes nothing.

### Fixed

- **`AclBackfill::run` works for every integer key column.** The first page read had a lower bound at `PHP_INT_MIN`,
  which PostgreSQL refused on a `SMALLINT`/`INTEGER` key (`SQLSTATE[22003]` out of range) and which skipped a key of
  exactly `PHP_INT_MIN` on a `BIGINT` column. The first page now has no lower bound; each following page continues
  after the last key seen. Negative keys, the key `0` and the ends of the type's range are backfilled on SQLite,
  MySQL, MariaDB and PostgreSQL.
- **`AclBackfill::run` takes linear time instead of quadratic time.** Each page bounded only the records read; the
  lookup of existing entries stayed unbounded, so PostgreSQL read every entry of the kind written so far on each page
  and the time per page grew with the progress of the run. From the second page on, the lookup carries the same lower
  bound (`record_id` after the last key seen) as the records: every page reads only its own key range, on both sides.
  Result unchanged. PostgreSQL 16, 1,000,000 records, pages of 1,000: 60 s before, 9 s after; the read time per page
  is flat (median 1 ms, before 52 ms, up to 143 ms).

## [1.0.0] - 2026-11-19

### Changed

- **Stable API.** The public API of 0.3.0 (`AclStore`, `AclFilter::existsQuery`, `AclFilter::COUNT_CAP`, `AclRule`,
  `AclBackfill`, `Entry`, `AccessLevel`, the DDL in `resources/sql/`) is now covered by SemVer: breaking changes only in a
  new major version. No code change against 0.3.0; verified by a consumer (generated code of the Jardis Builder).
- README: installation from the GitHub source until the package is listed on Packagist.

## [0.3.0] - 2026-11-18

### Changed

- **`AclFilter`** has one public method, `existsQuery(int $kind, array $groups, AccessLevel $min, string $keyColumn)`:
  the subquery for `jardissupport/dbquery` `exists()` (`SELECT 1 FROM <table> WHERE kind = ? AND record_id = <outer key>
  AND group_id IN (...) AND level >= ?`, no limit, no distinct), closure `Handler/Filter/BuildExistsQuery`.
- **Index `idx_acl_entries_group` is replaced by `idx_acl_entries_group_record`** `(kind, group_id, record_id, level)`
  (equality columns first, join column, range column last) in `resources/sql/{mysql,pgsql,sqlite}.sql`. An existing table
  is not upgraded: drop the old index, create the new one (README, "Existing tables are not upgraded").

### Removed

- **`AclFilter::rowCondition`** and **`AclFilter::recordIds`**, with the closures `Handler/Filter/BuildRowCondition` and
  `Handler/Filter/BuildRecordIds`. Use `existsQuery` with `->exists(...)`.

## [0.2.0] - 2026-11-18

### Added

- **`AclFilter::COUNT_CAP`** (`int`, `10000`) — fixed limit of the capped total of a filtered list: the total is at most
  `COUNT_CAP`; above it the answer reports `COUNT_CAP` and carries a marker that the total is capped. Its comment names
  the counting form: inside `SELECT 1` with the conditions of the page, without column list, without sorting,
  `limit(COUNT_CAP + 1)`; outside `COUNT(*)`.

## [0.1.0] - 2026-11-18

### Added

- **`AclStore`** — per-record access entries (information kind, record, group, level) on a SQL table:
  `levelOf`, `check` (three-valued `AccessDecision`: `Granted`, `NotVisible`, `TooLow`), `grant`, `grantAll`,
  `revoke`, `isLastFullEntry`, `removeRecord`, `reassignGroup` (the higher level stays on collision),
  `hasEntriesOfGroup`, `entriesOf`. Opens no transaction; the caller brackets calls that must succeed or fail together.
- **`AclFilter`** — builds `jardissupport/dbquery` objects that restrict a record query to the records a person may see:
  `rowCondition` (single-value subquery bound to the outer key column) and `recordIds` (key set). Needs no connection,
  runs nothing.
- **`AclBackfill`** — gives every record of a source table without an entry one entry "all", for the group of its
  creator (per tenant) or else for a fallback group; returns a `BackfillResult` (`written`, `toFallback`, `skipped`).
- **Data classes** — `AccessLevel` (`View`, `Edit`, `All`; words `view`, `edit`, `all` via `word()` and `fromWord()`),
  `AccessDecision`, `Entry`, `BackfillResult`, `AclRule` (name and message key of the rule "the last entry with level
  all stays").
- **Table DDL** for MySQL / MariaDB, PostgreSQL and SQLite in `resources/sql/` (default table `acl_entries`, primary key
  `(kind, record_id, group_id)`, indexes `idx_acl_entries_group` and `idx_acl_entries_group_id`). An existing table
  does not get a new index by itself.
- Skill `support-acl` for AI-assisted development.
