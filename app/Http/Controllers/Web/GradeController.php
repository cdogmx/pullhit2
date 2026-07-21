<?php

namespace App\Http\Controllers\Web;

use App\Actions\Grading\BuildGradingDossier;
use App\Enums\ItemType;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Support\RipOrKeep\SenseiChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * "Grade or Sell?" — the CardFoo Sensei rules on whether to send a raw single to
 * PSA or sell it as-is, reasoning from real raw + graded values and the modeled
 * grade-vs-sell EV (BuildGradingDossier). Same persona/engine as Rip or Keep,
 * launched from a card page. Throttled to keep AI cost bounded.
 */
class GradeController extends Controller
{
    /** The stats card behind the decision (also fed to the Sensei). */
    public function dossier(CatalogItem $catalogItem, BuildGradingDossier $build): JsonResponse
    {
        abort_unless($catalogItem->item_type === ItemType::Single, 404);

        return response()->json($build($catalogItem));
    }

    /** One Sensei turn: rebuild the dossier server-side, then reason over it. */
    public function chat(Request $request, CatalogItem $catalogItem, BuildGradingDossier $build, SenseiChat $sensei): JsonResponse
    {
        abort_unless($catalogItem->item_type === ItemType::Single, 404);

        // Validate by hand + return JSON: the app only auto-renders JSON errors
        // for /api/* routes (bootstrap/app.php).
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
