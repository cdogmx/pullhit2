<?php

namespace App\Http\Controllers\Web;

use App\Enums\ContributionType;
use App\Http\Controllers\Controller;
use App\Models\CardReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Community contributions hub: report a missing card or set (admin-reviewed,
 * points on acceptance) and see your own submission history + standing.
 */
class ContributeController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('contribute', [
            'reports' => $user->cardReports()->take(30)->get()->map(fn (CardReport $r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'name' => $r->name,
                'details' => $r->details,
                'status' => $r->status,
                'review_note' => $r->review_note,
                'created_at' => $r->created_at?->toIso8601String(),
            ]),
            'points' => [
                'missing_card' => ContributionType::MissingCard->points(),
                'missing_set' => ContributionType::MissingSet->points(),
                'edit_suggestion' => ContributionType::EditSuggestion->points(),
            ],
            'level' => $user->level(),
            'monthlyEntries' => $user->monthlyEntries(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['card', 'set'])],
            'name' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:50'],
            'set' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'language' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'source_url' => ['nullable', 'url', 'max:2000'],
        ]);

        $request->user()->cardReports()->create([
            'kind' => $data['kind'],
            'name' => $data['name'],
            'details' => collect($data)->only(['number', 'set', 'brand', 'language', 'notes', 'source_url'])
                ->filter()->all(),
            'status' => 'pending',
        ]);

        return back()->with('success', 'Thanks! We’ll review your report — you’ll earn points if it’s accepted.');
    }
}
