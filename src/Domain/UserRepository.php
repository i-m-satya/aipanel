<?php

declare(strict_types=1);

namespace AIPanel\Domain;

use AIPanel\Infra\Database;

/**
 * Users are GitHub identities. aipanel stores no credentials of its own — the
 * GitHub account id is the primary identity, and revoking access in GitHub
 * revokes access here.
 *
 * Bootstrap rule: the first GitHub account to sign in on a fresh installation
 * becomes its admin. Everyone after that must have been invited — otherwise a
 * panel reachable on the internet would be open to anyone with a GitHub
 * account.
 */
final class UserRepository
{
    public function __construct(private Database $db)
    {
    }

    public function find(int $id): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findByGithubId(int $githubId): ?array
    {
        return $this->db->selectOne('SELECT * FROM users WHERE github_id = ?', [$githubId]);
    }

    /**
     * Resolve a GitHub profile to a panel user, applying the bootstrap rule.
     *
     * Upserts on GitHub account id, not login: logins can be renamed, ids
     * cannot, so a renamed user keeps their sites instead of becoming a
     * duplicate.
     *
     * @param array{id:int,login:string,name:string,email:?string,avatar_url:string} $profile
     * @return array<string,mixed>|null the user row, or null when this GitHub
     *                                  account has no access to this panel
     */
    public function resolveFromGithub(array $profile): ?array
    {
        $existing = $this->findByGithubId($profile['id']);

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE users
                 SET github_login = ?, email = ?, name = ?, avatar_url = ?, last_login_at = NOW()
                 WHERE id = ?',
                [$profile['login'], $profile['email'], $profile['name'], $profile['avatar_url'], (int) $existing['id']]
            );

            return $this->find((int) $existing['id']);
        }

        // First ever sign-in claims the installation.
        if ($this->isUnclaimed()) {
            return $this->createUser($profile, $this->createAccountFor($profile['login']), 'admin', claimsInstallation: true);
        }

        // Everyone else needs an invite.
        $invite = $this->db->selectOne(
            'SELECT * FROM user_invites WHERE github_login = ? AND accepted_at IS NULL',
            [$profile['login']]
        );

        if ($invite === null) {
            return null;
        }

        $user = $this->createUser($profile, (int) $invite['account_id'], (string) $invite['role']);

        $this->db->execute('UPDATE user_invites SET accepted_at = NOW() WHERE id = ?', [(int) $invite['id']]);

        return $user;
    }

    /**
     * True until the first user claims this installation.
     *
     * If the database cannot be reached, report the installation as claimed:
     * the login page must not offer "sign in to become the administrator" when
     * it cannot actually tell whether an admin already exists.
     */
    public function isUnclaimed(): bool
    {
        try {
            $row = $this->db->selectOne('SELECT COUNT(*) AS n FROM users');
        } catch (\PDOException) {
            return false;
        }

        return (int) ($row['n'] ?? 0) === 0;
    }

    public function invite(string $githubLogin, int $accountId, string $role, int $invitedBy): void
    {
        $this->db->execute(
            'INSERT INTO user_invites (account_id, github_login, role, invited_by, created_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), role = VALUES(role), accepted_at = NULL',
            [$accountId, strtolower($githubLogin), $role, $invitedBy]
        );
    }

    /**
     * @param array{id:int,login:string,name:string,email:?string,avatar_url:string} $profile
     * @return array<string,mixed>
     */
    private function createUser(array $profile, int $accountId, string $role, bool $claimsInstallation = false): array
    {
        $this->db->execute(
            'INSERT INTO users (account_id, github_id, github_login, email, name, avatar_url, role, last_login_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $accountId,
                $profile['id'],
                $profile['login'],
                $profile['email'],
                $profile['name'],
                $profile['avatar_url'],
                $role,
            ]
        );

        $userId = $this->db->lastInsertId();

        if ($claimsInstallation) {
            $this->db->execute(
                "INSERT INTO settings (name, value, updated_at) VALUES ('installation_admin', ?, NOW())
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW()",
                [(string) $userId]
            );
        }

        return (array) $this->find($userId);
    }

    public function isActive(array $user): bool
    {
        return ($user['status'] ?? 'active') === 'active';
    }

    private function createAccountFor(string $login): int
    {
        $this->db->execute(
            "INSERT INTO accounts (name, plan, status, created_at) VALUES (?, 'default', 'active', NOW())",
            [$login]
        );

        return $this->db->lastInsertId();
    }
}
