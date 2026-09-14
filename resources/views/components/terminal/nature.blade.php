{{-- Nature du marché (principe 3) : « Ajust. » ou « Dérivé ». --}}
@props(['market'])
<span {{ $attributes->class('nature') }} title="{{ \App\Support\Terminal\MarketNature::isDerived($market) ? 'Marché dérivé : calculé à partir des λ, son écart est propre au modèle' : 'Marché d\'ajustement : les λ sont estimés sur ces cotes, l\'écart est mécanique' }}">{{ \App\Support\Terminal\MarketNature::label($market) }}</span>
