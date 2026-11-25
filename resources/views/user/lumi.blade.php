@extends('layouts.user')

@section('title', 'Lumi | Side House')
@section('page-title', 'Lumi')
@section('page-class', 'user-lumi-page')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/lumi.css') }}">
@endpush

@section('content')
    <section class="lumi-page">
        <header class="lumi-page-header">
            <div class="lumi-heading">
                <x-lumi-icon size="40" aria-hidden="true" />
                <div>
                    <h2>Lumi</h2>
                    <p>Your Artificial Assistant</p>
                </div>
            </div>
        </header>

        <section class="lumi-suggestion-feed" aria-label="Lumi suggestions">
            @forelse ($suggestions as $suggestion)
                <article class="lumi-suggestion-card" data-suggestion-key="{{ $suggestion['key'] }}"
                         data-suggestion-type="{{ $suggestion['type'] }}">
                    <div class="lumi-suggestion-icon" aria-hidden="true">
                        @switch($suggestion['type'])
                            @case('free_slot_same_day')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 9h18M12 12v4l2 1"/></svg>
                                @break
                            @case('weekday_vacancy')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg>
                                @break
                            @case('open_play')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3"/><path d="M3 20v-1a6 6 0 0 1 12 0v1M16 5.5a3 3 0 0 1 0 5.8M18 14a5 5 0 0 1 3 4.6V20"/></svg>
                                @break
                            @default
                                <x-lumi-icon size="24" aria-hidden="true" />
                        @endswitch
                    </div>
                    <div class="lumi-suggestion-content">
                        <h3>{{ $suggestion['title'] }}</h3>
                        <p>{{ $suggestion['message'] }}</p>
                        <div class="lumi-suggestion-details">
                            <span>{{ \Illuminate\Support\Carbon::parse($suggestion['details']['date'], config('lumi.timezone'))->format('D, M j') }}</span>
                            <span>{{ \Illuminate\Support\Carbon::createFromFormat('!H:i', $suggestion['details']['time'], config('lumi.timezone'))->format('g:i A') }}</span>
                            @if (! empty($suggestion['details']['court']))
                                <span>{{ $suggestion['details']['court'] }}</span>
                            @endif
                            @if (array_key_exists('price', $suggestion['details']))
                                <span>₱{{ number_format($suggestion['details']['price'], 2) }} / player</span>
                            @endif
                            @if (array_key_exists('spots_left', $suggestion['details']))
                                <span>{{ $suggestion['details']['spots_left'] }} spots left</span>
                            @endif
                        </div>
                        <div class="lumi-suggestion-actions">
                            <a class="lumi-suggestion-cta" href="{{ $suggestion['cta_url'] }}">{{ $suggestion['cta_label'] }}</a>
                            <button type="button" class="lumi-dismiss-button"
                                    data-lumi-dismiss
                                    aria-label="Not interested in: {{ $suggestion['title'] }}">Not interested</button>
                        </div>
                        <p class="lumi-dismiss-error" data-lumi-dismiss-error role="status" hidden></p>
                    </div>
                </article>
            @empty
                <div class="lumi-empty-state">
                    <x-lumi-icon size="36" aria-hidden="true" />
                    <p>Nothing new right now. Check back after your next game.</p>
                </div>
            @endforelse
        </section>
    </section>
@endsection
