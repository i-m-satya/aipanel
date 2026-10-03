-- Shared hosting: many unrelated customers on one installation.
--
-- Three things change once strangers can sign up. Ownership has to be proved
-- rather than claimed — of the repository (via their own GitHub App
-- installation) and of the domain (via DNS) — otherwise one customer can point
-- someone else's hostname at their own site, or obtain a certificate for it.
-- Capacity has to be bounded per account. And a first site needs a human in the
-- loop, so abuse is not fully self-serve.

ALTER TABLE accounts
    ADD COLUMN max_sites SMALLINT UNSIGNED NOT NULL DEFAULT 3 AFTER plan,
    ADD COLUMN max_disk_mb INT UNSIGNED NOT NULL DEFAULT 2048 AFTER max_sites,
    ADD COLUMN trusted TINYINT(1) NOT NULL DEFAULT 0 AFTER max_disk_mb,
    ADD COLUMN suspended_reason VARCHAR(255) NULL AFTER status;

-- A site is provisioned only once it is both approved and domain-verified.
ALTER TABLE sites
    ADD COLUMN approval_state ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER status,
    ADD COLUMN approved_by BIGINT UNSIGNED NULL AFTER approval_state,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN rejected_reason VARCHAR(255) NULL AFTER approved_at,
    ADD KEY idx_sites_approval (approval_state, id);

-- Proof that the account controls the hostname, by DNS TXT record. Kept per
-- account so one customer verifying example.com does not let another use it.
CREATE TABLE domain_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    domain VARCHAR(253) NOT NULL,
    token CHAR(43) NOT NULL,
    verified_at DATETIME NULL,
    last_checked_at DATETIME NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_domain_account (account_id, domain),
    KEY idx_domain_verified (domain, verified_at),
    CONSTRAINT fk_domver_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Repositories each installation actually grants, refreshed from GitHub. A site
-- may only name a repository that appears here for its own account.
CREATE TABLE installation_repositories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installation_id BIGINT UNSIGNED NOT NULL,
    account_id BIGINT UNSIGNED NOT NULL,
    full_name VARCHAR(190) NOT NULL,
    private TINYINT(1) NOT NULL DEFAULT 0,
    synced_at DATETIME NOT NULL,
    UNIQUE KEY uq_install_repo (installation_id, full_name),
    KEY idx_install_repo_account (account_id, full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Counter-based rate limiting that works without Redis, so a single-server
-- install is still protected.
CREATE TABLE rate_limits (
    bucket VARCHAR(190) NOT NULL,
    window_started_at DATETIME NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Defaults for a shared instance: anyone may sign up, the first site of an
-- untrusted account needs approval.
INSERT INTO settings (name, value, updated_at) VALUES
    ('signup_mode', 'open', NOW()),
    ('site_approval', 'untrusted', NOW())
ON DUPLICATE KEY UPDATE value = value
