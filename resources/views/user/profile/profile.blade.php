@extends('layouts.user')

@section('title', 'Profile')
<link rel="stylesheet" href="{{ asset('css/profile.css') }}">
@section('page-title', 'My Profile')

@section('content')

<div class="profile-page">

    <div class="profile-card">

        <div class="profile-header">

            <div class="profile-avatar">
                {{ strtoupper(substr($user->name,0,1)) }}
            </div>

            <div>
                <h2>{{ $user->name }}</h2>
                <p>{{ $user->email }}</p>
            </div>

        </div>

        <div class="profile-grid">

            <div class="profile-item">
                <label>Name</label>
                <input type="text" value="{{ $user->name }}" readonly>
            </div>

            <div class="profile-item">
                <label>Email</label>
                <input type="text" value="{{ $user->email }}" readonly>
            </div>

            <div class="profile-item">
                <label>Member Since</label>
                <input
                    type="text"
                    value="{{ $user->created_at->format('F d, Y') }}"
                    readonly>
            </div>

            <div class="profile-item">
                <label>User ID</label>
                <input
                    type="text"
                    value="{{ $user->user_id }}"
                    readonly>
            </div>

        </div>

        <div class="profile-actions">
            <button class="btn-primary">
                Edit Profile
            </button>
        </div>

    </div>

</div>

@endsection