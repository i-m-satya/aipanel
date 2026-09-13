-- Bootstrap and access control for a freshly installed panel.
--
-- The first GitHub account to sign in owns the installation. After that,
-- signing in is not enough: a user must have been invited, so an installation
-- reachable on the public internet is not open to anyone with a GitHub account.

CREATE TABLE user_invites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id BIGINT UNSIGNED NOT NULL,
    github_login VARCHAR(190) NOT NULL,
    role ENUM('owner','user','admin') NOT NULL DEFAULT 'user',
    invited_by BIGINT UNSIGNED NULL,
    accepted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_invite_login (github_login),
    KEY idx_invite_account (account_id),
    CONSTRAINT fk_invite_account FOREIGN KEY (account_id) REFERENCES accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Installation-wide settings, written by the installer and the bootstrap.
CREATE TABLE settings (
    name VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
