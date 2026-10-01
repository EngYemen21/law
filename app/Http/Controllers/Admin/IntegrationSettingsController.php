<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Support\Integrations\IntegrationRegistry;
use App\Support\Integrations\IntegrationSecrets;
use App\Support\Notify;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **مفاتيح الخدمات الخارجيّة** — تحديثها من الشاشة بدل `.env` على الخادم (قرار المالك 2026-09-30).
 *
 * الأسرار **لا تغادر الخادم**: الشاشة تتلقّى المصدر وقيمةً مقنّعة، وتُرسل الجديد وحده. والكتابة بعد نافذة تأكيد
 * في الواجهة، ثمّ قيد تدقيقٍ بأسماء المفاتيح (لا قيمها) وإشعارٌ لكلّ مدير.
 */
class IntegrationSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/integrations', [
            'services' => IntegrationRegistry::services(),
            'fields' => array_map(
                fn (array $f) => array_intersect_key($f, array_flip(['service', 'label', 'env', 'secret', 'options'])),
                IntegrationRegistry::fields(),
            ),
            'states' => IntegrationSecrets::states(),
        ]);
    }

    /**
     * `changes`: قائمة `{key, value}` — الجديد وحده (الحقل الفارغ يُبقي الحاليّ). `clear`: مفاتيح تُحذف من الشاشة فتعود
     * الخدمة إلى `.env`. المفاتيح قائمةً لا خريطة: مسار الإعداد فيه نقاط يقرؤها `input()` تداخلاً.
     */
    public function update(Request $request): RedirectResponse
    {
        $fields = IntegrationRegistry::fields();
        $data = $request->validate([
            'changes' => ['array'],
            'changes.*.key' => ['required', 'string', Rule::in(array_keys($fields))],
            'changes.*.value' => ['required', 'string'],
            'clear' => ['array'],
            'clear.*' => ['string', Rule::in(array_keys($fields))],
        ]);

        $changes = [];
        foreach ((array) ($data['changes'] ?? []) as $change) {
            $changes[(string) $change['key']] = trim((string) $change['value']);
        }
        // ما يُحذف ويُعاد إدخاله في الطلب نفسه قيمةٌ جديدة لا حذف
        $clear = array_values(array_diff(array_unique(array_map('strval', (array) ($data['clear'] ?? []))), array_keys($changes)));

        // كلّ مفتاحٍ بقواعده المعلنة في السجلّ — ولا كتابةَ لأيٍّ منها إن رُفض أحدها
        $errors = [];
        foreach ($changes as $key => $value) {
            $check = Validator::make(['value' => $value], ['value' => $fields[$key]['rules']], [], ['value' => $fields[$key]['label']]);
            if ($check->fails()) {
                $errors["changes.{$key}"] = $check->errors()->first('value');
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($changes === [] && $clear === []) {
            return back()->with('flash', 'لا تغيير — لم يُدخَل مفتاحٌ جديد.');
        }

        $user = $request->user();
        foreach ($changes as $key => $value) {
            IntegrationSecrets::put($key, $value, $user);
        }
        foreach ($clear as $key) {
            IntegrationSecrets::forget($key);
        }

        $label = fn (string $key) => IntegrationRegistry::services()[$fields[$key]['service']]['label'].' ← '.$fields[$key]['label'];
        $names = implode('، ', [
            ...array_map($label, array_keys($changes)),
            ...array_map(fn (string $k) => $label($k).' (أُعيد إلى .env)', $clear),
        ]);

        // القيم لا تُقيَّد ولا تُرسل في الإشعار — أسماؤها وحدها
        Audit::log(
            action: 'تحديث مفاتيح الخدمات الخارجيّة',
            description: "حدّث {$user->name} مفاتيح: {$names}.",
            category: 'إعدادات النظام',
            severity: 'warning',
            user: $user,
        );
        foreach (User::where('role', Role::Admin)->pluck('id') as $adminId) {
            Notify::send($adminId, 'card', 't-amber', "حدّث {$user->name} مفاتيح الخدمات الخارجيّة: {$names}.");
        }

        return redirect()->route('admin.integrations')->with('flash', 'حُفظت المفاتيح وسرت على النظام.');
    }
}
