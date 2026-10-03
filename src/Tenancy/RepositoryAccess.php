<?php

declare(strict_types=1);

namespace AIPanel\Tenancy;

use AIPanel\Git\GitHubApp;
use AIPanel\Git\GitHubException;
use AIPanel\Infra\Database;

/**
 * Proof that an account may use a repository.
 *
 * The rule on a shared instance: a site may only name a repository that the
 * account's own GitHub App installation grants. Without this check a customer
 * could type any public repository — someone else's — and have the panel deploy
 * it, register deploy keys on it, and (with write permissions) merge into it.
 *
 * GitHub is the authority. The local table is a cache for listing repositories
 * in the UI; every site creation re-checks against the API, because an
 * installation's repository selection can change at any time.
 */
final class RepositoryAccess
{
    public function __construct(
        private Database $db,
        private GitHubApp $github,
    ) {
    }

    /**
     * Installations belonging to one account.
     *
     * @return list<array<string,mixed>>
     */
    public function installationsFor(int $accountId): array
    {
        return $this->db->select(
            'SELECT * FROM github_installations WHERE account_id = ? ORDER BY id',
            [$accountId]
        );
    }

    /**
     * Which installation of this account grants this repository?
     *
     * @throws AccessDeniedException when none does
     */
    public function installationGranting(int $accountId, string $repo): int
    {
        $repo = self::normalise($repo);

        if (!self::isWellFormed($repo)) {
            throw new AccessDeniedException("'{$repo}' is not a valid owner/name repository.");
        }

        $installations = $this->installationsFor($accountId);
        if ($installations === []) {
            throw new AccessDeniedException(
                'This account has no GitHub App installation yet. Install the app on the account or organisation that owns the repository.'
            );
        }

        $errors = [];
        foreach ($installations as $installation) {
            $installationId = (int) $installation['id'];

            try {
                // Asks GitHub, as this installation, for this repository. A
                // repository the installation does not cover answers 404 —
                // which is the answer we want for "not yours".
                $this->github->repository($installationId, $repo);

                $this->remember($installationId, $accountId, $repo);

                return $installationId;
            } catch (GitHubException $e) {
                $errors[] = $e->getMessage();
            }
        }

        throw new AccessDeniedException(sprintf(
            "Your GitHub App installation does not grant access to %s. Open the app's configuration on GitHub and add that repository to its selected repositories.",
            $repo
        ));
    }

    /** Refresh the cached repository list for one installation. */
    public function sync(int $installationId, int $accountId, array $repositories): void
    {
        foreach ($repositories as $repository) {
            $fullName = is_array($repository) ? (string) ($repository['full_name'] ?? '') : (string) $repository;
            if (!self::isWellFormed($fullName)) {
                continue;
            }

            $this->remember(
                $installationId,
                $accountId,
                $fullName,
                is_array($repository) ? (bool) ($repository['private'] ?? false) : false
            );
        }
    }

    public function forget(int $installationId, string $repo): void
    {
        $this->db->execute(
            'DELETE FROM installation_repositories WHERE installation_id = ? AND full_name = ?',
            [$installationId, self::normalise($repo)]
        );
    }

    /** @return list<array<string,mixed>> */
    public function cachedFor(int $accountId): array
    {
        return $this->db->select(
            'SELECT full_name, private, installation_id FROM installation_repositories
             WHERE account_id = ? ORDER BY full_name',
            [$accountId]
        );
    }

    private function remember(int $installationId, int $accountId, string $repo, bool $private = false): void
    {
        $this->db->execute(
            'INSERT INTO installation_repositories (installation_id, account_id, full_name, private, synced_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE account_id = VALUES(account_id), private = VALUES(private), synced_at = NOW()',
            [$installationId, $accountId, self::normalise($repo), $private ? 1 : 0]
        );
    }

    public static function normalise(string $repo): string
    {
        $repo = trim($repo);
        $repo = preg_replace('#^(https?://)?(www\.)?github\.com/#i', '', $repo) ?? $repo;
        $repo = preg_replace('#\.git$#i', '', $repo) ?? $repo;

        return trim($repo, '/');
    }

    public static function isWellFormed(string $repo): bool
    {
        return preg_match('#^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,98}[A-Za-z0-9])?/[A-Za-z0-9._-]{1,100}$#', $repo) === 1
            && !str_contains($repo, '..');
    }
}
