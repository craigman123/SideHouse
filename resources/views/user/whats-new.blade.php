@extends('layouts.user')

@section('title', "What's New? | Side House")
@section('page-title', "What's New?")
@section('page-class', 'user-whats-new-page')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/whats-new.css') }}">
@endpush

@section('content')
    <section class="whats-new-page">
        <header class="whats-new-header">
            <h2>What's <span>New?</span></h2>
            <p>Product updates and improvements from Side House.</p>
        </header>

        <div class="whats-new-list">
            @forelse ($updates as $update)
                <article class="whats-new-entry">
                    <div class="whats-new-entry-heading">
                        <h3>{{ $update['title'] }}</h3>
                        @isset($update['tag'])
                            <span class="whats-new-tag">{{ $update['tag'] }}</span>
                        @endisset
                    </div>
                    <time datetime="{{ $update['date'] }}">{{ \Illuminate\Support\Carbon::parse($update['date'])->format('F j, Y') }}</time>
                    <p>{{ $update['description'] }}</p>
                    @if (! empty($update['url']))
                        <a class="whats-new-link" href="{{ $update['url'] }}">{{ $update['link_label'] ?? 'Learn more' }}</a>
                    @endif
                </article>
            @empty
                <div class="whats-new-empty">
                    <h3>You're all caught up</h3>
                    <p>There are no updates to show right now.</p>
                </div>
            @endforelse
        </div>
    </section>
@endsection
