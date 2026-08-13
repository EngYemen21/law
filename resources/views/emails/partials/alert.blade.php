@props([
    'type' => 'info', // info | success | warning | danger
    'title' => null,
])

@php
    $style = match ($type) {
        'success' => 'background-color: #EAF8F1; border-color: #A3E6C7; border-right-color: #1E9D6B; color: #116945;',
        'warning' => 'background-color: #FEF9EE; border-color: #F8DC9F; border-right-color: #C0832B; color: #8C5913;',
        'danger' => 'background-color: #FDF2F0; border-color: #F7B8B0; border-right-color: #C0392B; color: #8F251A;',
        default => 'background-color: #EBF6FC; border-color: #BBE1F5; border-right-color: #0E5C9C; color: #09477A;',
    };
@endphp

<div style="margin:20px 0;padding:14px 18px;border-radius:12px;border:1px solid;border-right:4px solid;font-size:13.5px;line-height:1.7;{{ $style }}">
    @if($title)
        <div style="font-weight:800;margin-bottom:4px;font-size:14px;">{{ $title }}</div>
    @endif
    {{ $slot }}
</div>
