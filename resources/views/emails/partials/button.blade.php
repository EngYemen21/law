@props(['url', 'label'])
<div style="text-align:center;margin:22px 0;">
    <a href="{{ $url }}" style="display:inline-block;background:linear-gradient(135deg,#0E5C9C,#11A0C8);color:#ffffff;text-decoration:none;font-weight:800;font-size:14px;padding:12px 26px;border-radius:11px;">
        {{ $label }}
    </a>
</div>
