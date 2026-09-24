<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\GradePrediction;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One shared reading from the grading bench.
 *
 * Public by link only — the token IS the permission, and a prediction that was
 * never shared has none, so it 404s rather than 403s. There is nothing to
 * enumerate here either: the URL carries 32 random characters, not an id.
 *
 * What the page must never do is read as a grade. It is the output of a
 * pipeline that has not been validated against real outcomes yet, it usually
 * has not looked at three of the four attributes a grade accounts for, and it
 * answers with a distribution on purpose. The caveats travel with the link for
 * that reason — a shared number without them is the one way this tool could
 * mislead somebody who was not in the room when it ran.
 */
class GradeReportController extends Controller
{
    public function show(string $token): Response
    {
        $prediction = GradePrediction::where('share_token', $token)->firstOrFail();

        $shared = $prediction->toShared();

        $title = $prediction->label
            ? "Grade prediction · {$prediction->label}"
            : 'Grade prediction';

        return Inertia::render('grading/report', $shared + [
            // Server-rendered share meta; social scrapers do not run JS.
            'meta' => [
                'title' => $title,
                'description' => $this->summary($prediction),
            ],
        ]);
    }

    private function summary(GradePrediction $prediction): string
    {
        $unseen = count($prediction->estimate['unseen'] ?? []);

        $said = collect($prediction->estimate['probs'] ?? [])
            ->sortDesc()
            ->take(1)
            ->map(fn ($p, $grade) => $grade.' at '.round($p * 100).'%')
            ->first() ?? 'no call';

        return $prediction->actual_grade !== null
            ? "Predicted {$said}; graded {$prediction->actual_grade}. An estimate from photos, not a grade."
            : "Predicted {$said}, with {$unseen} of 4 attributes unassessed. An estimate from photos, not a grade.";
    }
}
