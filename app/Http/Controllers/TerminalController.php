<?php

namespace App\Http\Controllers;

use App\Services\Backtesting\FootballData\ReferenceCalibration;
use App\Services\DataPipeline\PipelineLog;
use App\Services\Market\CLVTrackerService;
use App\Support\Terminal\SelectionsBoard;
use App\Support\Terminal\SystemState;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Page principale du terminal : sélections du jour et combiné, Monte-Carlo du
 * match choisi, calibration du run de référence, écart de clôture.
 */
class TerminalController extends Controller
{
    public function index(Request $request, SelectionsBoard $board, ReferenceCalibration $reference, CLVTrackerService $clv): View
    {
        $date = $this->date($request->query('date'));
        $states = [];

        $calibration = null;
        try {
            $calibration = $reference->summary();
            if ($calibration === null) {
                $states[] = new SystemState(SystemState::WARNING, 'Calibration', 'Run de référence introuvable ou non terminé');
            } elseif ($calibration['config_mismatches'] !== []) {
                $states[] = new SystemState(
                    SystemState::NOTICE,
                    'Config',
                    "Run #{$calibration['run_id']} mesuré avec une configuration différente de la production",
                    implode(' ; ', $calibration['config_mismatches']),
                );
            }
        } catch (\Throwable $e) {
            PipelineLog::caught('Terminal : calibration de référence', $e);
            $states[] = new SystemState(SystemState::WARNING, 'Calibration', 'Calibration de référence illisible', $e->getMessage());
        }

        $closing = null;
        try {
            $closing = $clv->getSummary();
        } catch (\Throwable $e) {
            PipelineLog::caught('Terminal : écart de clôture', $e);
            $states[] = new SystemState(SystemState::WARNING, 'CLV', 'Écart de clôture illisible', $e->getMessage());
        }

        $selections = $board->forDate($date->toDateString());

        return view('terminal.index', [
            'date' => $date,
            'isToday' => $date->isSameDay(CarbonImmutable::now('UTC')),
            'selections' => $selections,
            'calibration' => $calibration,
            'closing' => $closing,
            'states' => $states,
        ]);
    }

    private function date(mixed $value): CarbonImmutable
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            try {
                return CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
            } catch (\Throwable) {
                // date invalide : on retombe sur aujourd'hui
            }
        }

        return CarbonImmutable::now('UTC')->startOfDay();
    }
}
