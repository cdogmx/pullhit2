<?php

namespace App\Http\Controllers\Web;

use App\Actions\Catalog\SuggestSearch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public catalog search endpoints. The header type-ahead hits `suggest` for
 * grouped brand/set/card results; full result pages live under /browse.
 */
class SearchController extends Controller
{
    public function suggest(Request $request, SuggestSearch $suggest): JsonResponse
    {
        return response()->json($suggest((string) $request->query('q', '')));
    }
}
