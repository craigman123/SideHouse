@extends('layouts.user')

@section('title', 'Lumi | Side House')
@section('page-title', 'Lumi')
@section('page-class', 'user-lumi-page')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/lumi.css') }}">
@endpush

@section('content')
    <section class="lumi-page" id="lumiChat" data-reply-url="{{ route('user.lumi.message') }}">
        <header class="lumi-page-header">
            <div class="lumi-heading">
                <x-lumi-icon size="40" aria-hidden="true" />
                <div>
                    <h2>Lumi</h2>
                    <p>Your Artificial Assistant</p>
                </div>
            </div>
            <button type="button" class="lumi-clear-button" id="lumiClearOpen" aria-haspopup="dialog">
                Clear conversation
            </button>
        </header>

        <section class="lumi-chat-panel" aria-label="Chat with Lumi">
            <div class="lumi-messages" id="lumiMessages" role="log" aria-live="polite" aria-relevant="additions text" aria-label="Conversation">
                <div class="lumi-empty-state" id="lumiEmptyState">
                    <x-lumi-icon size="48" aria-hidden="true" />
                    <h3>Hi, I'm Lumi.</h3>
                    <p>Ask me about booking a court, managing a reservation, or using your credits.</p>
                    <div class="lumi-prompts" role="group" aria-label="Suggested questions">
                        <button type="button" data-lumi-prompt="How do I book a court?">How do I book a court?</button>
                        <button type="button" data-lumi-prompt="Where can I see my bookings?">Where can I see my bookings?</button>
                        <button type="button" data-lumi-prompt="How do credits work?">How do credits work?</button>
                        <button type="button" data-lumi-prompt="How can I cancel a booking?">How can I cancel a booking?</button>
                    </div>
                </div>
            </div>

            <div class="lumi-status lumi-typing" id="lumiTyping" role="status" aria-live="polite" hidden>
                <span aria-hidden="true"><i></i><i></i><i></i></span>
                <span>Lumi is thinking</span>
            </div>

            <div class="lumi-error" id="lumiError" role="alert" hidden>
                <p>Lumi couldn't respond. Check your connection and try again.</p>
                <button type="button" id="lumiRetry">Retry</button>
            </div>

            <form class="lumi-composer" id="lumiForm">
                <label class="lumi-visually-hidden" for="lumiInput">Message Lumi</label>
                <textarea id="lumiInput" name="message" rows="1" maxlength="2000" placeholder="Message Lumi..." required></textarea>
                <button type="submit" class="lumi-send-button" id="lumiSend" aria-label="Send message" title="Send message">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/></svg>
                </button>
            </form>
            <p class="lumi-composer-hint">Enter to send · Shift+Enter for a new line</p>
        </section>
    </section>

    <dialog class="lumi-clear-dialog" id="lumiClearDialog" aria-labelledby="lumiClearTitle">
        <form method="dialog">
            <h2 id="lumiClearTitle">Clear this conversation?</h2>
            <p>Your messages will be removed from this page.</p>
            <div class="lumi-dialog-actions">
                <button type="submit" class="lumi-dialog-cancel" value="cancel">Keep conversation</button>
                <button type="button" class="lumi-dialog-confirm" id="lumiClearConfirm">Clear conversation</button>
            </div>
        </form>
    </dialog>
@endsection

@push('scripts')
    <script src="{{ asset('js/user/lumi.js') }}" defer></script>
@endpush
