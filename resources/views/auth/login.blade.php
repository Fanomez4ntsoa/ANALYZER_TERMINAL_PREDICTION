{{--
    Connexion, au style du terminal. Autonome : ni barre du haut ni état du
    pipeline avant authentification.
--}}
<!DOCTYPE html>
<html lang="fr" data-motion="off">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Connexion · Terminal Prédiction</title>
    @vite(['resources/css/terminal.css'])
</head>
<body>
<div class="t-wrap" style="max-width: 360px; padding-top: 12vh;">
    <div class="flex items-center gap-group">
        <div class="topbar-mark" aria-hidden="true">▚</div>
        <div>
            <div class="topbar-title">TERMINAL PRÉDICTION</div>
            <div class="lab">Probabilités, cotes, écarts. Aucune décision.</div>
        </div>
    </div>

    @if (session('status'))
        <div class="state-line" role="status"><span class="state-key">Info</span><span class="state-msg">{{ session('status') }}</span></div>
    @endif
    @foreach ($errors->all() as $error)
        <div class="state-line" role="alert"><span class="state-key">Erreur</span><span class="state-msg" title="{{ $error }}">{{ $error }}</span></div>
    @endforeach

    <x-terminal.panel title="Connexion">
        <form method="POST" action="{{ route('login') }}" class="flex flex-col gap-bd">
            @csrf
            <div class="field">
                <label class="lab" for="email">E-mail</label>
                <input class="input" id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label class="lab" for="password">Mot de passe</label>
                <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
            </div>
            <label class="flex items-center gap-gap lab" for="remember">
                <input class="pick" id="remember" type="checkbox" name="remember">
                Se souvenir de moi
            </label>
            <button type="submit" class="toggle justify-center">Se connecter</button>
        </form>
    </x-terminal.panel>
</div>
</body>
</html>
