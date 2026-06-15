<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Catalog\ApplyItemEdit;
use App\Http\Controllers\Controller;
use App\Models\ItemEditSuggestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin review of user-submitted catalog edits: current → proposed diff per
 * field; approve applies it (and rehashes), reject dismisses it.
 */
class SuggestionController extends Controller
{
    public function index(): Response
    {
        $pending = ItemEditSuggestion::where('status', 'pending')
            ->with(['user:id,name', 'catalogItem.set'])
            ->latest()
            ->get()
            ->map(fn (ItemEditSuggestion $s) => [
                'id' => $s->id,
                'submitted_by' => $s->user?->name,
                'submitted_at' => $s->created_at?->diffForHumans(),
                'note' => $s->note,
                'diff' => $this->diff($s),
                'catalog_item' => [
                    'id' => $s->catalogItem?->id,
                    'display_name' => $s->catalogItem?->display_name,
                    'number' => $s->catalogItem?->number,
                    'set' => $s->catalogItem?->set?->name,
                    'image_url' => $s->catalogItem?->primary_image_path
                        ?? ($s->catalogItem?->external_ids['ptcgio_image'] ?? null),
                ],
            ]);

        return Inertia::render('admin/suggestions', [
            'suggestions' => $pending,
        ]);
    }

    public function approve(Request $request, ItemEditSuggestion $itemEditSuggestion, ApplyItemEdit $apply): RedirectResponse
    {
        try {
            $apply->apply($itemEditSuggestion, $request->user());
        } catch (ValidationException $e) {
            return back()->with('error', 'Could not apply — the edit makes the card invalid: '.collect($e->errors())->flatten()->first());
        }

        return back()->with('success', 'Edit applied to the catalog.');
    }

    public function reject(Request $request, ItemEditSuggestion $itemEditSuggestion, ApplyItemEdit $apply): RedirectResponse
    {
        $data = $request->validate(['review_note' => ['nullable', 'string', 'max:500']]);

        $apply->reject($itemEditSuggestion, $request->user(), $data['review_note'] ?? null);

        return back()->with('success', 'Suggestion rejected.');
    }

    /**
     * @return array<int, array{field: string, from: mixed, to: mixed}>
     */
    private function diff(ItemEditSuggestion $s): array
    {
        $item = $s->catalogItem;
        $attributes = $item?->getAttribute('attributes') ?? [];

        $rows = [];
        foreach ($s->changes as $field => $proposed) {
            $current = in_array($field, ItemEditSuggestion::TOP_LEVEL, true)
                ? $item?->{$field}
                : ($attributes[$field] ?? null);
            $rows[] = ['field' => $field, 'from' => $current, 'to' => $proposed];
        }

        return $rows;
    }
}
