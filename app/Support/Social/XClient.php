<?php

namespace App\Support\Social;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Posts tweets via the X (Twitter) API v2 `POST /2/tweets`. That endpoint
 * requires *user-context* auth, so we sign with OAuth 1.0a (consumer key/secret
 * + the bot account's access token/secret). A bearer token is app-only and
 * cannot post. Credentials come from config('services.x').
 */
class XClient
{
    private const ENDPOINT = 'https://api.twitter.com/2/tweets';

    public function configured(): bool
    {
        $c = config('services.x');

        return ! empty($c['consumer_key'])
            && ! empty($c['consumer_secret'])
            && ! empty($c['access_token'])
            && ! empty($c['access_token_secret']);
    }

    /**
     * Post a tweet, returning its id.
     *
     * @throws RuntimeException when credentials are missing or the API rejects it
     */
    public function tweet(string $text): string
    {
        if (! $this->configured()) {
            throw new RuntimeException(
                'X posting needs OAuth 1.0a user credentials: set X_CONSUMER_KEY, X_SECRET_KEY, X_ACCESS_TOKEN and X_ACCESS_TOKEN_SECRET.'
            );
        }

        $header = $this->authorizationHeader('POST', self::ENDPOINT);

        $response = Http::withHeaders(['Authorization' => $header])
            ->asJson()
            ->timeout(30)
            ->post(self::ENDPOINT, ['text' => $text]);

        if (! $response->successful()) {
            throw new RuntimeException("X API rejected the tweet: HTTP {$response->status()} {$response->body()}");
        }

        return (string) ($response->json('data.id') ?? '');
    }

    /**
     * Ask X what access level the current token actually has. Returns the
     * `x-access-level` header — "read", "read-write", or
     * "read-write-directmessages". Useful to confirm Read+Write took effect.
     */
    public function accessLevel(): string
    {
        if (! $this->configured()) {
            return 'unconfigured';
        }

        $url = 'https://api.twitter.com/1.1/account/verify_credentials.json';
        $response = Http::withHeaders(['Authorization' => $this->authorizationHeader('GET', $url)])
            ->timeout(30)
            ->get($url);

        return $response->header('x-access-level')
            ?: ('HTTP '.$response->status().' '.$response->body());
    }

    /**
     * Build the OAuth 1.0a Authorization header. For a JSON body the request
     * body is not part of the signature — only the oauth_* params are.
     */
    private function authorizationHeader(string $method, string $url): string
    {
        $c = config('services.x');

        $oauth = [
            'oauth_consumer_key' => $c['consumer_key'],
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_token' => $c['access_token'],
            'oauth_version' => '1.0',
        ];

        ksort($oauth);
        $paramString = http_build_query($oauth, '', '&', PHP_QUERY_RFC3986);

        $base = implode('&', [
            $method,
            rawurlencode($url),
            rawurlencode($paramString),
        ]);

        $signingKey = rawurlencode($c['consumer_secret']).'&'.rawurlencode($c['access_token_secret']);
        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $signingKey, true));

        ksort($oauth);
        $parts = [];
        foreach ($oauth as $key => $value) {
            $parts[] = rawurlencode($key).'="'.rawurlencode($value).'"';
        }

        return 'OAuth '.implode(', ', $parts);
    }
}
