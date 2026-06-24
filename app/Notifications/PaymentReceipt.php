<?php

namespace App\Notifications;

use App\Models\BillingTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Emails a receipt after a successful payment (subscription or credit pack), and
 * drops a copy in the in-app notification center. Transactional — always sent
 * (a receipt isn't a preference). Dodo Payments is the merchant of record.
 */
class PaymentReceipt extends Notification
{
    use Queueable;

    public function __construct(public BillingTransaction $transaction) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'receipt',
            'title' => 'Payment receipt',
            'body' => "{$this->transaction->description} — {$this->amount()}",
            'url' => '/settings/billing',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $t = $this->transaction;
        $when = Carbon::parse($t->processed_at ?? $t->created_at)->toDayDateTimeString();

        $mail = (new MailMessage)
            ->subject('Your CardFoo receipt')
            ->greeting("Thanks, {$notifiable->name}!")
            ->line('We received your payment — here are the details:')
            ->line("**{$t->description}**")
            ->line("Amount: {$this->amount()}")
            ->line("Date: {$when}");

        if ($t->dodo_payment_id) {
            $mail->line("Reference: {$t->dodo_payment_id}");
        }

        return $mail
            ->action('View billing', url('/settings/billing'))
            ->line('Dodo Payments is our merchant of record; this email is your receipt.');
    }

    private function amount(): string
    {
        $major = $this->transaction->amountInMajorUnits();

        return $major === null
            ? '—'
            : strtoupper($this->transaction->currency ?? 'USD').' '.number_format($major, 2);
    }
}
