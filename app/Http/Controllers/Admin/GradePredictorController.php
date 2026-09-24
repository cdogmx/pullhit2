<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Grading\PredictGradeFromPhotos;
use App\Http\Controllers\Controller;
use App\Models\GradePrediction;
use App\Support\Grading\CardOutline;
use App\Support\Grading\GuideProposer;
use App\Support\Grading\ImageRectifier;
use App\Support\Grading\PhotoSequence;
use App\Support\Grading\PredictionArchive;
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
                ->with('user:id,name,username')
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
                    'share_url' => $p->share_token ? $p->shareUrl() : null,
                    // Everyone sees every run — comparing them is the point —
                    // but only the person who ran one may change it.
                    'owned' => $p->user_id === request()->user()?->id,
                    'ran_by' => $p->user?->username ?? $p->user?->name,
                ]),
        ]);
    }

    /**
     * Find the card in a photo, so nobody has to cut the background out by hand.
     *
     * This is the pipeline's own detector — Otsu threshold, largest quad — not
     * a model. It is deterministic, it costs nothing, and on the case that
     * actually matters here (a card on a plain background) it is better at
     * finding an edge than anything that has to be asked in words.
     *
     * Returns both what it found and the box around it: the box pre-fills the
     * crop, and the quad pre-places the outer guide once cropped.
     */
    public function detect(Request $request, CardOutline $outline): JsonResponse
    {
        $validator = validator($request->all(), [
            'photo' => ['required', 'file', 'image', 'max:12288'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        @ini_set('memory_limit', '1024M');

        try {
            $photo = PhotoSequence::fromBinaries(
                [(string) file_get_contents($request->file('photo')->getRealPath())],
                1400,
            );

            $quad = $outline->detect($photo->frames[0], $photo->width, $photo->height);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (count($quad) !== 4) {
            return response()->json(['message' => 'Could not find a card in that photo.'], 422);
        }

        $xs = array_map(fn ($p) => $p[0] / $photo->width, $quad);
        $ys = array_map(fn ($p) => $p[1] / $photo->height, $quad);

        // A hair of margin. Cropping exactly to the detected edge risks shaving
        // the card's own border off, and the border is what centering measures.
        $pad = 0.012;

        $x = max(0.0, min($xs) - $pad);
        $y = max(0.0, min($ys) - $pad);

        return response()->json([
            'outline' => array_map(
                fn ($p) => ['x' => $p[0] / $photo->width, 'y' => $p[1] / $photo->height],
                $quad,
            ),
            'crop' => [
                'x' => $x,
                'y' => $y,
                'w' => min(1.0 - $x, max($xs) - min($xs) + $pad * 2),
                'h' => min(1.0 - $y, max($ys) - min($ys) + $pad * 2),
            ],
        ]);
    }

    /**
     * Straighten a card out of a photo, given its four corners.
     *
     * A card is rarely square-on in a hand-held shot, and an axis-aligned crop
     * of a tilted one keeps the tilt and a wedge of background in every corner.
     * Both make the next step harder than it needs to be: a guide placed along
     * a crooked border is fighting the picture, and the model reads a crooked
     * card as a card with a crooked border.
     *
     * Warped here rather than in the browser because the maths is already here,
     * fitted and tested — the same Homography the surface pipeline flattens
     * frames with. A second implementation in canvas would be a second thing
     * to be wrong.
     */
    public function deskew(Request $request, ImageRectifier $rectifier): JsonResponse
    {
        $validator = validator($request->all(), [
            'photo' => ['required', 'file', 'image', 'max:12288'],
            'quad' => ['required', 'array', 'size:4'],
            'quad.*.x' => ['required', 'numeric', 'min:-0.5', 'max:1.5'],
            'quad.*.y' => ['required', 'numeric', 'min:-0.5', 'max:1.5'],
            'width' => ['nullable', 'integer', 'min:200', 'max:1600'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        @ini_set('memory_limit', '1024M');

        $data = $validator->validated();

        try {
            $png = $rectifier->rectify(
                (string) file_get_contents($request->file('photo')->getRealPath()),
                $data['quad'],
                (int) ($data['width'] ?? 700),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'image' => 'data:image/png;base64,'.base64_encode($png),
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
    public function store(Request $request, PredictionArchive $archive): JsonResponse
    {
        $validator = validator($request->all(), [
            'label' => ['nullable', 'string', 'max:120'],
            'sides' => ['required', 'array'],
            'estimate' => ['required', 'array'],
            // present, not required: "required" fails an EMPTY array in
            // Laravel, and a run that observed nothing is exactly the case
            // worth keeping — one photo, no centering typed, an honest
            // record of having seen none of the four attributes.
            'observed' => ['present', 'array'],
            'guides_source' => ['nullable', 'in:ai,ai-adjusted,manual'],
            'centering_standards' => ['sometimes', 'array'],
            // Where the guide sat, so it can be drawn back over the picture.
            'sides.*.guide' => ['nullable', 'array', 'size:4'],
            'sides.*.guide.*.x' => ['required_with:sides.*.guide', 'numeric'],
            'sides.*.guide.*.y' => ['required_with:sides.*.guide', 'numeric'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        // The pictures are kept now, small: a straightened card with its guide
        // drawn over it is the only thing that answers "was the guide on the
        // border", and that is the first question whenever a number looks off.
        // The full-size data URIs still never reach the database.
        // Read from the request, not from validated(): adding rules for
        // sides.*.guide made validated() rebuild "sides" out of just the leaves
        // those rules matched, which on a payload without a guide is nothing at
        // all. The array is validated as required+array above; this takes it
        // whole.
        $data['sides'] = $archive->store(
            $request->user()->id,
            (array) $request->input('sides', []),
        );

        $prediction = GradePrediction::create($data + ['user_id' => $request->user()->id]);

        return response()->json(['id' => $prediction->id]);
    }

    /** Throw a run away. The owner's to make. */
    public function destroy(GradePrediction $gradePrediction): JsonResponse
    {
        $this->authorize('delete', $gradePrediction);

        $gradePrediction->delete();

        return response()->json(['deleted' => true]);
    }

    /** Hand out a link to one reading, or take it back. */
    public function share(Request $request, GradePrediction $gradePrediction): JsonResponse
    {
        $this->authorize('share', $gradePrediction);

        if ($request->boolean('revoke')) {
            $gradePrediction->unshare();

            return response()->json(['share_url' => null]);
        }

        return response()->json(['share_url' => $gradePrediction->share()]);
    }

    /** Record what the grader actually said. */
    public function update(Request $request, GradePrediction $gradePrediction): JsonResponse
    {
        $this->authorize('update', $gradePrediction);

        $validator = validator($request->all(), [
            // What it is and what you thought at the time: both get corrected
            // later, and a run mislabelled is a run nobody finds again.
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'actual_company' => ['sometimes', 'nullable', 'string', 'max:40'],
            // Half grades exist; 0 does not.
            'actual_grade' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:10'],
            'actual_cert' => ['sometimes', 'nullable', 'string', 'max:60'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $data = $validator->validated();

        // Only touch graded_at when this edit carried a grade. Renaming a run
        // must not quietly un-grade it, which the old unconditional write did.
        if (array_key_exists('actual_grade', $data)) {
            $data['graded_at'] = $data['actual_grade'] !== null ? now() : null;
        }

        $gradePrediction->update($data);

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
            foreach (['outline', 'frame', 'frame_uv'] as $guide) {
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
