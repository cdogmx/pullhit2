<?php

namespace App\Support\Billing;

use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin wrapper over the Dodo Payments API. Creates hosted subscription checkouts
 * and cancels subscriptions. Credentials come from config('services.dodo') and
 * are never logged.
 */
class DodoClient
{
    /**
     * Create a hosted checkout for any product (a subscription tier or a one-time
     * credit pack) and return its URL. Extra metadata is merged onto user_id.
     *
     * @param  array<string, string>  $metadata
     */
    public function createCheckout(User $user, string $productId, string $returnUrl, array $metadata = []): string
    {
        if ($productId === '') {
            throw new RuntimeException('Dodo product id is not configured.');
        }

        $response = $this->http()->post($this->url('checkouts'), [
            'product_cart' => [
                ['product_id' => $productId, 'quantity' => 1],
            ],
            'customer' => [
                'email' => $user->email,
                'name' => $user->name,
            ],
            'metadata' => array_merge(['user_id' => (string) $user->id], $metadata),
            'return_url' => $returnUrl,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Dodo checkout failed: HTTP {$response->status()}");
        }

        $url = $response->json('checkout_url');

        if (! $url) {
            throw new RuntimeException('Dodo checkout returned no checkout_url.');
        }

        return $url;
    }

    /** Schedule a subscription to cancel at the end of the current billing period. */
    public function cancelSubscription(string $subscriptionId): void
    {
        $response = $this->http()->patch($this->url("subscriptions/{$subscriptionId}"), [
            'cancel_at_next_billing_date' => true,
            'cancel_reason' => 'cancelled_by_customer',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Dodo cancel failed: HTTP {$response->status()}");
        }
    }

    protected function http(): PendingRequest
    {
        return Http::withToken($this->config()['key'])
            ->timeout(30)
            ->retry(2, 2000, throw: false);
    }

    protected function url(string $path): string
    {
        return rtrim($this->config()['base_url'], '/').'/'.ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    protected function config(): array
    {
        $config = config('services.dodo');

        if (empty($config['key'])) {
            throw new RuntimeException('Dodo API key is not configured.');
        }

        return $config;
    }
}
