@props([
    'size' => 24,
    'className' => '',
])

<svg {{ $attributes->merge([
    'viewBox' => '0 0 48 48',
    'xmlns' => 'http://www.w3.org/2000/svg',
    'width' => $size,
    'height' => $size,
    'fill' => 'none',
])->class($className) }} @if (! $attributes->has('aria-label') && ! $attributes->has('aria-hidden')) aria-label="Lumi" @endif>
    <circle cx="24" cy="24" r="20" stroke="currentColor" stroke-width="2.5" opacity="0.35"/>
    <ellipse cx="24" cy="24" rx="20" ry="8" transform="rotate(-30 24 24)" stroke="currentColor" stroke-width="2"/>
    <path d="M24 11 L27.5 20.5 L37 24 L27.5 27.5 L24 37 L20.5 27.5 L11 24 L20.5 20.5 Z" fill="currentColor"/>
    <circle cx="38" cy="12" r="2.5" fill="currentColor"/>
</svg>
