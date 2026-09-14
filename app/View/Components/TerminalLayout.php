<?php

namespace App\View\Components;

use App\Support\Terminal\PipelineFreshness;
use App\Support\Terminal\SystemState;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Layout du terminal. Reçoit les états propres à la page (écart de
 * configuration, erreur d'API…), y ajoute la fraîcheur du pipeline, et répartit
 * le tout : un seul bloc en vidéo inverse, les autres en ligne.
 */
class TerminalLayout extends Component
{
    public ?SystemState $inverse;

    /** @var SystemState[] */
    public array $lines;

    public array $freshness;

    /**
     * @param  SystemState[]  $states
     * @param  bool  $fit  page tenue dans la hauteur de l'écran sur grand écran
     */
    public function __construct(
        PipelineFreshness $pipeline,
        public string $title = 'Terminal',
        array $states = [],
        public bool $fit = false,
    ) {
        $this->freshness = $pipeline->current();
        ['inverse' => $this->inverse, 'lines' => $this->lines] = SystemState::arrange([...$this->freshness['states'], ...$states]);
    }

    public function render(): View
    {
        return view('layouts.terminal');
    }
}
