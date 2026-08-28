<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * أسعار الاستشارات — تُدار من الإدارة وتُحفظ في جدول settings، وتنعكس على كل حجز.
 */
class PriceController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/prices', ['prices' => Setting::consultPrices()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'office' => ['required', 'integer', 'min:0', 'max:100000'],
            'video' => ['required', 'integer', 'min:0', 'max:100000'],
            'phone' => ['required', 'integer', 'min:0', 'max:100000'],
            'vat' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $before = Setting::consultPrices();

        Setting::put('price_office', $data['office']);
        Setting::put('price_video', $data['video']);
        Setting::put('price_phone', $data['phone']);
        Setting::put('vat_rate', $data['vat']);

        Audit::log(
            action: 'تعديل أسعار الاستشارات',
            description: "عدّل {$request->user()->name} أسعار الاستشارات المعتمدة (حضورية {$data['office']} / مرئية {$data['video']} / هاتفية {$data['phone']} / ضريبة {$data['vat']}%).",
            category: 'مالية وفواتير',
            severity: 'warning',
            beforeState: $before,
            afterState: $data,
        );

        return back()->with('flash', 'تم حفظ أسعار الاستشارات، وتُطبَّق فوراً على الحجوزات الجديدة.');
    }
}
