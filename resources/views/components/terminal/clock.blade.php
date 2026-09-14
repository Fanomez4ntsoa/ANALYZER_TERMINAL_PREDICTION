{{-- Horloge UTC, animée par resources/js/terminal/clock.js. Valeur unique vivante : vert vif. --}}
<time {{ $attributes->class('clock num') }} data-clock>{{ now()->utc()->format('H:i:s') }}</time>
