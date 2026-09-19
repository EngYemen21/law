<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\SettingsRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **إعدادات النظام** — المدخل الواحد للمتغيّرات التي كانت حبيسة الشيفرة أو حبيسة `tinker`.
 *
 * `exec_working_days_from` مثالها الأصرح: إعدادٌ وُضع في الجدول **عمداً** («كي لا يحتاج
 * تغييرُه نشرَ كود» — `ExecFlow`) ثمّ بقي بلا شاشة، فتغييره على الإنتاج يلزمه SQL مباشر.
 *
 * والوصف والنوع والافتراض والتحقّق كلُّها من `SettingsRegistry` لا من هنا: المتحكّم ينقل
 * ولا يعرّف، فإضافة متغيّرٍ لاحقاً سطرٌ في السجلّ لا تعديلٌ في ثلاثة ملفّات.
 */
class SettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/settings', [
            'groups' => SettingsRegistry::groups(),
            'fields' => SettingsRegistry::all(),
            'values' => SettingsRegistry::values(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(SettingsRegistry::rulesFor());

        $before = SettingsRegistry::values();
        $changed = [];

        // **ما تغيّر فعلاً وحده يُكتب**: حفظُ بطاقةٍ لم يُمَسّ فيها حقل كان سيقيّد في التدقيق
        // «تعديلاً» بلا تعديل، ويكتب صفوفاً لا تحمل جديداً. والمفاتيح من السجلّ لا من الطلب —
        // فمفتاحٌ خارجه لا يبلغ `Setting::put` بحال.
        foreach (SettingsRegistry::all() as $key => $field) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $value = $field['type'] === 'int' ? (int) $data[$key] : trim((string) $data[$key]);

            if ($value === $before[$key]) {
                continue;
            }

            Setting::put($key, $value);
            $changed[$key] = $value;
        }

        // لا إبطالَ يدويّاً للقراءة المحفوظة: `Setting::saved` في `AppServiceProvider` يتولّاه.
        if ($changed === []) {
            return back()->with('flash', 'لا تغيير — القيم المرسلة تطابق المحفوظة.');
        }

        // **الفرق وحده يُقيَّد** لا الحالة كاملةً: قيدٌ يحمل عشرة مفاتيح لتغيير واحد يُخفي
        // ما تغيّر بين ما لم يتغيّر، ومن يقرأ السجلّ يسأل «ماذا تغيّر» لا «ما القيم يومها».
        $names = implode('، ', array_map(fn ($key) => SettingsRegistry::all()[$key]['label'], array_keys($changed)));

        Audit::log(
            action: 'تعديل إعدادات النظام',
            description: "عدّل {$request->user()->name} إعدادات النظام: {$names}.",
            category: 'عام',
            severity: 'warning',
            beforeState: array_intersect_key($before, $changed),
            afterState: $changed,
        );

        return back()->with('flash', 'حُفظت الإعدادات — وتسري على ما يُنشأ بعدها.');
    }
}
