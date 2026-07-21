<?php

namespace App\Support\Grading;

/**
 * The result of a grade-or-sell analysis (cents). `advantage` is grade EV minus
 * sell-raw EV — positive favours grading. `breakevenP10` is the PSA-10 probability
 * at which grading breaks even against selling raw (null when a PSA 10 isn't worth
 * more than raw after fees, i.e. never worth grading).
 */
readonly class GradeAdvice
{
    /**
     * @param  array<string, float>  $probs  grade => probability used
     */
    public function __construct(
        public int $raw,
        public int $evGrade,
        public int $evRaw,
        public int $advantage,
        public ?float $breakevenP10,
        public string $verdict,
        public int $feeCents,
        public array $probs,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'raw' => $this->raw,
            'ev_grade' => $this->evGrade,
            'ev_raw' => $this->evRaw,
            'advantage' => $this->advantage,
            'breakeven_p10' => $this->breakevenP10,
            'verdict' => $this->verdict,
            'fee' => $this->feeCents,
            'probs' => $this->probs,
        ];
    }
}
