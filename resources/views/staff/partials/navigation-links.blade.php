@foreach (config('user_access.staff_navigation', []) as $item)
    @if ($item['permission'] === null || $staffAccess->allows(auth()->user(), $item['permission']))
        <a href="{{ route($item['route']) }}" @class(['is-active' => request()->routeIs($item['route'])])>{{ $item['label'] }}</a>
    @endif
@endforeach
