-- aipanel initial schema

CREATE TABLE accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    plan VARCHAR(64) NOT NULL DEFAULT 'default',
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Identity comes from GitHub only: there are no passwords in aipanel.
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    github_id BIGINT UNSIGNED NOT NULL,
    github_login VARCHAR(190) NOT NULL,
    email VARCHAR(190) NULL,
    name VARCHAR(190) NULL,
    avatar_url VARCHAR(255) NULL,
    role ENUM('owner','user','admin') NOT NULL DEFAULT 'user',
    status ENUM('active','disabled') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_github_id (github_id),
    UNIQUE KEY uq_users_github_login (github_login),
    KEY idx_users_email (email),
    KEY idx_users_account (account_id),
    CONSTRAINT fk_users_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE nodes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    hostname VARCHAR(190) NOT NULL,
    endpoint VARCHAR(255) NOT NULL,
    role ENUM('web','mysql','dns','mail') NOT NULL,
    secret_encrypted TEXT NOT NULL,
    status ENUM('unknown','online','unreachable','draining') NOT NULL DEFAULT 'unknown',
    facts JSON NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_nodes_hostname (hostname),
    KEY idx_nodes_role_status (role, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE sites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    domain VARCHAR(253) NOT NULL,
    php_version VARCHAR(8) NOT NULL DEFAULT '8.3',
    status ENUM('provisioning','active','suspended','failed') NOT NULL DEFAULT 'provisioning',
    ssl_status ENUM('none','active','failed') NOT NULL DEFAULT 'none',
    ssl_renewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_sites_domain (domain),
    KEY idx_sites_account (account_id),
    KEY idx_sites_node (node_id),
    CONSTRAINT fk_sites_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_sites_node FOREIGN KEY (node_id) REFERENCES nodes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE databases_managed (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    site_id BIGINT UNSIGNED NULL,
    name VARCHAR(64) NOT NULL,
    username VARCHAR(32) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_db_node_name (node_id, name),
    KEY idx_db_account (account_id),
    CONSTRAINT fk_db_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Queue. Claimed with FOR UPDATE SKIP LOCKED under a time-bounded lease, so
-- workers scale horizontally without a broker and crashed workers self-heal.
CREATE TABLE jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    node_id BIGINT UNSIGNED NOT NULL,
    task VARCHAR(64) NOT NULL,
    params JSON NOT NULL,
    idempotency_key CHAR(64) NOT NULL,
    plan_id CHAR(36) NULL,
    requested_by BIGINT UNSIGNED NULL,
    priority SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    state ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    lease_owner VARCHAR(64) NULL,
    lease_expires_at DATETIME NULL,
    run_after DATETIME NOT NULL,
    result JSON NULL,
    error TEXT NULL,
    created_at DATETIME NOT NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    KEY idx_jobs_claim (state, run_after, priority, id),
    KEY idx_jobs_node_state (node_id, state),
    KEY idx_jobs_account (account_id, id),
    KEY idx_jobs_lease (state, lease_expires_at),
    KEY idx_jobs_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE plans (
    id CHAR(36) NOT NULL PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    summary TEXT NOT NULL,
    steps JSON NOT NULL,
    state ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    decided_at DATETIME NULL,
    KEY idx_plans_account (account_id, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    job_id BIGINT UNSIGNED NULL,
    node_id BIGINT UNSIGNED NULL,
    task VARCHAR(64) NOT NULL,
    params JSON NULL,
    outcome ENUM('ok','error') NOT NULL,
    detail JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_audit_account (account_id, id),
    KEY idx_audit_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Single-row-per-name advisory locks for the leader-elected scheduler when
-- Redis is not deployed.
CREATE TABLE leader_locks (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    owner VARCHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
