@props([
    'url',
    'label',
    'variant' => 'primary', // primary | success | gold | danger | outline
])

@php
    $styles = match ($variant) {
        'success' => 'background: linear-gradient(135deg, #168157 0%, #1E9D6B 100%); color: #ffffff; border: 1px solid #14744E; box-shadow: 0 6px 18px rgba(30,157,107,0.28);',
        'gold' => 'background: linear-gradient(135deg, #A86B1B 0%, #C0832B 100%); color: #ffffff; border: 1px solid #925D15; box-shadow: 0 6px 18px rgba(192,131,43,0.28);',
        'danger' => 'background: linear-gradient(135deg, #A62D21 0%, #C0392B 100%); color: #ffffff; border: 1px solid #91271D; box-shadow: 0 6px 18px rgba(192,57,43,0.28);',
        'outline' => 'background: #FFFFFF; color: #0E5C9C; border: 1.5px solid #0E5C9C; box-shadow: 0 3px 10px rgba(10,42,85,0.06);',
        default => 'background: linear-gradient(135deg, #0A2A55 0%, #0E5C9C 50%, #11A0C8 110%); color: #ffffff; border: 1px solid #0A2A55; box-shadow: 0 6px 20px rgba(14,92,156,0.30);',
    };
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 16px;">
    <tr>
        <td align="center" style="padding:0;text-align:center;">
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:13px 32px;border-radius:10px;text-decoration:none;font-family:'Tajawal',Arial,sans-serif;font-weight:700;font-size:14.5px;text-align:center;letter-spacing:0.2px;{{ $styles }}">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>
