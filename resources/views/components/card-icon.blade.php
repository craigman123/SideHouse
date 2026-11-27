@props(['name'])

<span {{ $attributes->merge(['class' => 'icon-chip']) }} data-icon="{{ $name }}">
    <svg aria-hidden="true" focusable="false"><use href="#ci-{{ $name }}"></use></svg>
</span>
