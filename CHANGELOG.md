# Changelog

All notable changes to `jardissupport/acl` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioning follows [SemVer](https://semver.org/).

## [Unreleased]

### Added

- **`AclFilter::COUNT_CAP`** (`int`, `10000`) — fixed limit of the capped total of a filtered list: the total is at most
  `COUNT_CAP`; above it the answer reports `COUNT_CAP` and carries a marker that the total is capped.

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
