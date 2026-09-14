{{--
    Ligne étiquette / valeur, à placer dans un conteneur .rows. L'étiquette est en
    capitales : une lettre grecque doit passer par une HtmlString avec
    <span class="normal-case">, sinon ρ devient Ρ et se lit P.
--}}
@props(['label'])
<div {{ $attributes->class('row') }}>
    <span class="lab">{{ $label }}</span>
    <span class="row-value num">{{ $slot }}</span>
</div>
