<?php

namespace App\Actions\Community;

use App\Models\Contribution;
use App\Models\Giveaway;
use App\Models\User;
use App\Notifications\GiveawayWon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Draw a weighted-random winner for a giveaway. A user's entries are the points
 * they earned in the giveaway's calendar month (the contributions ledger), so
 * more contributions = better odds. Auditable from source; the result snapshots
 * the winner + pool totals onto the row. Idempotent unless $force.
 */
class DrawGiveaway
{
    /** @return User|null  the winner, or null when there were no entries */
    public function __invoke(Giveaway $giveaway, bool $force = false): ?User
    {
        if ($giveaway->status === Giveaway::DRAWN && ! $force) {
            return $giveaway->winner;
        }

        // Entries per eligible user (points earned in the period, public username).
        $entries = Contribution::query()
            ->whereBetween('contributions.created_at', [$giveaway->periodStart(), $giveaway->periodEnd()])
            ->join('users', 'users.id', '=', 'contributions.user_id')
            ->whereNotNull('users.username')
            ->groupBy('users.id')
            ->havingRaw('SUM(contributions.points) > 0')
            ->get(['users.id', DB::raw('SUM(contributions.points) as entries')]);

        $total = (int) $entries->sum('entries');

        if ($total <= 0) {
            return null;
        }

        // Weighted pick: each point is a ticket; land on a random ticket.
        $pick = random_int(1, $total);
        $cursor = 0;
        $winnerId = (int) $entries->last()->id;
        $winnerEntries = (int) $entries->last()->entries;

        foreach ($entries as $row) {
            $cursor += (int) $row->entries;
            if ($pick <= $cursor) {
                $winnerId = (int) $row->id;
                $winnerEntries = (int) $row->entries;
                break;
            }
        }

        $winner = User::find($winnerId);

        DB::transaction(fn () => $giveaway->forceFill([
            'status' => Giveaway::DRAWN,
            'winner_user_id' => $winner?->id,
            'winner_entries' => $winnerEntries,
            'total_entries' => $total,
            'entrant_count' => $entries->count(),
            'drawn_at' => Carbon::now(),
        ])->save());

        if ($winner) {
            $winner->notify(new GiveawayWon($giveaway));
        }

        return $winner;
    }
}
