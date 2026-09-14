{{--
    Cartouche d'une mesure historique : ce qui a été mesuré, sur quoi, et ce
    que la mesure ne décrit pas. Vert moyen, jamais de lueur.
--}}
<div {{ $attributes->class('measure') }}>
    <span class="lab">Mesure</span>
    <span>{{ $slot }}</span>
</div>
