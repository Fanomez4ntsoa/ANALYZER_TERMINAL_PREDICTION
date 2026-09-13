<?php

namespace App\Jobs;

use App\Services\Betting\ComboSelectorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateDailyCombosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    private string $date;

    public function __construct(?string $date = null)
    {
        $this->date = $date ?? now()->format('Y-m-d');
    }

    public function handle(ComboSelectorService $selector): void
    {
        // Hors flux depuis la simplification (2026-09-13).
        Log::warning("GenerateDailyCombos: job hors flux depuis la simplification, les combos sont débranchés du pipeline ({$this->date}).");
        return;

        // @phpstan-ignore-next-line — code conservé volontairement (débranché)
        Log::info("GenerateDailyCombos: demarrage pour le {$this->date}");

        $result = $selector->generateForDate($this->date);

        $count = count($result['combos']);
        Log::info("GenerateDailyCombos: {$count} combos generes", $result['stats']);
    }
}
