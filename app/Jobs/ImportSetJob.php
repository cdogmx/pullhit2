<?php

namespace App\Jobs;

use App\Actions\Catalog\ImportPokemonSet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Queued set import (catalog + images + estimated values). Runs out of the
 * request path — a full set fetches hundreds of cards/images and takes minutes.
 */
class ImportSetJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public string $setId,
        public bool $withPrices = true,
        public bool $withImages = true,
    ) {}

    public function handle(ImportPokemonSet $import): void
    {
        @ini_set('memory_limit', '1024M');

        try {
            $import($this->setId, $this->withPrices, $this->withImages);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
