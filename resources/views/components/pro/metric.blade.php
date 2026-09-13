@props(['label', 'value', 'sublabel' => null, 'trend' => null, 'color' => 'brand'])

@php
    $colorMap = [
        'brand' => 'bg-blue-50 text-blue-700',
        'green' => 'bg-emerald-50 text-emerald-700',
        'amber' => 'bg-amber-50 text-amber-700',
        'red' => 'bg-red-50 text-red-700',
        'slate' => 'bg-slate-100 text-slate-700',
    ];
    $pillClass = $colorMap[$color] ?? $colorMap['brand'];
@endphp

<div class="bg-white rounded-xl border border-slate-200 shadow-sm p-5">
    <p class="text-xs font-medium text-slate-500 uppercase tracking-wide">{{ $label }}</p>
    <p class="mt-2 text-2xl font-bold text-slate-800">{{ $value }}</p>
    <div class="mt-1 flex items-center gap-2">
        @if($sublabel)
            <span class="text-xs text-slate-400">{{ $sublabel }}</span>
        @endif
        @if($trend !== null)
            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium {{ $trend >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">
                {{ $trend >= 0 ? '+' : '' }}{{ $trend }}%
            </span>
        @endif
    </div>
</div>
