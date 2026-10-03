<?php

declare(strict_types=1);

namespace AIPanel\Tests;

use AIPanel\Domain\SiteRepository;
use AIPanel\Tenancy\DomainVerifier;
use AIPanel\Tenancy\RepositoryAccess;
use PHPUnit\Framework\TestCase;

/**
 * On a shared instance these rules are the difference between hosting and
 * handing strangers each other's traffic. They are tested as rules, not as
 * incidental behaviour of a controller.
 */
final class SharedHostingTest extends TestCase
{
    // ---------------------------------------------------- domain ownership

    public function testTxtRecordMustMatchExactly(): void
    {
        $token = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFG';

        self::assertTrue(DomainVerifier::matches([['txt' => $token]], $token));
        self::assertFalse(DomainVerifier::matches([['txt' => $token . 'x']], $token));
        self::assertFalse(DomainVerifier::matches([['txt' => substr($token, 0, -1)]], $token));
        self::assertFalse(DomainVerifier::matches([], $token));
    }

    /** Providers wrap, pad and split TXT values; none of that should break it. */
    public function testTolerantOfProviderFormatting(): void
    {
        $token = 'tok' . str_repeat('A', 40);

        self::assertTrue(DomainVerifier::matches([['txt' => '"' . $token . '"']], $token));
        self::assertTrue(DomainVerifier::matches([['txt' => "  {$token}  "]], $token));
        self::assertTrue(DomainVerifier::matches([['entries' => ['other', $token]]], $token));
        self::assertTrue(DomainVerifier::matches([['txt' => 'unrelated'], ['txt' => $token]], $token));
    }

    public function testEmptyTokenNeverMatches(): void
    {
        // A bug that left the token blank must not verify every domain.
        self::assertFalse(DomainVerifier::matches([['txt' => '']], ''));
        self::assertFalse(DomainVerifier::matches([['txt' => 'anything']], ''));
    }

    public function testDomainNormalisation(): void
    {
        foreach ([
            'Example.COM' => 'example.com',
            'https://example.com/path' => 'example.com',
            'example.com.' => 'example.com',
            '  example.com ' => 'example.com',
        ] as $input => $expected) {
            self::assertSame($expected, DomainVerifier::normalise($input));
        }
    }

    public function testReservedHostnamesCannotBeClaimed(): void
    {
        // The panel's own hostname: claiming it would let a tenant serve the
        // login page and harvest GitHub sessions.
        self::assertTrue(DomainVerifier::isReserved('panel.example.com', 'http://panel.example.com:2087'));
        self::assertTrue(DomainVerifier::isReserved('sub.panel.example.com', 'http://panel.example.com:2087'));

        self::assertTrue(DomainVerifier::isReserved('localhost'));
        self::assertTrue(DomainVerifier::isReserved('thing.localhost'));
        self::assertTrue(DomainVerifier::isReserved('box.internal'));
        self::assertTrue(DomainVerifier::isReserved('192.168.1.10'));
        self::assertTrue(DomainVerifier::isReserved('2001:db8::1'));

        self::assertFalse(DomainVerifier::isReserved('customer.com', 'http://panel.example.com:2087'));
        // A hostname that merely ends with similar text is a different domain.
        self::assertFalse(DomainVerifier::isReserved('notpanel.example.com.au', 'http://panel.example.com:2087'));
    }

    // ------------------------------------------------- repository ownership

    public function testRepositoryNormalisation(): void
    {
        foreach ([
            'https://github.com/acme/site' => 'acme/site',
            'https://www.github.com/acme/site.git' => 'acme/site',
            'acme/site.git' => 'acme/site',
            ' acme/site ' => 'acme/site',
            '/acme/site/' => 'acme/site',
        ] as $input => $expected) {
            self::assertSame($expected, RepositoryAccess::normalise($input));
        }
    }

    public function testOnlyWellFormedRepositoriesAreAccepted(): void
    {
        foreach (['acme/site', 'a/b', 'acme-org/my.site_1', 'Acme/Site'] as $ok) {
            self::assertTrue(RepositoryAccess::isWellFormed($ok), "{$ok} should be accepted");
        }

        foreach ([
            '',                      // empty
            'acme',                  // no name
            'acme/site/extra',       // not owner/name
            '../../etc/passwd',      // traversal
            'acme/../other',         // traversal
            'acme/site;rm -rf /',    // shell metacharacters
            '-acme/site',            // owner cannot start with a dash
            'acme/si te',            // whitespace
        ] as $bad) {
            self::assertFalse(RepositoryAccess::isWellFormed($bad), "{$bad} should be rejected");
        }
    }

    // ----------------------------------------------------- approval gate

    /**
     * The approval gate must hold on every path. A pending site has rows, and
     * the deploy/rollback/promote routes take a site id.
     */
    public function testOnlyApprovedSitesAreDeployable(): void
    {
        $sites = (new \ReflectionClass(SiteRepository::class))->newInstanceWithoutConstructor();

        self::assertTrue($sites->isDeployable(['approval_state' => 'approved']));
        self::assertFalse($sites->isDeployable(['approval_state' => 'pending']));
        self::assertFalse($sites->isDeployable(['approval_state' => 'rejected']));

        // A row from before this column existed is treated as approved, so an
        // upgrade does not silently stop every existing site.
        self::assertTrue($sites->isDeployable([]));
    }

    /**
     * The container name is derived from the validated site user, never from
     * anything a customer typed — otherwise it is an argument-injection hole in
     * a command that runs as root.
     */
    public function testContainerNamesAreDerivedNotSupplied(): void
    {
        require_once dirname(__DIR__) . '/agent/lib/Exec.php';
        require_once dirname(__DIR__) . '/agent/lib/Container.php';

        self::assertSame('aipanel-site_8f3ab1c2', \Container::name('site_8f3ab1c2'));
        self::assertSame('aipanel-net-site_8f3ab1c2', \Container::network('site_8f3ab1c2'));
    }
}
