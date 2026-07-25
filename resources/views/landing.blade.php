<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Side House Paddlers</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/landing.css') }}">
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
</head>
<body>

    <div class="landing-wrapper">
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>
        <div class="bg-particle"></div>

        <nav class="landing-nav">
            <img src="{{ asset('images/logo.png') }}" alt="Side House" class="nav-logo">
            <div class="nav-links">
                <a href="{{ route('login') }}" class="nav-btn-ghost">Log In</a>
                <a href="{{ route('register') }}" class="nav-btn-solid">Register</a>
            </div>
        </nav>

        <section class="hero">
            <img src="{{ asset('images/logo.png') }}" alt="Side House" class="hero-logo">
            <h1>Book Your Court. <span>Play Your Game.</span></h1>
            <p>Reserve a pickleball court at Side House in seconds — pick your date, time, and court, and you're set.</p>

            <div class="hero-actions">
                <a href="{{ route('register') }}" class="btn-hero-primary">Get Started</a>
                <a href="{{ route('login') }}" class="btn-hero-secondary">I already have an account</a>
            </div>
        </section>

        <section class="features">
            <div class="feature-card">
                <div class="feature-icon">🏓</div>
                <h3>Easy Booking</h3>
                <p>Pick a court, date, and time — submit your booking in under a minute.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">📅</div>
                <h3>Track Your Games</h3>
                <p>See all your upcoming and past bookings in one place.</p>
            </div>

            <div class="feature-card">
                <div class="feature-icon">⚡</div>
                <h3>Fast & Simple</h3>
                <p>No calls, no messages — just book online and show up ready to play.</p>
            </div>
        </section>

        <footer class="landing-footer">
            &copy; {{ date('Y') }} Side House Paddlers. All rights reserved.
        </footer>
    </div>

</body>
</html>
