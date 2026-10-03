<?php

declare(strict_types=1);

namespace AIPanel\Tenancy;

use AIPanel\Infra\Database;

/**
 * Proof that an account controls a hostname, by DNS TXT record.
 *
 * On a shared instance this is not a formality. Without it a customer can point
 * any hostname they like at the panel — including one belonging to someone else
 * — and the panel would serve it and obtain a Let's Encrypt certificate for it.
 * So a domain is provisioned only after a TXT record proves control, and the
 * proof is recorded per account: one customer verifying example.com never lets
 * another use it.
 */
final class DomainVerifier
{
    public const RECORD_PREFIX = '_aipanel-challenge';

    /** @var callable(string): array<int,array<string,mixed>> */
    private $resolver;

    public function __construct(
        private Database $db,
        ?callable $resolver = null,
    ) {
        // Injectable so the matching logic can be tested without DNS.
        $this->resolver = $resolver ?? static fn (string $name): array => dns_get_record($name, DNS_TXT) ?: [];
    }

    /**
     * Start (or restart) verification for a domain, returning what the customer
     * must publish.
     *
     * @return array{domain:string,record:string,value:string,verified:bool}
     */
    public function challenge(int $accountId, string $domain): array
    {
        $domain = self::normalise($domain);

        $existing = $this->db->selectOne(
            'SELECT * FROM domain_verifications WHERE account_id = ? AND domain = ?',
            [$accountId, $domain]
        );

        if ($existing !== null) {
            return [
                'domain' => $domain,
                'record' => self::RECORD_PREFIX . '.' . $domain,
                'value' => (string) $existing['token'],
                'verified' => $existing['verified_at'] !== null,
            ];
        }

        // 32 random bytes, URL-safe: long enough that a token cannot be guessed
        // and published by someone else to steal the domain.
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->db->execute(
            'INSERT INTO domain_verifications (account_id, domain, token, created_at) VALUES (?, ?, ?, NOW())',
            [$accountId, $domain, $token]
        );

        return [
            'domain' => $domain,
            'record' => self::RECORD_PREFIX . '.' . $domain,
            'value' => $token,
            'verified' => false,
        ];
    }

    /**
     * Check DNS for the expected token.
     *
     * @return array{verified:bool,error:?string}
     */
    public function check(int $accountId, string $domain): array
    {
        $domain = self::normalise($domain);

        $row = $this->db->selectOne(
            'SELECT * FROM domain_verifications WHERE account_id = ? AND domain = ?',
            [$accountId, $domain]
        );

        if ($row === null) {
            return ['verified' => false, 'error' => 'No verification has been started for this domain.'];
        }

        if ($row['verified_at'] !== null) {
            return ['verified' => true, 'error' => null];
        }

        $records = ($this->resolver)(self::RECORD_PREFIX . '.' . $domain);
        $found = self::matches($records, (string) $row['token']);

        $this->db->execute(
            'UPDATE domain_verifications
             SET last_checked_at = NOW(), attempts = attempts + 1, last_error = ?,
                 verified_at = CASE WHEN ? = 1 THEN NOW() ELSE verified_at END
             WHERE id = ?',
            [
                $found ? null : 'TXT record not found or did not match.',
                $found ? 1 : 0,
                (int) $row['id'],
            ]
        );

        return [
            'verified' => $found,
            'error' => $found ? null : 'TXT record not found or did not match. DNS changes can take a few minutes.',
        ];
    }

    /** Has this account proved control of this domain? */
    public function isVerified(int $accountId, string $domain): bool
    {
        $row = $this->db->selectOne(
            'SELECT verified_at FROM domain_verifications
             WHERE account_id = ? AND domain = ? AND verified_at IS NOT NULL',
            [$accountId, self::normalise($domain)]
        );

        return $row !== null;
    }

    /**
     * Verifying the apex also covers its own subdomains, so a customer does not
     * have to prove `sandbox.example.com` separately — but only subdomains of a
     * domain THEY verified, never a sibling of someone else's.
     */
    public function isCovered(int $accountId, string $domain): bool
    {
        $domain = self::normalise($domain);

        if ($this->isVerified($accountId, $domain)) {
            return true;
        }

        $labels = explode('.', $domain);
        // Walk up to (but not into) the public suffix; two labels is as far as a
        // customer could ever legitimately own.
        for ($i = 1; $i <= count($labels) - 2; $i++) {
            if ($this->isVerified($accountId, implode('.', array_slice($labels, $i)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this hostname already taken by a different account?
     *
     * Two accounts must never both serve one hostname: the second would hijack
     * the first's traffic and certificate.
     */
    public function claimedByAnotherAccount(int $accountId, string $domain): bool
    {
        $row = $this->db->selectOne(
            'SELECT id FROM sites WHERE domain = ? AND account_id <> ?',
            [self::normalise($domain), $accountId]
        );

        return $row !== null;
    }

    /**
     * @param array<int,array<string,mixed>> $records
     */
    public static function matches(array $records, string $token): bool
    {
        // hash_equals('', '') is true, so a blank token would otherwise verify
        // any domain that publishes an empty TXT record.
        if (strlen($token) < 20) {
            return false;
        }

        foreach ($records as $record) {
            // A TXT record can arrive as `txt`, or split into `entries`.
            $candidates = [];
            if (isset($record['txt']) && is_string($record['txt'])) {
                $candidates[] = $record['txt'];
            }
            foreach ((array) ($record['entries'] ?? []) as $entry) {
                if (is_string($entry)) {
                    $candidates[] = $entry;
                }
            }

            foreach ($candidates as $candidate) {
                // Constant-time compare, and tolerate the quoting and whitespace
                // some DNS providers add.
                if (hash_equals($token, trim($candidate, " \t\"'"))) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function normalise(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $domain = explode('/', $domain)[0];

        // Strip a trailing :port, but not the colons inside an IPv6 literal.
        if (filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            $domain = preg_replace('#:\d+$#', '', $domain) ?? $domain;
        }

        return rtrim($domain, '.');
    }

    /**
     * Hostnames nobody may claim on a shared instance: the panel's own, and
     * anything that would let a customer impersonate infrastructure.
     */
    public static function isReserved(string $domain, string $panelHost = ''): bool
    {
        $domain = self::normalise($domain);

        if ($panelHost !== '' && ($domain === self::normalise($panelHost)
            || str_ends_with($domain, '.' . self::normalise($panelHost)))) {
            return true;
        }

        foreach (['localhost', 'local', 'internal', 'test', 'invalid', 'example'] as $suffix) {
            if ($domain === $suffix || str_ends_with($domain, '.' . $suffix)) {
                return true;
            }
        }

        // An IP address is not a hostname anyone can prove by DNS.
        return filter_var($domain, FILTER_VALIDATE_IP) !== false;
    }
}
