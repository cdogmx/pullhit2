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
        // The browse box passes its current brand/series so suggestions stay
        // inside the section being browsed; the header box passes neither.
        $scope = [
            'product_line' => $this->scopeParam($request, 'product_line'),
            'series' => $this->scopeParam($request, 'series'),
        ];

        return response()->json($suggest((string) $request->query('q', ''), $scope));
    }

    /** A bounded scope value from the query string, or null when absent/blank. */
    private function scopeParam(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value === '' ? null : mb_substr($value, 0, 64);
    }
}
