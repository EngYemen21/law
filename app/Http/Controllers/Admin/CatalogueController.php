<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LegalCatalogueAlias;
use App\Models\LegalDepartment;
use App\Models\LegalDepartmentDocument;
use App\Models\LegalService;
use App\Models\StaffDepartment;
use App\Support\LegalCatalogue;
use App\Support\LegalCatalogueEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * **شاشة «الأقسام والخدمات»** — الأقسام القانونيّة وخدماتها وقائمة مستندات كلّ قسم، والأقسام الإداريّة للموظّفين.
 *
 * المتحكّم يتحقّق من المدخلات وينقل؛ منطق الكتابة كلّه (إعادة التسمية الشاملة، والأسماء البديلة،
 * والتدقيق) في `LegalCatalogueEditor` — مصدرٌ واحد يعيد استعماله أيّ مدخلٍ آخر لاحقاً.
 * داخل مجموعة `role:admin` بلا صلاحيّةٍ مستحدثة، كشاشة الإعدادات (التعليل في routes/web.php).
 */
class CatalogueController extends Controller
{
    private const NAME_MESSAGES = [
        'name.required' => 'اكتب الاسم.',
        'name.max' => 'الاسم طويل جداً.',
    ];

    public function index(): Response
    {
        $impacts = LegalCatalogueEditor::suspensionImpacts();
        $aliases = LegalCatalogueAlias::query()->whereNull('legal_service_id')->orderBy('alias')
            ->get(['alias', 'legal_department_id'])->groupBy('legal_department_id');

        $usage = [
            'tickets' => $this->countPerDepartment('tickets'),
            'cases' => $this->countPerDepartment('cases'),
            'consults' => $this->countPerDepartment('consults'),
            'lawyers' => $this->countPerDepartment('lawyer_specialties'),
        ];

        return Inertia::render('admin/catalogue', [
            // قوائم المستندات تُحمَّل هنا دفعةً واحدة — لقطة الكتالوج لا تحمّلها لكلّ طلب
            'departments' => LegalCatalogue::departments(activeOnly: false)->loadMissing('documents')->map(fn (LegalDepartment $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'active' => $d->isActive(),
                'usage' => array_map(fn (Collection $counts) => (int) ($counts[$d->id] ?? 0), $usage),
                'impact' => $impacts[$d->id],
                'aliases' => $aliases->get($d->id, collect())->pluck('alias')->values(),
                'services' => $d->services->map(fn (LegalService $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'active' => $s->isActive(),
                ])->values(),
                // قائمة مستندات القسم كما حُرّرت — فارغةٌ ⇒ القسم على القائمة العامّة (`defaultDocuments`)
                'documents' => $d->documents->map(fn (LegalDepartmentDocument $doc) => [
                    'id' => $doc->id,
                    'name' => $doc->name,
                    'required' => $doc->required,
                ])->values(),
            ])->values(),
            'defaultDocuments' => LegalCatalogue::DEFAULT_DOCUMENTS,
            'staffDepartments' => StaffDepartment::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (StaffDepartment $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'active' => $d->status === LegalDepartment::STATUS_ACTIVE,
                    'employees' => DB::table('users')->where('department', $d->name)->where('role', '!=', 'lawyer')->count(),
                ])->values(),
        ]);
    }

    // ── الأقسام القانونيّة ─────────────────────────────────

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], self::NAME_MESSAGES);
        LegalCatalogueEditor::createDepartment($data['name'], $request->user());

        return back()->with('flash', 'أُضيف القسم.');
    }

    public function updateDepartment(Request $request, LegalDepartment $department): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], self::NAME_MESSAGES);
        LegalCatalogueEditor::renameDepartment($department, $data['name'], $request->user());

        return back()->with('flash', 'حُفظ اسم القسم، وظهر في التذاكر والقضايا والاستشارات المرتبطة به.');
    }

    /**
     * تفعيل/إيقاف. الإيقاف ذو الأثر (محامٍ تخصّصه الوحيد هذا القسم، أو تذاكر مفتوحة فيه)
     * يشترط `confirm` — الشاشة تعرض الأثر أوّلاً، والخادم لا يعتمد على ذلك وحده.
     */
    public function toggleDepartment(Request $request, LegalDepartment $department): RedirectResponse
    {
        $activate = ! $department->isActive();

        if (! $activate) {
            $impact = LegalCatalogueEditor::suspensionImpacts()[$department->id];

            if (($impact['lawyersOnlyHere'] > 0 || $impact['openTickets'] > 0) && ! $request->boolean('confirm')) {
                throw ValidationException::withMessages([
                    'confirm' => "إيقاف «{$department->name}» يُخرج {$impact['lawyersOnlyHere']} محامياً من الإسناد التلقائيّ لتذاكره الجديدة، وتبقى {$impact['openTickets']} تذكرة مفتوحة فيه. أكّد الإيقاف للمتابعة.",
                ]);
            }
        }

        LegalCatalogueEditor::setDepartmentStatus($department, $activate, $request->user());

        return back()->with('flash', $activate ? 'فُعّل القسم.' : 'أُوقف القسم — لا يظهر في الاختيار، وتبقى سجلّاته.');
    }

    public function reorderDepartments(Request $request): RedirectResponse
    {
        $data = $request->validate(['order' => ['required', 'array', 'min:1'], 'order.*' => ['integer']]);
        LegalCatalogueEditor::reorderDepartments($data['order'], $request->user());

        return back()->with('flash', 'حُفظ ترتيب الأقسام.');
    }

    // ── الخدمات ────────────────────────────────────────────

    public function storeService(Request $request, LegalDepartment $department): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160']], self::NAME_MESSAGES);
        LegalCatalogueEditor::createService($department, $data['name'], $request->user());

        return back()->with('flash', 'أُضيفت الخدمة.');
    }

    public function updateService(Request $request, LegalService $service): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160']], self::NAME_MESSAGES);
        LegalCatalogueEditor::renameService($service, $data['name'], $request->user());

        return back()->with('flash', 'حُفظ اسم الخدمة، وظهر في التذاكر المرتبطة بها.');
    }

    public function toggleService(Request $request, LegalService $service): RedirectResponse
    {
        $activate = ! $service->isActive();
        LegalCatalogueEditor::setServiceStatus($service, $activate, $request->user());

        return back()->with('flash', $activate ? 'فُعّلت الخدمة.' : 'أُوقفت الخدمة — لا تظهر في الاختيار، وتبقى سجلّاتها.');
    }

    public function reorderServices(Request $request, LegalDepartment $department): RedirectResponse
    {
        $data = $request->validate(['order' => ['required', 'array', 'min:1'], 'order.*' => ['integer']]);
        LegalCatalogueEditor::reorderServices($department, $data['order'], $request->user());

        return back()->with('flash', 'حُفظ ترتيب الخدمات.');
    }

    // ── قائمة مستندات القسم ─────────────────────────────────

    public function storeDocument(Request $request, LegalDepartment $department): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:160'], 'required' => ['required', 'boolean']], self::NAME_MESSAGES);
        LegalCatalogueEditor::createDocument($department, $data['name'], (bool) $data['required'], $request->user());

        return back()->with('flash', 'أُضيف المستند إلى قائمة القسم.');
    }

    /** إعادة التسمية و/أو تغيير الإلزام — كلٌّ يُقيَّد في التدقيق وحده. */
    public function updateDocument(Request $request, LegalDepartmentDocument $departmentDocument): RedirectResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:160'], 'required' => ['sometimes', 'boolean']], self::NAME_MESSAGES);

        if (array_key_exists('name', $data)) {
            LegalCatalogueEditor::renameDocument($departmentDocument, $data['name'], $request->user());
        }
        if (array_key_exists('required', $data)) {
            LegalCatalogueEditor::setDocumentRequired($departmentDocument, (bool) $data['required'], $request->user());
        }

        return back()->with('flash', 'حُفظ المستند.');
    }

    public function destroyDocument(Request $request, LegalDepartmentDocument $departmentDocument): RedirectResponse
    {
        LegalCatalogueEditor::deleteDocument($departmentDocument, $request->user());

        return back()->with('flash', 'حُذف المستند من قائمة القسم — تبقى مطابقات المرفقات السابقة باسمه.');
    }

    public function reorderDocuments(Request $request, LegalDepartment $department): RedirectResponse
    {
        $data = $request->validate(['order' => ['required', 'array', 'min:1'], 'order.*' => ['integer']]);
        LegalCatalogueEditor::reorderDocuments($department, $data['order'], $request->user());

        return back()->with('flash', 'حُفظ ترتيب المستندات.');
    }

    // ── الأقسام الإداريّة ───────────────────────────────────

    public function storeStaffDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], self::NAME_MESSAGES);
        LegalCatalogueEditor::createStaffDepartment($data['name'], $request->user());

        return back()->with('flash', 'أُضيف القسم الإداريّ.');
    }

    public function updateStaffDepartment(Request $request, StaffDepartment $staffDepartment): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120']], self::NAME_MESSAGES);
        LegalCatalogueEditor::renameStaffDepartment($staffDepartment, $data['name'], $request->user());

        return back()->with('flash', 'حُفظ اسم القسم الإداريّ، وظهر لدى موظّفيه.');
    }

    public function toggleStaffDepartment(Request $request, StaffDepartment $staffDepartment): RedirectResponse
    {
        $activate = $staffDepartment->status !== LegalDepartment::STATUS_ACTIVE;
        LegalCatalogueEditor::setStaffDepartmentStatus($staffDepartment, $activate, $request->user());

        return back()->with('flash', $activate ? 'فُعّل القسم الإداريّ.' : 'أُوقف القسم الإداريّ — يبقى لمن يحمله.');
    }

    /**
     * عدد صفوف جدولٍ لكلّ قسم — الجداول الأربعة كلُّها تربط بالقسم بالعمود نفسه.
     *
     * @return Collection<int|string, int>
     */
    private function countPerDepartment(string $table): Collection
    {
        return DB::table($table)->whereNotNull('legal_department_id')
            ->selectRaw('legal_department_id, count(*) as total')
            ->groupBy('legal_department_id')
            ->pluck('total', 'legal_department_id');
    }
}
