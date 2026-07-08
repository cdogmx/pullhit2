<?php

use App\Support\Pricecharting\ChartDataParser;

test('it parses the requested monthly series, dropping zero-value months', function () {
    // Epochs: 2023-09-01, 2023-10-01, 2023-11-01 (UTC-ish). Values are cents.
    $html = <<<'HTML'
    <script>
    if (typeof VGPC == 'undefined') var VGPC = { };
    VGPC.chart_data = {"used":[[1693526400000,5200],[1696118400000,0],[1698796800000,6100]],"new":[[1693526400000,9900]]};
    </script>
    HTML;

    $points = (new ChartDataParser)->parse($html, 'used');

    // The zero month is dropped; two real points remain, in cents.
    expect($points)->toHaveCount(2)
        ->and($points[0]['price'])->toBe(5200)
        ->and($points[1]['price'])->toBe(6100)
        ->and($points[0]['t'])->toStartWith('2023-09');
});

test('it returns nothing when the series or chart_data is absent', function () {
    $html = '<script>VGPC.chart_data = {"used":[[1693526400000,5200]]};</script>';

    expect((new ChartDataParser)->parse($html, 'graded'))->toBe([]) // series missing
        ->and((new ChartDataParser)->parse('<div>nope</div>', 'used'))->toBe([]);
});

test('parseAll maps every grade tier, keyed by grade, skipping empty series', function () {
    // used=Ungraded, new=Grade 8, graded=Grade 9, boxonly=Grade 9.5,
    // manualonly=PSA 10. cib (Grade 7) is present but all-zero → dropped.
    $html = <<<'HTML'
    <script>
    VGPC.chart_data = {
      "used":[[1693526400000,9800]],
      "cib":[[1693526400000,0]],
      "new":[[1693526400000,105544]],
      "graded":[[1693526400000,116100]],
      "boxonly":[[1693526400000,172311]],
      "manualonly":[[1693526400000,273645]]
    };
    </script>
    HTML;

    $tiers = (new ChartDataParser)->parseAll($html);

    expect($tiers)->toHaveKeys(['ungraded', '8', '9', '9.5', '10'])
        ->and($tiers)->not->toHaveKey('7') // all-zero series dropped
        ->and($tiers['ungraded'][0]['price'])->toBe(9800)
        ->and($tiers['10'][0]['price'])->toBe(273645)
        ->and($tiers['9.5'][0]['price'])->toBe(172311);
});
