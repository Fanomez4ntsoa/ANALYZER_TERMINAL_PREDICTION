{{-- Ligne étiquette / valeur, à placer dans un conteneur .rows. --}}
@props(['label'])
<div {{ $attributes->class('row') }}>
    <span class="lab">{{ $label }}</span>
    <span class="row-value num">{{ $slot }}</span>
</div>
