{{--
    État système. Ne pas poser inverse à la main : TerminalLayout choisit le seul
    bloc en vidéo inverse de la page (SystemState::arrange).
--}}
@props(['state', 'inverse' => false])
@if ($inverse)
    <div class="state-inverse" role="alert">
        <span class="state-key">{{ $state->key }}</span>
        <span>{{ $state->message }}@if ($state->detail) · {{ $state->detail }}@endif</span>
    </div>
@else
    <div class="state-line" role="status" @if ($state->detail) title="{{ $state->message }} · {{ $state->detail }}" @endif>
        <span class="state-key">{{ $state->key }}</span>
        <span class="state-msg">{{ $state->message }}</span>
    </div>
@endif
