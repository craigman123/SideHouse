@extends('layouts.user')

@section('title', 'Feedback & Suggestions | Side House')
@section('page-class', 'user-feedback-page')
@section('page-title', 'Feedback & Suggestions')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/user/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/user/feedback.css') }}">
@endpush

@section('content')

    <div class="feedback-content">
    <div class="feedback-page-heading">
        <p>Tell us about your visit and help us improve Side House Paddlers.</p>
    </div>

    <div class="feedback-columns">
    <section class="panel feedback-form-panel" aria-labelledby="feedback-form-title">
        <h2 id="feedback-form-title">Share Your Experience</h2>
        <p class="panel-subtext">Rate your overall experience and let us know how we can make Side House Paddlers better.</p>

        @if (session('success'))
            <div class="feedback-flash feedback-flash-success">{{ session('success') }}</div>
        @endif

        <form method="POST" action="{{ route('user.feedback.store') }}" id="feedbackForm" novalidate>
            @csrf

            <div class="form-group">
                <label id="ratingLabel">Overall Experience</label>
                <div
                    class="star-rating"
                    id="starRating"
                    data-value="{{ old('rating', 0) }}"
                    role="radiogroup"
                    aria-labelledby="ratingLabel"
                >
                    @for ($i = 1; $i <= 5; $i++)
                        <button
                            type="button"
                            class="star-btn"
                            data-star="{{ $i }}"
                            role="radio"
                            aria-checked="false"
                            aria-label="{{ $i }} star{{ $i > 1 ? 's' : '' }}"
                        >&#9733;</button>
                    @endfor
                </div>
                <input type="hidden" name="rating" id="ratingInput" value="{{ old('rating', '') }}">
                <span class="error-text field-error" data-field="rating">{{ $errors->first('rating') }}</span>
            </div>

            <div class="form-group">
                <label for="category">What's this about?</label>
                <select id="category" name="category">
                    <option value="">General</option>
                    <option value="court_quality" {{ old('category') === 'court_quality' ? 'selected' : '' }}>Court Quality</option>
                    <option value="booking_process" {{ old('category') === 'booking_process' ? 'selected' : '' }}>Booking Process</option>
                    <option value="equipment" {{ old('category') === 'equipment' ? 'selected' : '' }}>Equipment</option>
                    <option value="staff" {{ old('category') === 'staff' ? 'selected' : '' }}>Staff &amp; Service</option>
                    <option value="other" {{ old('category') === 'other' ? 'selected' : '' }}>Other</option>
                </select>
                <span class="error-text field-error" data-field="category">{{ $errors->first('category') }}</span>
            </div>

            <div class="form-group">
                <label for="message">Tell us more (optional)</label>
                <textarea id="message" name="message" rows="4" maxlength="2000" placeholder="What went well? What could be better?">{{ old('message') }}</textarea>
                <span class="error-text field-error" data-field="message">{{ $errors->first('message') }}</span>
            </div>

            <button type="submit" class="btn btn-primary-sm">Submit Feedback</button>
        </form>
    </section>

    <section class="panel feedback-history-panel" aria-labelledby="feedback-history-title">
        <h2 id="feedback-history-title">Your Previous Feedback</h2>

        <div class="feedback-list" role="region" aria-label="Previous feedback list" @if ($feedbacks->isEmpty()) tabindex="0" @endif>
        @forelse ($feedbacks as $feedback)
            <div class="feedback-entry">
                <div class="feedback-view">
                    <div class="feedback-entry-header">
                        <span class="feedback-entry-stars" aria-label="{{ $feedback->rating }} out of 5 stars">
                            @for ($i = 1; $i <= 5; $i++)
                                <span class="feedback-star {{ $i <= $feedback->rating ? 'filled' : '' }}">&#9733;</span>
                            @endfor
                        </span>
                        <span class="feedback-entry-date">{{ $feedback->created_at->format('M d, Y \a\t g:i A') }}</span>
                    </div>

                    <p class="feedback-entry-meta">By {{ auth()->user()->name }}</p>

                    @if ($feedback->category)
                        <span class="feedback-entry-category">{{ ucfirst(str_replace('_', ' ', $feedback->category)) }}</span>
                    @endif

                    @if ($feedback->message)
                        <p class="feedback-entry-message">{{ $feedback->message }}</p>
                    @endif

                    <div class="feedback-entry-actions">
                        <button type="button" class="btn-icon btn-edit-toggle">Edit</button>

                        <form method="POST" action="{{ route('user.feedback.destroy', $feedback) }}" class="feedback-delete-form" onsubmit="return confirm('Delete this feedback? This can\'t be undone.');">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn-icon btn-delete">Delete</button>
                        </form>
                    </div>
                </div>

                <form method="POST" action="{{ route('user.feedback.update', $feedback) }}" class="feedback-edit" hidden>
                    @csrf
                    @method('PUT')

                    <div class="form-group">
                        <label>Overall Experience</label>
                        <select name="rating" required>
                            @for ($i = 1; $i <= 5; $i++)
                                <option value="{{ $i }}" {{ $feedback->rating === $i ? 'selected' : '' }}>{{ $i }} star{{ $i > 1 ? 's' : '' }}</option>
                            @endfor
                        </select>
                    </div>

                    <div class="form-group">
                        <label>What's this about?</label>
                        <select name="category">
                            <option value="" {{ ! $feedback->category ? 'selected' : '' }}>General</option>
                            <option value="court_quality" {{ $feedback->category === 'court_quality' ? 'selected' : '' }}>Court Quality</option>
                            <option value="booking_process" {{ $feedback->category === 'booking_process' ? 'selected' : '' }}>Booking Process</option>
                            <option value="equipment" {{ $feedback->category === 'equipment' ? 'selected' : '' }}>Equipment</option>
                            <option value="staff" {{ $feedback->category === 'staff' ? 'selected' : '' }}>Staff &amp; Service</option>
                            <option value="other" {{ $feedback->category === 'other' ? 'selected' : '' }}>Other</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Tell us more (optional)</label>
                        <textarea name="message" rows="3" maxlength="2000">{{ $feedback->message }}</textarea>
                    </div>

                    <div class="feedback-edit-actions">
                        <button type="button" class="btn-icon btn-cancel-edit btn-ghost-cancel">Cancel</button>
                        <button type="submit" class="btn btn-primary-sm">Save Changes</button>
                    </div>
                </form>
            </div>
        @empty
            <div class="empty-row">
                <svg class="feedback-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M21 11.5a8.5 8.5 0 0 1-12.7 7.4L3 21l2.1-5.3A8.5 8.5 0 1 1 21 11.5Z"/><path d="M8 12h.01M12 12h.01M16 12h.01"/></svg>
                <p>You haven't submitted any feedback yet — your review after a session helps us improve.</p>
            </div>
        @endforelse
        </div>
    </section>
    </div>
    </div>

@endsection

@push('scripts')
    <script src="{{ asset('js/user/feedback.js') }}" defer></script>
@endpush
