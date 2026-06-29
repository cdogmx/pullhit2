<?php

namespace App\Support\Social;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    private const MEDIA_ENDPOINT = 'https://upload.twitter.com/1.1/media/upload.json';

    public function configured(): bool
    {
        $c = config('services.x');

        return ! empty($c['consumer_key'])
            && ! empty($c['consumer_secret'])
            && ! empty($c['access_token'])
            && ! empty($c['access_token_secret']);
    }

    /**
     * Post a tweet (optionally with already-uploaded media), returning its id.
     *
     * @param  array<int, string>  $mediaIds
     *
     * @throws RuntimeException when credentials are missing or the API rejects it
     */
    public function tweet(string $text, array $mediaIds = []): string
    {
        if (! $this->configured()) {
            throw new RuntimeException(
                'X posting needs OAuth 1.0a user credentials: set X_CONSUMER_KEY, X_SECRET_KEY, X_ACCESS_TOKEN and X_ACCESS_TOKEN_SECRET.'
            );
        }

        $body = ['text' => $text];

        if ($mediaIds !== []) {
            $body['media'] = ['media_ids' => array_values($mediaIds)];
        }

        $response = Http::withHeaders(['Authorization' => $this->authorizationHeader('POST', self::ENDPOINT)])
            ->asJson()
            ->timeout(30)
            ->post(self::ENDPOINT, $body);

        if (! $response->successful()) {
            throw new RuntimeException("X API rejected the tweet: HTTP {$response->status()} {$response->body()}");
        }

        return (string) ($response->json('data.id') ?? '');
    }

    /**
     * Post a tweet with a product image. The image is best-effort: if the
     * download or media upload fails, we still post the text so the alert
     * isn't lost. Returns the tweet id.
     */
    public function tweetWithImage(string $text, ?string $imageUrl): string
    {
        $mediaIds = [];

        if ($imageUrl) {
            try {
                $id = $this->uploadMedia($imageUrl);
                if ($id) {
                    $mediaIds[] = $id;
                }
            } catch (\Throwable $e) {
                Log::warning('X media upload failed; posting without image', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $this->tweet($text, $mediaIds);
    }

    /**
     * Download an image and upload it to X, returning its media_id (string form).
     * Uses the v1.1 simple upload (multipart, so the body isn't part of the
     * OAuth signature). Returns null if the image couldn't be fetched.
     */
    public function uploadMedia(string $imageUrl): ?string
    {
        $image = Http::timeout(30)->get($imageUrl);

        if (! $image->successful() || $image->body() === '') {
            return null;
        }

        $response = Http::withHeaders(['Authorization' => $this->authorizationHeader('POST', self::MEDIA_ENDPOINT)])
            ->attach('media', $image->body(), 'product.jpg')
            ->timeout(60)
            ->post(self::MEDIA_ENDPOINT);

        if (! $response->successful()) {
            throw new RuntimeException("X media upload failed: HTTP {$response->status()} {$response->body()}");
        }

        return ((string) ($response->json('media_id_string') ?? '')) ?: null;
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
