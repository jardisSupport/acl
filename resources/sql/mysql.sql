-- jardissupport/acl: access entries table for AclStore (MySQL / MariaDB)
-- One row = one group may see one record of one information kind up to a level
-- (1 = view, 2 = edit, 3 = all). Default table name: acl_entries.
CREATE TABLE IF NOT EXISTS acl_entries (
    kind      INT      NOT NULL,
    record_id BIGINT   NOT NULL,
    group_id  BIGINT   NOT NULL,
    level     SMALLINT NOT NULL,
    PRIMARY KEY (kind, record_id, group_id),
    KEY idx_acl_entries_group (kind, group_id, level, record_id),
    KEY idx_acl_entries_group_id (group_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
