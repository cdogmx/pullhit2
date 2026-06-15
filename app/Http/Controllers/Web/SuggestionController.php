<?php

namespace App\Http\Controllers\Web;

use App\Actions\Catalog\SubmitItemEdit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreItemEditRequest;
use App\Models\CatalogItem;
use App\Models\ItemEditSuggestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;

/**
 * User-submitted catalog corrections. Anyone logged in can suggest an edit;
 * admins review it in the back office.
 */
class SuggestionController extends Controller
{
    public function store(StoreItemEditRequest $request, CatalogItem $catalogItem, SubmitItemEdit $submit): RedirectResponse
    {
        $changes = Arr::only($request->validated(), ItemEditSuggestion::editableFields());

        $submit($request->user(), $catalogItem, $changes, $request->validated()['note'] ?? null);

        return back()->with('success', 'Thanks — your suggested edit was sent for review.');
    }
}
