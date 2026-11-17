-- jardissupport/acl: access entries table for AclStore (PostgreSQL)
-- One row = one group may see one record of one information kind up to a level
-- (1 = view, 2 = edit, 3 = all). Default table name: acl_entries.
CREATE TABLE IF NOT EXISTS acl_entries (
    kind      INT      NOT NULL,
    record_id BIGINT   NOT NULL,
    group_id  BIGINT   NOT NULL,
    level     SMALLINT NOT NULL,
    PRIMARY KEY (kind, record_id, group_id)
);
CREATE INDEX IF NOT EXISTS idx_acl_entries_group ON acl_entries (kind, group_id, level, record_id);
CREATE INDEX IF NOT EXISTS idx_acl_entries_group_id ON acl_entries (group_id);
