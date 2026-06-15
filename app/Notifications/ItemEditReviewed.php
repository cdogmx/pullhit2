<?php

namespace App\Notifications;

use App\Models\ItemEditSuggestion;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a user the outcome of the catalog edit they suggested (approved or
 * rejected, with the reviewer's note when present).
 */
class ItemEditReviewed extends Notification
{
    use Queueable;

    public function __construct(public ItemEditSuggestion $suggestion) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->wantsNotification('edit_reviews') ? ['mail', 'database'] : [];
    }

    /**
     * The in-app notification-center payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $card = $this->suggestion->catalogItem;
        $name = $card?->display_name ?? 'a card';
        $approved = $this->suggestion->status === 'approved';

        return [
            'type' => 'edit_review',
            'title' => $approved
                ? 'Your suggested edit was approved'
                : 'Your suggested edit was reviewed',
            'body' => $approved
                ? "Your edit to {$name} is now live."
                : "Your suggested edit to {$name} wasn't applied.",
            'url' => $card ? "/catalog/{$card->id}" : '/',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $card = $this->suggestion->catalogItem;
        $name = $card?->display_name ?? 'a card';
        $approved = $this->suggestion->status === 'approved';

        $mail = (new MailMessage)
            ->subject($approved ? 'Your suggested edit was approved' : 'Your suggested edit was reviewed')
            ->greeting($approved ? 'Nice catch!' : 'Thanks for the suggestion.')
            ->line($approved
                ? "Your edit to **{$name}** has been applied to the catalog — thanks for helping keep the data accurate."
                : "Your suggested edit to **{$name}** wasn't applied this time.");

        if (! $approved && $this->suggestion->review_note) {
            $mail->line("Reviewer note: {$this->suggestion->review_note}");
        }

        if ($card) {
            $mail->action('View the card', url("/catalog/{$card->id}"));
        }

        return $mail;
    }
}
