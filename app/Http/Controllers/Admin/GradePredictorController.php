<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Grading\PredictGradeFromPhotos;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * A bench for the photo grading pipeline (admin).
 *
 * The pipeline has only ever been exercised on synthetic images and through a
 * CLI harness, which proves the maths but says nothing about paper texture,
 * foil, sensor noise or hand shake. This is where real photos meet it.
 *
 * Admin-only and deliberately so: it answers with a distribution over grades
 * from an unvalidated pipeline, and that is not a thing to show a user next to
 * a price until it has been shown to work.
 */
class GradePredictorController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/grade-predictor', [
            'defaults' => [
                'max_input' => 1400,
                'canvas_width' => 500,
            ],
        ]);
    }

    public function predict(Request $request, PredictGradeFromPhotos $predict): JsonResponse
    {
        // Validated by hand and answered as JSON: this is a fetch endpoint on a
        // non-api route, and Laravel's default redirect-on-failure would arrive
        // at the front end as an opaque 302.
        $validator = validator($request->all(), [
            'photos' => ['required', 'array', 'min:2', 'max:8'],
            'photos.*' => ['file', 'image', 'max:12288'],
            'max_input' => ['nullable', 'integer', 'min:400', 'max:3000'],
            'canvas_width' => ['nullable', 'integer', 'min:200', 'max:1200'],
            'inner' => ['nullable', 'array'],
            'inner.left' => ['nullable', 'numeric', 'min:0', 'max:0.49'],
            'inner.right' => ['nullable', 'numeric', 'min:0', 'max:0.49'],
            'inner.top' => ['nullable', 'numeric', 'min:0', 'max:0.49'],
            'inner.bottom' => ['nullable', 'numeric', 'min:0', 'max:0.49'],
        ], [
            'photos.min' => 'Two or more photos — one image carries no surface information.',
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        $binaries = array_map(
            fn ($file) => (string) file_get_contents($file->getRealPath()),
            $request->file('photos'),
        );

        $started = hrtime(true);

        try {
            $result = $predict(
                $binaries,
                (int) ($data['max_input'] ?? 1400),
                (int) ($data['canvas_width'] ?? 500),
                // Only pass an inner frame when one was actually marked; an
                // all-zero frame is "not measured", not "perfectly centred".
                array_filter($data['inner'] ?? []) !== [] ? $data['inner'] : null,
            );
        } catch (RuntimeException $e) {
            // The capture rules — two photos, same size, readable — are the
            // errors a person testing this will hit most, so they come back as
            // the message rather than a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $result['took_ms'] = (int) round((hrtime(true) - $started) / 1_000_000);

        return response()->json($result);
    }
}
