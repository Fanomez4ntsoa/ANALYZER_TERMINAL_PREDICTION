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
        Log::info("GenerateDailyCombos: demarrage pour le {$this->date}");

        $result = $selector->generateForDate($this->date);

        $count = count($result['combos']);
        Log::info("GenerateDailyCombos: {$count} combos generes", $result['stats']);
    }
}
