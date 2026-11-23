@php
    $viewAsAccess = app(\App\Services\UserAccessService::class);
    $viewAsActor = auth()->user();
    $viewAsRealAdmin = $viewAsActor && $viewAsAccess->isRealAdmin($viewAsActor);
    $viewAsRole = $viewAsActor ? $viewAsAccess->effectiveRole($viewAsActor) : 'user';
    $viewAsLabel = $viewAsRole === 'user' ? 'Customer' : ucfirst($viewAsRole);
@endphp

@if ($viewAsRealAdmin && $viewAsRole !== 'admin')
    <aside class="view-as-banner" role="status" aria-label="Current access view">
        <div class="view-as-banner-copy">
            <strong>Viewing as {{ $viewAsLabel }}</strong>
            @if ($viewAsRole === 'user')
                <span>This is your own admin account. Bookings, payments and profile changes apply to you.</span>
            @else
                <span>Your current pages and actions follow the {{ strtolower($viewAsLabel) }} role.</span>
            @endif
        </div>
        <form method="POST" action="{{ route('view-as.reset') }}">
            @csrf
            <button type="submit">Return to Admin</button>
        </form>
    </aside>
@endif
