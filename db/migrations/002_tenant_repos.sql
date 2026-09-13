-- One website = one tenant: Linux user, GitHub repo, release history.

ALTER TABLE sites
    ADD COLUMN site_user VARCHAR(32) NOT NULL AFTER domain,
    ADD COLUMN repo VARCHAR(190) NULL AFTER site_user,
    ADD COLUMN github_installation_id BIGINT UNSIGNED NULL AFTER repo,
    ADD COLUMN deploy_branch VARCHAR(100) NOT NULL DEFAULT 'main' AFTER github_installation_id,
    ADD COLUMN document_root VARCHAR(64) NOT NULL DEFAULT 'public' AFTER deploy_branch,
    ADD COLUMN run_migrations TINYINT(1) NOT NULL DEFAULT 0 AFTER document_root,
    ADD COLUMN ai_autopilot TINYINT(1) NOT NULL DEFAULT 0 AFTER run_migrations,
    ADD COLUMN live_commit CHAR(40) NULL AFTER ai_autopilot,
    ADD COLUMN live_release VARCHAR(32) NULL AFTER live_commit,
    ADD COLUMN deployed_at DATETIME NULL AFTER live_release,
    ADD UNIQUE KEY uq_sites_site_user (site_user),
    ADD KEY idx_sites_repo (repo);

-- Immutable deploy history. One row per attempted release.
CREATE TABLE releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    commit_sha CHAR(40) NOT NULL,
    release_id VARCHAR(32) NULL,
    trigger_source ENUM('webhook','manual','scheduled','ai') NOT NULL DEFAULT 'webhook',
    triggered_by VARCHAR(190) NULL,
    state ENUM('queued','running','live','failed','rolled_back') NOT NULL DEFAULT 'queued',
    detail TEXT NULL,
    created_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    KEY idx_releases_site (site_id, id),
    KEY idx_releases_job (job_id),
    CONSTRAINT fk_releases_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SSH keys authorised for one tenant. A key never reaches another tenant.
CREATE TABLE ssh_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(190) NOT NULL,
    fingerprint VARCHAR(64) NOT NULL,
    public_key TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    UNIQUE KEY uq_ssh_site_fingerprint (site_id, fingerprint),
    CONSTRAINT fk_ssh_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GitHub App installations, one per customer organisation.
CREATE TABLE github_installations (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    github_account_login VARCHAR(190) NOT NULL,
    webhook_secret_encrypted TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_installations_account (account_id),
    CONSTRAINT fk_installations_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AI change requests: the only sanctioned way code changes.
CREATE TABLE change_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    site_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    requested_by BIGINT UNSIGNED NOT NULL,
    instruction TEXT NOT NULL,
    branch VARCHAR(190) NULL,
    pull_request_url VARCHAR(255) NULL,
    commit_sha CHAR(40) NULL,
    state ENUM('queued','working','review','merged','failed','abandoned') NOT NULL DEFAULT 'queued',
    checks_output MEDIUMTEXT NULL,
    session_ref VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    KEY idx_cr_site (site_id, id),
    KEY idx_cr_state (state),
    CONSTRAINT fk_cr_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Webhook deliveries, kept for replay and debugging. Unknown repos land here
-- and go no further.
CREATE TABLE webhook_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    delivery_id VARCHAR(64) NOT NULL,
    event VARCHAR(64) NOT NULL,
    repo VARCHAR(190) NULL,
    ref VARCHAR(190) NULL,
    commit_sha CHAR(40) NULL,
    outcome ENUM('deployed','ignored','unknown_repo','bad_signature') NOT NULL,
    received_at DATETIME NOT NULL,
    UNIQUE KEY uq_delivery (delivery_id),
    KEY idx_delivery_repo (repo, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One read-only deploy key per node, registered on the repos it hosts.
ALTER TABLE nodes
    ADD COLUMN deploy_public_key TEXT NULL AFTER secret_encrypted,
    ADD COLUMN deploy_key_fingerprint VARCHAR(64) NULL AFTER deploy_public_key
