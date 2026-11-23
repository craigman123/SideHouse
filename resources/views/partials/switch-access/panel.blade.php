@php
    $viewAsAccess = app(\App\Services\UserAccessService::class);
    $viewAsActor = auth()->user();
    $viewAsRealAdmin = $viewAsActor && $viewAsAccess->isRealAdmin($viewAsActor);
    $viewAsRole = $viewAsActor ? $viewAsAccess->effectiveRole($viewAsActor) : 'user';
    $viewAsSelection = session('view_as', 'admin');
    $viewAsOptions = [
        'admin' => 'Admin',
        'owner' => 'Owner',
        'staff' => 'Staff',
        'customer' => 'Customer',
    ];
@endphp

@if ($viewAsRealAdmin)
    <details class="switch-access-panel" data-switch-access>
        <summary class="switch-access-trigger" aria-label="Switch access view" title="Switch access">
            <svg class="switch-access-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h10a4 4 0 0 1 4 4v1M20 17H10a4 4 0 0 1-4-4v-1"/><path d="m15 4 3 3-3 3M9 14l-3 3 3 3"/></svg>
            <span class="switch-access-trigger-copy"><strong>Switch access</strong><small>Viewing as {{ ucfirst($viewAsSelection) }}</small></span>
            <span class="switch-access-chevron" aria-hidden="true">⌄</span>
        </summary>
        <div class="switch-access-options" role="group" aria-label="Choose an access view">
            @foreach ($viewAsOptions as $role => $label)
                <form method="POST" action="{{ route('view-as.switch') }}">
                    @csrf
                    <button type="submit" name="role" value="{{ $role }}" @if ($viewAsSelection === $role) aria-current="true" @endif>
                        <span>{{ $label }}</span>
                        @if ($viewAsSelection === $role)
                            <svg class="switch-access-check" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-label="Current view"><path d="m4 10 4 4 8-8"/></svg>
                        @endif
                    </button>
                </form>
            @endforeach
        </div>
    </details>
@endif
