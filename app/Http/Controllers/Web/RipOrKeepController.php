<?php

namespace App\Http\Controllers\Web;

use App\Actions\Catalog\SearchCatalog;
use App\Actions\RipOrKeep\BuildSealedDossier;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Support\RipOrKeep\SenseiChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * "Rip or Keep?" — a playful AI Sensei that helps a collector decide whether to
 * open a sealed product or keep it sealed, reasoning from real CardFoo data
 * (BuildSealedDossier). Public + throttled to keep it viral but abuse-resistant.
 */
class RipOrKeepController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('rip-or-keep/index', [
            'meta' => [
                'title' => 'Rip or Keep? — ask the CardFoo Sensei',
                'description' => 'Open the box or keep it sealed? Ask the CardFoo Sensei for a data-driven verdict from real sealed prices, trends, and modeled pull-rate expected value. Wax on.',
            ],
        ]);
    }

    /** Type-ahead over sealed products for the picker. */
    public function search(Request $request, SearchCatalog $search): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $results = $search(['q' => $query, 'item_type' => ItemType::Sealed->value, 'per_page' => 8]);

        return response()->json([
            'results' => collect($results->items())->map(fn (CatalogItem $c) => [
                'id' => $c->id,
                'name' => $c->display_name ?? $c->name,
                'image' => $c->primary_image_path,
                'set' => $c->set?->name,
                'sealed_value' => $c->defaultMarketValue?->median,
            ])->all(),
        ]);
    }

    /** The stats card behind the decision (also fed to the Sensei). */
    public function dossier(CatalogItem $catalogItem, BuildSealedDossier $build): JsonResponse
    {
        abort_unless($catalogItem->item_type === ItemType::Sealed, 404);

        return response()->json($build($catalogItem));
    }

    /** One Sensei turn: rebuild the dossier server-side, then reason over it. */
    public function chat(Request $request, CatalogItem $catalogItem, BuildSealedDossier $build, SenseiChat $sensei): JsonResponse
    {
        abort_unless($catalogItem->item_type === ItemType::Sealed, 404);

        // Validate by hand + return JSON: this is a fetch endpoint, but the app
        // only auto-renders JSON errors for /api/* routes (bootstrap/app.php).
        $validator = Validator::make($request->all(), [
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'That conversation looks off.'], 422);
        }

        try {
            $reply = $sensei->reply($build($catalogItem), $validator->validated()['messages']);
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'The Sensei is unavailable right now. Try again shortly.',
            ], 503);
        }

        return response()->json(['reply' => $reply]);
    }
}
