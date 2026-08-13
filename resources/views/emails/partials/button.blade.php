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

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 20px;">
    <tr>
        <td align="center">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:46px;v-text-anchor:middle;width:240px;" arcsize="24%" fillcolor="#0E5C9C" stroke="f">
            <w:anchorlock/>
            <center style="color:#ffffff;font-family:'Tajawal',Arial,sans-serif;font-size:14.5px;font-weight:bold;">{{ $label }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" style="display:inline-block;padding:13px 32px;border-radius:12px;text-decoration:none;font-weight:800;font-size:14.5px;text-align:center;letter-spacing:0.2px;transition:all 0.2s ease;{{ $styles }}">
                {{ $label }}
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
