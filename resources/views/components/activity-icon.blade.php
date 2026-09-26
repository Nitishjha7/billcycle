@props(['name'])

@php
    $paths = [
        'arrow-up' => 'M5 10l7-7m0 0l7 7m-7-7v18',
        'x' => 'M6 18L18 6M6 6l12 12',
        'invoice' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'pause' => 'M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z',
    ];
@endphp

<svg {{ $attributes->merge(['class' => 'h-4 w-4']) }} fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $paths[$name] ?? $paths['invoice'] }}" />
</svg>
