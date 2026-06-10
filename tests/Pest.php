<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** A tiny real JPEG (base64) so vision/cropper tests run against actual image bytes. */
function tinyJpeg(int $w = 120, int $h = 168): string
{
    $im = imagecreatetruecolor($w, $h);
    ob_start();
    imagejpeg($im);
    $data = (string) ob_get_clean();
    imagedestroy($im);

    return base64_encode($data);
}

/** Faked Anthropic responses: detect → two boxes, identify → a fixed card. */
function fakeAnthropic(array $identify = []): Closure
{
    $identify = array_merge([
        'name' => 'Pikachu ex', 'number' => '276/217', 'set_name' => 'Chaos Rising',
        'language' => 'en', 'is_graded' => false, 'confidence' => 0.9,
    ], $identify);

    return function ($request) use ($identify) {
        $tool = $request->data()['tools'][0]['name'] ?? '';

        if ($tool === 'record_detected_cards') {
            return Http::response(['content' => [[
                'type' => 'tool_use', 'name' => 'record_detected_cards',
                'input' => ['cards' => [
                    ['box' => ['x' => 0.0, 'y' => 0.0, 'width' => 0.5, 'height' => 1.0]],
                    ['box' => ['x' => 0.5, 'y' => 0.0, 'width' => 0.5, 'height' => 1.0]],
                ]],
            ]]], 200);
        }

        return Http::response(['content' => [[
            'type' => 'tool_use', 'name' => 'record_card', 'input' => $identify,
        ]]], 200);
    };
}

/**
 * Build a Standard-Webhooks-signed Dodo payload for testing the webhook endpoint.
 *
 * @return array{body: string, headers: array<string, string>}
 */
function dodoSigned(array $payload, ?string $secret = null): array
{
    $secret ??= (string) config('services.dodo.webhook_secret');
    $body = json_encode($payload);
    $id = 'msg_'.bin2hex(random_bytes(6));
    $ts = (string) time();

    $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret);
    $sig = base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $key, true));

    return [
        'body' => $body,
        'headers' => [
            'webhook-id' => $id,
            'webhook-timestamp' => $ts,
            'webhook-signature' => "v1,{$sig}",
        ],
    ];
}
