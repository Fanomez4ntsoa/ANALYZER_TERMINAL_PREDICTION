{{-- Lecture VT323 moyenne (compteurs, produits du combiné). live : valeur unique vivante. --}}
@props(['live' => false])
<div {{ $attributes->class(['readout num', 'is-live' => $live]) }}>{{ $slot }}</div>
