{{-- Pastille. on : uniquement pour une donnée réellement vivante. --}}
@props(['on' => false])
<span {{ $attributes->class(['dot', 'is-on' => $on]) }} aria-hidden="true"></span>
