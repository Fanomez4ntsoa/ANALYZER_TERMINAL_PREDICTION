{{--
    Chiffre d'affichage VT323, décimales en petit. value : texte déjà formaté
    (App\Support\Terminal\Fmt). live : vert vif, valeur unique vivante.
    glow : lueur, budget de deux effets rendus par page ; jamais sur une mesure
    historique.
--}}
@props(['value', 'suffix' => null, 'live' => false, 'glow' => false])
@php
    [$int, $dec] = array_pad(explode(',', (string) $value, 2), 2, null);
    $html = e($int)
        . ($dec !== null ? '<small>,</small>' . e($dec) : '')
        . ($suffix !== null ? '<small>' . e($suffix) . '</small>' : '');
@endphp
<div {{ $attributes->class(['figure num', 'is-live' => $live]) }} @if ($glow) data-glow @endif>{!! $html !!}</div>
