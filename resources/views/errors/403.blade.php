@if (auth()->user()?->role === 'staff')
    @extends('staff.layouts.app')

    @section('title', 'Access denied | Staff | Side House')
    @section('page-title', 'Access denied')

    @section('content')
        <section class="staff-error-panel" aria-labelledby="staffAccessDeniedTitle">
            <h2 id="staffAccessDeniedTitle">You don’t have access to this page</h2>
            <p>Your staff account can only open the pages available in the staff dashboard.</p>
            <a class="btn-secondary btn-ghost-danger" href="{{ route('staff.dashboard') }}">Return to Overview</a>
        </section>
    @endsection
@endif
