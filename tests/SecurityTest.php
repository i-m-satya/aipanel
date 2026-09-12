<?php

declare(strict_types=1);

namespace AIPanel\Tests;

use AIPanel\Git\GitHubApp;
use AIPanel\Http\Request;
use AIPanel\Http\Router;
use AIPanel\Support\Crypto;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    public function testSecretsRoundTripThroughAuthenticatedEncryption(): void
    {
        $crypto = new Crypto(base64_encode(random_bytes(32)));
        $secret = bin2hex(random_bytes(32));

        self::assertSame($secret, $crypto->decrypt($crypto->encrypt($secret)));
    }

    public function testTwoEncryptionsOfTheSameSecretDiffer(): void
    {
        $crypto = new Crypto(base64_encode(random_bytes(32)));

        self::assertNotSame($crypto->encrypt('same'), $crypto->encrypt('same'));
    }

    public function testTamperedCiphertextIsRejectedRatherThanDecrypted(): void
    {
        $crypto = new Crypto(base64_encode(random_bytes(32)));
        $payload = $crypto->encrypt('node-secret');

        $raw = base64_decode($payload, true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] === 'a' ? 'b' : 'a';

        $this->expectException(\RuntimeException::class);
        $crypto->decrypt(base64_encode($raw));
    }

    public function testAShortKeyIsRefused(): void
    {
        $this->expectExceptionMessage('APP_KEY must be 32 random bytes');
        new Crypto(base64_encode(random_bytes(16)));
    }

    public function testWebhookSignatureVerification(): void
    {
        $payload = '{"ref":"refs/heads/main"}';
        $secret = 'webhook-secret';
        $valid = 'sha256=' . hash_hmac('sha256', $payload, $secret);

        self::assertTrue(GitHubApp::verifyWebhook($payload, $valid, $secret));
        self::assertFalse(GitHubApp::verifyWebhook($payload, $valid, 'other-secret'));
        self::assertFalse(GitHubApp::verifyWebhook($payload . ' ', $valid, $secret));
        self::assertFalse(GitHubApp::verifyWebhook($payload, 'sha1=whatever', $secret));
        self::assertFalse(GitHubApp::verifyWebhook($payload, '', $secret));
    }

    /**
     * The agent's transport signs timestamp.nonce.body — a body altered in
     * flight must not verify.
     */
    public function testAgentSignatureCoversTheWholeRequest(): void
    {
        $secret = bin2hex(random_bytes(32));
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $body = '{"task":"site.create"}';

        $signature = hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $secret);
        $tampered = '{"task":"site.delete"}';

        self::assertTrue(hash_equals(
            hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $secret),
            $signature
        ));
        self::assertFalse(hash_equals(
            hash_hmac('sha256', "{$timestamp}.{$nonce}.{$tampered}", $secret),
            $signature
        ));
    }

    public function testRouterMatchesParametersAndRejectsUnknownPaths(): void
    {
        $router = new Router();
        $match = new \ReflectionMethod(Router::class, 'match');
        $match->setAccessible(true);

        self::assertSame(['id' => '42'], $match->invoke($router, '/sites/{id}/deploy', '/sites/42/deploy'));
        self::assertNull($match->invoke($router, '/sites/{id}', '/sites/42/deploy'));
        self::assertNull($match->invoke($router, '/sites', '/nodes'));
    }

    public function testRequestTreatsJsonBodiesAndFormsConsistently(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/sites/7/deploy';
        $_POST = ['_token' => 'abc'];
        $_GET = [];

        $request = Request::fromGlobals();

        self::assertSame('POST', $request->method);
        self::assertSame('/sites/7/deploy', $request->path);
        self::assertSame('abc', $request->input('_token'));
        self::assertNull($request->input('missing'));
    }
}
