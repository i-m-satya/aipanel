-- Every website gets two environments that are otherwise ordinary tenants:
--
--   sandbox.example.com  ← the 'sandbox' branch
--   example.com          ← the 'main' branch
--
-- Because a deploy is already keyed on "this site's deploy branch", a sandbox
-- needs no separate delivery path: it is a second site row on the same repo
-- watching a different branch, with its own jailed user, pool and database.
-- Promoting is then a merge of sandbox into main, and the production deploy
-- happens through the same webhook as any other push.

ALTER TABLE sites
    ADD COLUMN environment ENUM('production','sandbox') NOT NULL DEFAULT 'production' AFTER domain,
    ADD COLUMN parent_site_id BIGINT UNSIGNED NULL AFTER environment,
    ADD KEY idx_sites_parent (parent_site_id),
    ADD KEY idx_sites_repo_branch (repo, deploy_branch);

-- Audit of promotions: who pressed "make it live", what was promoted, and the
-- merge commit that carried it to production.
CREATE TABLE promotions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    promoted_by BIGINT UNSIGNED NOT NULL,
    from_branch VARCHAR(100) NOT NULL,
    to_branch VARCHAR(100) NOT NULL,
    from_commit CHAR(40) NOT NULL,
    merge_commit CHAR(40) NULL,
    state ENUM('merged','up_to_date','conflict','failed') NOT NULL,
    detail TEXT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_promotions_site (site_id, id),
    CONSTRAINT fk_promotions_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
