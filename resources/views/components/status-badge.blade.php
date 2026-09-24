@props(['status'])

@php
    $styles = [
        'active' => 'bg-green-100 text-green-800',
        'trialing' => 'bg-blue-100 text-blue-800',
        'past_due' => 'bg-amber-100 text-amber-800',
        'suspended' => 'bg-red-100 text-red-800',
        'cancelled' => 'bg-gray-100 text-gray-600',
        'open' => 'bg-blue-100 text-blue-800',
        'paid' => 'bg-green-100 text-green-800',
        'void' => 'bg-gray-100 text-gray-600',
    ];
    $label = str($status)->replace('_', ' ')->title();
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium '.($styles[$status] ?? 'bg-gray-100 text-gray-600')]) }}>
    {{ $label }}
</span>
