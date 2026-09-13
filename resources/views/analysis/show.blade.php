@extends('layouts.pro')

@section('title', 'Détail du Match')
@section('page-title', 'Détail du Match')

@section('content')
<div class="container mx-auto px-4 py-8">

    <div class="mb-6">
        <a href="{{ route('history.index') }}"
           class="inline-flex items-center gap-2 text-blue-800 hover:text-blue-700 font-medium">
            ← Retour à l'historique
        </a>
    </div>

    <div class="space-y-6">
        @include('analysis.partials.match-header', ['footballMatch' => $footballMatch])

        @include('analysis.partials.predictions', ['predictionsByMarket' => $predictionsByMarket])

        @include('analysis.partials.actions')
    </div>

</div>
@endsection
