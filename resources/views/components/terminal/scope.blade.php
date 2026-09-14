{{-- Match hors périmètre des cotes : affiché et étiqueté, jamais omis. --}}
@props(['league' => null])
<span {{ $attributes->class('scope') }}>Hors périmètre des cotes @if ($league)· {{ $league }}@endif</span>
