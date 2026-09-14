{{--
    Panneau : en-tête (pastille, titre, méta à droite) et corps.
    live : pastille active, seulement si le panneau montre une donnée vivante.
--}}
@props(['title', 'live' => false])
<section {{ $attributes->class('panel') }}>
    <div class="panel-hd">
        <x-terminal.dot :on="$live" />
        <h2 class="lab">{{ $title }}</h2>
        @isset($meta)
            <div class="sp"></div>
            <div class="flex items-center gap-gap lab">{{ $meta }}</div>
        @endisset
    </div>
    <div class="panel-bd">
        {{ $slot }}
    </div>
</section>
