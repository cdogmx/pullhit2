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
            'sides' => PredictGradeFromPhotos::SIDES,
        ]);
    }

    public function predict(Request $request, PredictGradeFromPhotos $predict): JsonResponse
    {
        // Validated by hand and answered as JSON: this is a fetch endpoint on a
        // non-api route, and Laravel's default redirect-on-failure would arrive
        // at the front end as an opaque 302.
        $rules = [
            'max_input' => ['nullable', 'integer', 'min:400', 'max:3000'],
            'canvas_width' => ['nullable', 'integer', 'min:200', 'max:1200'],
        ];

        foreach (PredictGradeFromPhotos::SIDES as $side) {
            // One photo is allowed. It cannot carry a surface read, and the
            // pipeline says so rather than refusing the upload — a rectified
            // card and a centering figure are still worth having.
            $rules[$side] = ['nullable', 'array', 'min:1', 'max:8'];
            $rules["{$side}.*"] = ['file', 'image', 'max:12288'];

            // The centering split as a report prints it: 46 left / 54 right.
            foreach (['left', 'right', 'top', 'bottom'] as $edge) {
                $rules["centering.{$side}.{$edge}"] = ['nullable', 'numeric', 'min:0', 'max:100'];
            }

            // Dragged guides: the card's outline and its artwork frame, four
            // corners each, as fractions of the image.
            foreach (['outline', 'frame'] as $guide) {
                $rules["guides.{$side}.{$guide}"] = ['nullable', 'array', 'size:4'];
                $rules["guides.{$side}.{$guide}.*.x"] = ['required_with:guides.'.$side.'.'.$guide, 'numeric', 'min:-0.5', 'max:1.5'];
                $rules["guides.{$side}.{$guide}.*.y"] = ['required_with:guides.'.$side.'.'.$guide, 'numeric', 'min:-0.5', 'max:1.5'];
            }
        }

        $validator = validator($request->all(), $rules);

        $validator->after(function ($v) use ($request) {
            $given = array_filter(
                PredictGradeFromPhotos::SIDES,
                fn (string $s) => $request->file($s) !== null,
            );

            if ($given === []) {
                $v->errors()->add('front', 'Give at least one side — a front or a back tilt sequence.');
            }
        });

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        $sides = [];
        $centering = [];
        $guides = [];

        foreach (PredictGradeFromPhotos::SIDES as $side) {
            if (($files = $request->file($side)) === null) {
                continue;
            }

            $sides[$side] = array_map(
                fn ($file) => (string) file_get_contents($file->getRealPath()),
                $files,
            );

            // Only pass a split that was actually typed; an all-zero split is
            // "not measured", not "perfectly centred".
            $marked = array_filter($data['centering'][$side] ?? []);

            if ($marked !== []) {
                $centering[$side] = $data['centering'][$side];
            }

            if (($data['guides'][$side] ?? []) !== []) {
                $guides[$side] = $data['guides'][$side];
            }
        }

        $started = hrtime(true);

        try {
            $result = $predict(
                $sides,
                (int) ($data['max_input'] ?? 1400),
                (int) ($data['canvas_width'] ?? 500),
                $centering,
                $guides,
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
