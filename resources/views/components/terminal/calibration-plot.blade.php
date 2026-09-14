{{--
    Courbe de calibration d'un marché du run de référence : fréquence observée
    selon la probabilité annoncée, et effectif par tranche de 5 points.
    Mesure historique : vert moyen, aucune lueur, histogramme uniforme.
    $market : une entrée de ReferenceCalibration::summary()['markets'].
--}}
@props(['market'])
@php
    use App\Support\Terminal\Fmt;

    $X0 = 52; $X1 = 452; $Y0 = 214; $Y1 = 20; $NB0 = 312; $NB1 = 252;
    $sx = fn (float $v) => round($X0 + $v * ($X1 - $X0), 1);
    $sy = fn (float $v) => round($Y0 - $v * ($Y0 - $Y1), 1);
    $bins = $market['bins'];
    $maxN = max(1, ...array_column($bins, 'n'));
    $minN = $bins === [] ? 0 : min(array_column($bins, 'n'));
    $bw = ($X1 - $X0) / 20;
    $points = implode(' ', array_map(fn ($b) => $sx($b['mean_model']) . ',' . $sy($b['observed']), $bins));
@endphp
<svg {{ $attributes->class('plot') }} viewBox="0 0 470 330" preserveAspectRatio="xMidYMid meet" role="img"
     aria-label="Calibration {{ $market['label'] }} : fréquence observée selon la probabilité annoncée, {{ count($bins) }} tranches, {{ Fmt::number($market['n'], 0) }} lignes">
    @foreach ([0, 0.25, 0.5, 0.75, 1] as $v)
        <line class="gridline" x1="{{ $sx($v) }}" y1="{{ $Y1 }}" x2="{{ $sx($v) }}" y2="{{ $Y0 }}" />
        <line class="gridline" x1="{{ $X0 }}" y1="{{ $sy($v) }}" x2="{{ $X1 }}" y2="{{ $sy($v) }}" />
        <text class="tick" x="{{ $X0 - 6 }}" y="{{ $sy($v) + 3 }}" text-anchor="end">{{ (int) round($v * 100) }}</text>
        <text class="tick" x="{{ $sx($v) }}" y="{{ $Y0 + 14 }}" text-anchor="middle">{{ (int) round($v * 100) }}</text>
    @endforeach
    <line class="ref" x1="{{ $sx(0) }}" y1="{{ $sy(0) }}" x2="{{ $sx(1) }}" y2="{{ $sy(1) }}" />
    <line class="axis" x1="{{ $X0 }}" y1="{{ $Y0 }}" x2="{{ $X1 }}" y2="{{ $Y0 }}" />
    <line class="axis" x1="{{ $X0 }}" y1="{{ $Y1 }}" x2="{{ $X0 }}" y2="{{ $Y0 }}" />
    <polyline class="curve" points="{{ $points }}" />
    @foreach ($bins as $b)
        <circle class="pt" cx="{{ $sx($b['mean_model']) }}" cy="{{ $sy($b['observed']) }}" r="2.4">
            <title>Annoncé {{ Fmt::percent($b['mean_model']) }} %, observé {{ Fmt::percent($b['observed']) }} %, {{ Fmt::number($b['n'], 0) }} lignes</title>
        </circle>
    @endforeach
    @foreach ($bins as $b)
        @php $h = round(($b['n'] / $maxN) * ($NB0 - $NB1), 1); $i = (int) round($b['from'] * 20); @endphp
        <rect class="nbar" x="{{ round($X0 + $i * $bw + 1, 1) }}" y="{{ $NB0 - $h }}" width="{{ round($bw - 2, 1) }}" height="{{ $h }}">
            <title>Tranche {{ (int) round($b['from'] * 100) }}–{{ (int) round($b['to'] * 100) }} % : {{ Fmt::number($b['n'], 0) }} lignes</title>
        </rect>
    @endforeach
    <line class="axis" x1="{{ $X0 }}" y1="{{ $NB0 }}" x2="{{ $X1 }}" y2="{{ $NB0 }}" />
    <text class="tick" x="{{ $X1 }}" y="{{ $Y0 + 28 }}" text-anchor="end">ANNONCÉ %</text>
    <text class="tick" x="{{ $X0 }}" y="12">OBSERVÉ %</text>
    <text class="tick" x="{{ $X0 - 6 }}" y="{{ $NB0 - 2 }}" text-anchor="end">N</text>
    <text class="tick" x="{{ $X1 }}" y="{{ $NB0 + 14 }}" text-anchor="end">EFFECTIF PAR TRANCHE DE 5 PTS · {{ Fmt::number($minN, 0) }} À {{ Fmt::number($maxN, 0) }}</text>
</svg>
