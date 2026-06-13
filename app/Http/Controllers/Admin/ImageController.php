<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Catalog\CardImageStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin image intake: accept a file upload OR an external URL, store it in our
 * bucket, and return our hosted URL. We never hot-link — both paths download/
 * store a copy we own.
 */
class ImageController extends Controller
{
    public function store(Request $request, CardImageStore $images): JsonResponse
    {
        $request->validate([
            'file' => ['nullable', 'image', 'max:10240', 'required_without:url'],
            'url' => ['nullable', 'url', 'max:2000', 'required_without:file'],
        ]);

        $stored = $request->hasFile('file')
            ? $images->storeUpload($request->file('file'))
            : $images->storeFromUrl((string) $request->input('url'));

        abort_if($stored === null, 422, 'Could not store that image.');

        return response()->json(['url' => $stored]);
    }
}
