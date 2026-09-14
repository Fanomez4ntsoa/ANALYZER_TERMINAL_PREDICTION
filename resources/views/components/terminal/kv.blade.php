{{-- Paire étiquette / valeur de barre ou de panneau. --}}
@props(['label'])
<div {{ $attributes->class('kv') }}>
    <span class="lab">{{ $label }}</span>
    <span class="kv-value num">{{ $slot }}</span>
</div>
