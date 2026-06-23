<?php

namespace App\Notifications;

use App\Models\Giveaway;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells a user they won a monthly giveaway. Transactional — always delivered
 * (winning isn't a preference) — plus an in-app notification.
 */
class GiveawayWon extends Notification
{
    use Queueable;

    public function __construct(public Giveaway $giveaway) {}

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
            'type' => 'giveaway_won',
            'title' => "You won the {$this->giveaway->periodLabel()} giveaway!",
            'body' => "Prize: {$this->giveaway->prize}. We'll be in touch.",
            'url' => '/rankings',
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("🎉 You won the {$this->giveaway->periodLabel()} CardFoo giveaway!")
            ->greeting("Congratulations, {$notifiable->name}!")
            ->line("Your contributions this month earned you the win in the {$this->giveaway->periodLabel()} giveaway.")
            ->line("**Prize:** {$this->giveaway->prize}")
            ->line("We'll reach out shortly to arrange it. Thanks for helping build the most accurate catalog around.")
            ->action('See the rankings', url('/rankings'))
            ->line('Wax on. 🥋');
    }
}
