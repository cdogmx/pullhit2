<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Grading\PredictGradeFromPhotos;
use App\Http\Controllers\Controller;
use App\Models\GradePrediction;
use App\Support\Grading\GuideProposer;
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
            'saved' => GradePrediction::query()
                ->latest('id')->limit(30)->get()
                ->map(fn (GradePrediction $p) => [
                    'id' => $p->id,
                    'label' => $p->label,
                    'created_at' => $p->created_at?->toDateString(),
                    'score' => $p->estimate['score'] ?? null,
                    'probs' => $p->estimate['probs'] ?? [],
                    'observed' => $p->observed,
                    'guides_source' => $p->guides_source,
                    'actual_company' => $p->actual_company,
                    'actual_grade' => $p->actual_grade,
                    'actual_cert' => $p->actual_cert,
                    // What we gave the grade it actually got — the number that
                    // says whether the pipeline is any good.
                    'probability_of_actual' => $p->probabilityOfActual(),
                    'notes' => $p->notes,
                ]),
        ]);
    }

    /**
     * Where the model thinks the card and its border are.
     *
     * A starting point for the handles, never a measurement: see GuideProposer.
     */
    public function propose(Request $request, GuideProposer $proposer): JsonResponse
    {
        $validator = validator($request->all(), [
            'photo' => ['required', 'file', 'image', 'max:12288'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('photo');

        $guides = $proposer->propose(
            base64_encode((string) file_get_contents($file->getRealPath())),
            $file->getMimeType() ?: 'image/jpeg',
        );

        if ($guides === null) {
            return response()->json([
                'message' => 'Could not place the guides. Drag them yourself — they start on the card.',
            ], 422);
        }

        return response()->json($guides);
    }

    /** Keep a run, so it can be compared with the grade that comes back. */
    public function store(Request $request): JsonResponse
    {
        $validator = validator($request->all(), [
            'label' => ['nullable', 'string', 'max:120'],
            'sides' => ['required', 'array'],
            'estimate' => ['required', 'array'],
            'observed' => ['required', 'array'],
            'guides_source' => ['nullable', 'in:ai,ai-adjusted,manual'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        // The images are not kept. They are megabytes apiece and the thing
        // worth keeping is the reading, not the photograph of it.
        foreach ($data['sides'] as $name => $side) {
            unset($side['images']);
            $data['sides'][$name] = $side;
        }

        $prediction = GradePrediction::create($data + ['user_id' => $request->user()->id]);

        return response()->json(['id' => $prediction->id]);
    }

    /** Record what the grader actually said. */
    public function update(Request $request, GradePrediction $gradePrediction): JsonResponse
    {
        $validator = validator($request->all(), [
            'actual_company' => ['nullable', 'string', 'max:40'],
            // Half grades exist; 0 does not.
            'actual_grade' => ['nullable', 'numeric', 'min:0.5', 'max:10'],
            'actual_cert' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        $gradePrediction->update($data + [
            'graded_at' => ($data['actual_grade'] ?? null) !== null ? now() : null,
        ]);

        return response()->json([
            'id' => $gradePrediction->id,
            'probability_of_actual' => $gradePrediction->fresh()->probabilityOfActual(),
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
