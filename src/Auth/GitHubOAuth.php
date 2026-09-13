<?php

declare(strict_types=1);

namespace AIPanel\Auth;

use AIPanel\Git\GitHubException;

/**
 * GitHub is the only identity provider in aipanel.
 *
 * There are no passwords in the system: a tenant's code lives in a GitHub
 * repository, so GitHub already decides who may change that site. Reusing it
 * for panel login means one place to revoke access, and no password database
 * to leak.
 *
 * Flow: authorizeUrl() → GitHub → callback → exchangeCode() → user().
 * The `state` parameter is bound to the session and verified on return.
 */
final class GitHubOAuth
{
    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri,
        private int $timeout = 30,
    ) {
    }

    /**
     * @param list<string> $scopes
     * @return array{url:string,state:string}
     */
    public function authorizeUrl(array $scopes = ['read:user', 'user:email']): array
    {
        $state = bin2hex(random_bytes(32));

        $url = 'https://github.com/login/oauth/authorize?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
            'allow_signup' => 'false',
        ], '', '&', PHP_QUERY_RFC3986);

        return ['url' => $url, 'state' => $state];
    }

    /**
     * Exchange the callback code for a user access token.
     *
     * @throws GitHubException
     */
    public function exchangeCode(string $code, string $returnedState, string $expectedState): string
    {
        if ($expectedState === '' || !hash_equals($expectedState, $returnedState)) {
            throw new GitHubException('OAuth state mismatch; login rejected.');
        }

        $response = $this->post('https://github.com/login/oauth/access_token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $token = (string) ($response['access_token'] ?? '');
        if ($token === '') {
            throw new GitHubException('GitHub did not return an access token: ' . (string) ($response['error_description'] ?? 'unknown error'));
        }

        return $token;
    }

    /**
     * The authenticated GitHub user, with a verified primary email.
     *
     * @return array{id:int,login:string,name:string,email:?string,avatar_url:string}
     */
    public function user(string $token): array
    {
        $user = $this->get('https://api.github.com/user', $token);

        $id = (int) ($user['id'] ?? 0);
        $login = (string) ($user['login'] ?? '');
        if ($id === 0 || $login === '') {
            throw new GitHubException('GitHub returned an unusable user profile.');
        }

        return [
            'id' => $id,
            'login' => $login,
            'name' => (string) ($user['name'] ?? $login),
            'email' => $this->primaryVerifiedEmail($token) ?? (isset($user['email']) && is_string($user['email']) ? $user['email'] : null),
            'avatar_url' => (string) ($user['avatar_url'] ?? ''),
        ];
    }

    /**
     * Only a *verified* primary address is accepted: an unverified address
     * would let someone claim an identity they do not control.
     */
    private function primaryVerifiedEmail(string $token): ?string
    {
        try {
            $emails = $this->get('https://api.github.com/user/emails', $token);
        } catch (GitHubException) {
            return null; // scope not granted
        }

        foreach (array_is_list($emails) ? $emails : [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['primary'] ?? false) === true && ($entry['verified'] ?? false) === true) {
                return (string) $entry['email'];
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $fields
     * @return array<string,mixed>
     */
    private function post(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: aipanel'],
        ]);

        return $this->finish($ch, 'POST ' . $url);
    }

    /** @return array<string,mixed>|list<mixed> */
    private function get(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2022-11-28',
                'User-Agent: aipanel',
                "Authorization: Bearer {$token}",
            ],
        ]);

        return $this->finish($ch, 'GET ' . $url);
    }

    /** @return array<string,mixed>|list<mixed> */
    private function finish(\CurlHandle $ch, string $what): array
    {
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new GitHubException("{$what} failed: {$error}");
        }

        $decoded = json_decode((string) $raw, true);
        if ($status >= 400 || !is_array($decoded)) {
            throw new GitHubException("{$what} failed (HTTP {$status}).");
        }

        return $decoded;
    }
}
