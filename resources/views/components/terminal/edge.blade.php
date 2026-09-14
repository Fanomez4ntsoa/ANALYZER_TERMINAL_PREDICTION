{{--
    Écart en points signés. Couleur = signe, sur un marché dérivé seulement ;
    marché d'ajustement ou écart arrondi à 0,0 : sans couleur. Cote absente :
    rien. Règles dans App\Support\Terminal\MarketNature::edgeTone.
--}}
@props(['edge', 'market'])
@php
    $tone = \App\Support\Terminal\MarketNature::edgeTone($market, $edge);
@endphp
<span {{ $attributes->class(['num', 'edge-pos' => $tone === 'pos', 'edge-neg' => $tone === 'neg']) }}>{{ \App\Support\Terminal\Fmt::points($edge) }}</span>
