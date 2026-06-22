<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Community\AwardPoints;
use App\Enums\ContributionType;
use App\Http\Controllers\Controller;
use App\Models\CardReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin review of user "missing card/set" reports. Accepting one awards the
 * submitter points (per kind); the admin then adds the card/set with the
 * existing catalog tools.
 */
class CardReportController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'pending');

        $reports = CardReport::with('user:id,name,username')
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true),
                fn (Builder $q) => $q->where('status', $status))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('admin/card-reports', [
            'reports' => collect($reports->items())->map(fn (CardReport $r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'name' => $r->name,
                'details' => $r->details,
                'status' => $r->status,
                'user' => $r->user?->username ?? $r->user?->name,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'pagination' => [
                'page' => $reports->currentPage(),
                'last_page' => $reports->lastPage(),
                'total' => $reports->total(),
            ],
            'filters' => ['status' => $status],
            'pending' => CardReport::where('status', 'pending')->count(),
        ]);
    }

    public function approve(CardReport $cardReport, AwardPoints $award): RedirectResponse
    {
        if ($cardReport->status !== 'approved') {
            $cardReport->forceFill([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => Carbon::now(),
            ])->save();

            if ($cardReport->user) {
                $type = $cardReport->kind === 'set'
                    ? ContributionType::MissingSet
                    : ContributionType::MissingCard;

                $award($cardReport->user, $type, $cardReport, ucfirst($cardReport->kind).' report accepted');
            }
        }

        return back()->with('success', 'Report accepted — points awarded.');
    }

    public function reject(Request $request, CardReport $cardReport): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:500']]);

        $cardReport->forceFill([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'review_note' => $data['review_note'] ?? null,
            'reviewed_at' => Carbon::now(),
        ])->save();

        return back()->with('success', 'Report rejected.');
    }
}
