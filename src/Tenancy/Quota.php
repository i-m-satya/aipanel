<?php

declare(strict_types=1);

namespace AIPanel\Tenancy;

use AIPanel\Infra\Database;

/**
 * Per-account capacity limits.
 *
 * On a shared instance an unbounded account is a denial-of-service waiting to
 * happen: sites are cheap to ask for and expensive to run. Limits are stored on
 * the account so they can be raised for a paying customer without a deploy.
 */
final class Quota
{
    public function __construct(private Database $db)
    {
    }

    /**
     * @return array{allowed:bool,reason:?string,used:int,limit:int}
     */
    public function canCreateSite(int $accountId): array
    {
        $account = $this->db->selectOne('SELECT * FROM accounts WHERE id = ?', [$accountId]);

        if ($account === null) {
            return ['allowed' => false, 'reason' => 'Account not found.', 'used' => 0, 'limit' => 0];
        }

        if (($account['status'] ?? 'active') !== 'active') {
            return [
                'allowed' => false,
                'reason' => 'This account is suspended' . ($account['suspended_reason'] ? ': ' . $account['suspended_reason'] : '.'),
                'used' => 0,
                'limit' => (int) $account['max_sites'],
            ];
        }

        // Count websites, not environments: one website is a production row plus
        // a sandbox row, and a customer should not be charged two of their three.
        $used = (int) ($this->db->selectOne(
            "SELECT COUNT(*) AS n FROM sites WHERE account_id = ? AND environment = 'production' AND approval_state <> 'rejected'",
            [$accountId]
        )['n'] ?? 0);

        $limit = (int) $account['max_sites'];

        if ($used >= $limit) {
            return [
                'allowed' => false,
                'reason' => sprintf('This account is limited to %d website%s.', $limit, $limit === 1 ? '' : 's'),
                'used' => $used,
                'limit' => $limit,
            ];
        }

        return ['allowed' => true, 'reason' => null, 'used' => $used, 'limit' => $limit];
    }

    /** Disk allowance for one website, split across its two environments. */
    public function diskQuotaMbFor(int $accountId): int
    {
        $account = $this->db->selectOne('SELECT max_disk_mb, max_sites FROM accounts WHERE id = ?', [$accountId]);
        $total = (int) ($account['max_disk_mb'] ?? 2048);
        $sites = max(1, (int) ($account['max_sites'] ?? 1));

        return max(256, intdiv($total, $sites * 2));
    }

    public function isTrusted(int $accountId): bool
    {
        $row = $this->db->selectOne('SELECT trusted FROM accounts WHERE id = ?', [$accountId]);

        return (bool) ($row['trusted'] ?? false);
    }
}
