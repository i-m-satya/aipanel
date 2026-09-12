<?php

declare(strict_types=1);

namespace AIPanel\Domain;

use AIPanel\Infra\Database;

/**
 * Users are GitHub identities. aipanel stores no credentials of its own — the
 * GitHub account id is the primary identity, and revoking access in GitHub
 * revokes access here.
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
     * Upsert on GitHub account id, not login: logins can be renamed, ids
     * cannot, so a renamed user keeps their sites instead of creating a
     * duplicate account.
     *
     * @param array{id:int,login:string,name:string,email:?string,avatar_url:string} $profile
     * @return array<string,mixed> the stored user row
     */
    public function upsertFromGithub(array $profile, ?int $accountId = null): array
    {
        $existing = $this->findByGithubId($profile['id']);

        if ($existing !== null) {
            $this->db->execute(
                'UPDATE users
                 SET github_login = ?, email = ?, name = ?, avatar_url = ?, last_login_at = NOW()
                 WHERE id = ?',
                [$profile['login'], $profile['email'], $profile['name'], $profile['avatar_url'], (int) $existing['id']]
            );

            return (array) $this->find((int) $existing['id']);
        }

        // First login creates the user's own account, unless they were invited
        // into an existing one. Whoever creates the account owns it; an invited
        // user joins as a plain member.
        $invited = $accountId !== null;
        $accountId ??= $this->createAccountFor($profile['login']);

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
                $invited ? 'user' : 'owner',
            ]
        );

        return (array) $this->find($this->db->lastInsertId());
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
