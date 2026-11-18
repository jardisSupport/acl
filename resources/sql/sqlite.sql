-- jardissupport/acl: access entries table for AclStore (SQLite)
-- One row = one group may see one record of one information kind up to a level
-- (1 = view, 2 = edit, 3 = all). Default table name: acl_entries.
CREATE TABLE IF NOT EXISTS acl_entries (
    kind      INTEGER NOT NULL,
    record_id INTEGER NOT NULL,
    group_id  INTEGER NOT NULL,
    level     INTEGER NOT NULL,
    PRIMARY KEY (kind, record_id, group_id)
);
CREATE INDEX IF NOT EXISTS idx_acl_entries_group_record ON acl_entries (kind, group_id, record_id, level);
CREATE INDEX IF NOT EXISTS idx_acl_entries_group_id ON acl_entries (group_id);
