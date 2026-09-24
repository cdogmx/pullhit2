<?php

namespace App\Support\Grading;

use App\Support\Scanning\AnthropicVisionClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the vision model where the card and its border are, as a starting point.
 *
 * A STARTING POINT, and the distinction is the whole design. Centering is the
 * one attribute this system measures rather than infers, and its value comes
 * entirely from the two rectangles being where the border actually is. A
 * language model's corner is an aim, not a measurement: it lands close, which
 * is enormously useful for dragging four handles roughly into place, and it is
 * routinely a percent or two out, which at 9.06 points per percentage point is
 * twenty score points of nonsense if nobody looks.
 *
 * So what it returns is labelled as proposed, and a prediction records whether
 * a person moved the handles afterwards. Rows where nobody did are excluded
 * from the calibration set — fitting the centering constant against accepted
 * proposals would tune it to the model's aim rather than to the card.
 */
class GuideProposer
{
    private const TOOL = 'record_card_guides';

    public function __construct(
        protected AnthropicVisionClient $vision,
    ) {}

    /**
     * @return array{outline: array<int, array{x: float, y: float}>, frame: array<int, array{x: float, y: float}>}|null
     */
    public function propose(string $base64, string $mediaType): ?array
    {
        try {
            $input = $this->vision->runTool(
                $base64,
                $mediaType,
                $this->tool(),
                self::TOOL,
                'This is one trading card photographed against a background. Return two '.
                'quadrilaterals as normalized coordinates (0–1, origin top-left), each as '.
                'exactly four corners in clockwise order starting from the TOP LEFT corner '.
                'of the card as it appears in the photo.'."\n\n".
                '"outline" is the OUTER edge of the card — where the card stops and the '.
                'background begins. Follow the actual corners in the photo, including any '.
                'perspective: if the card is tilted, the quadrilateral is not a rectangle.'."\n\n".
                '"frame" is the INNER edge of the card\'s border — the line where the '.
                'printed border stops and the artwork or the card face begins. On a modern '.
                'Pokemon card this is the inner edge of the yellow or silver border. If the '.
                'card has no distinct border, return your best estimate of where the design '.
                'field begins.'."\n\n".
                'Be as precise as you can: these are used to measure how well centred the '.
                'card is, so a corner that is one percent off changes the answer.',
            );

            $outline = $this->quad($input['outline'] ?? null);
            $frame = $this->quad($input['frame'] ?? null);

            if ($outline === null || $frame === null) {
                return null;
            }

            return ['outline' => $outline, 'frame' => $frame];
        } catch (Throwable $e) {
            // A proposal failing is a nuisance, not a failure: the handles are
            // still draggable from their default positions.
            Log::warning('grading.guides.propose_failed', ['message' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @param  mixed  $points
     * @return array<int, array{x: float, y: float}>|null
     */
    private function quad($points): ?array
    {
        if (! is_array($points) || count($points) !== 4) {
            return null;
        }

        $quad = [];

        foreach ($points as $p) {
            if (! isset($p['x'], $p['y']) || ! is_numeric($p['x']) || ! is_numeric($p['y'])) {
                return null;
            }

            // Clamped, not rejected: a corner just off the frame is a card
            // shot tight to the edge, which is common and fine.
            $quad[] = [
                'x' => max(-0.2, min(1.2, (float) $p['x'])),
                'y' => max(-0.2, min(1.2, (float) $p['y'])),
            ];
        }

        return $quad;
    }

    /** @return array<string, mixed> */
    private function tool(): array
    {
        $quad = [
            'type' => 'array',
            'minItems' => 4,
            'maxItems' => 4,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'x' => ['type' => 'number'],
                    'y' => ['type' => 'number'],
                ],
                'required' => ['x', 'y'],
            ],
        ];

        return [
            'name' => self::TOOL,
            'description' => "Record the card's outer edge and the inner edge of its border.",
            'input_schema' => [
                'type' => 'object',
                'properties' => ['outline' => $quad, 'frame' => $quad],
                'required' => ['outline', 'frame'],
            ],
        ];
    }
}
