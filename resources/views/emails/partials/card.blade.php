@props([
    'rows' => [], // array of ['label' => '...', 'value' => '...'] or key => value
    'title' => null,
])

<table role="presentation" class="meta-table" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0;background-color:#F8FAFC;border:1px solid #E2E8F0;border-right:4px solid #0E5C9C;border-radius:12px;overflow:hidden;">
    @if($title)
    <tr>
        <td colspan="2" style="padding:12px 18px 8px;font-weight:800;font-size:13.5px;color:#0A2A55;border-bottom:1px solid #EDF2F7;background:#F1F5F9;">
            {{ $title }}
        </td>
    </tr>
    @endif
    
    @foreach($rows as $label => $value)
        @php
            if (is_array($value)) {
                $lbl = $value['label'] ?? $label;
                $val = $value['value'] ?? '';
            } else {
                $lbl = $label;
                $val = $value;
            }
        @endphp
        @if(filled($val))
        <tr>
            <td class="meta-label" style="padding:11px 18px;font-size:13px;font-weight:700;color:#64748B;width:35%;border-bottom:1px solid #EDF2F7;vertical-align:top;background:#FAFCFD;">
                {{ $lbl }}
            </td>
            <td class="meta-value" style="padding:11px 18px;font-size:13.5px;font-weight:600;color:#1E293B;border-bottom:1px solid #EDF2F7;vertical-align:top;">
                {!! is_string($val) ? e($val) : $val !!}
            </td>
        </tr>
        @endif
    @endforeach
</table>
