<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Correspondence;
use App\Models\User;
use App\Support\CorrespondenceFlow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * المخاطبات الرسميّة — لوحة المكتب (المحامي بفرعه، الإدارة ترى الكلّ) + شاشة العميل.
 * دورة الحياة كلّها في App\Support\CorrespondenceFlow؛ هذا المتحكّم يحرس الدور/العزل ويعرض.
 */
class CorrespondenceController extends Controller
{
    // ── لوحة المكتب (محامي/إدارة) ──

    public function index(Request $request): Response
    {
        $user = $request->user();
        $corrs = Correspondence::with(['user', 'legalCase', 'execution'])
            ->when($user->role === Role::Lawyer, fn ($q) => $q->where('assigned_lawyer_id', $user->id))
            ->latest('id')->get()->map(fn (Correspondence $c) => $c->toCard());

        $clients = User::where('role', Role::Client)->orderBy('name')->get(['id', 'name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name]);

        return Inertia::render('correspondences', [
            'role' => $user->role->value,
            'base' => $this->base($user),
            'corrs' => $corrs,
            'clients' => $clients,
        ]);
    }

    public function show(Request $request, Correspondence $correspondence): Response
    {
        $this->guardOffice($request, $correspondence);
        $correspondence->load(['user', 'legalCase', 'execution']);

        return Inertia::render('correspondence', [
            'role' => $request->user()->role->value,
            'base' => $this->base($request->user()),
            'corr' => $correspondence->toCard(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, [Role::Lawyer, Role::Admin], true), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:users,id'],
            'entity' => ['required', 'string', 'max:160'],
            'subject' => ['required', 'string', 'max:200'],
            'direction' => ['nullable', 'in:صادرة,واردة'],
            'body' => ['nullable', 'string', 'max:8000'],
            'case_id' => ['nullable', 'integer', 'exists:cases,id'],
            'execution_id' => ['nullable', 'integer', 'exists:executions,id'],
        ]);

        $client = User::findOrFail($data['client_id']);
        $corr = CorrespondenceFlow::create($user, $client, $data);

        return redirect()->to($this->base($user).'/'.$corr->number);
    }

    public function advance(Request $request, Correspondence $correspondence): RedirectResponse
    {
        $this->guardOffice($request, $correspondence);
        // الاعتماد والإرسال للجهة (من المرحلة 2) بيد الإدارة وحدها؛ المراجعة (0،1) للمحامي/الإدارة
        if ((int) $correspondence->stage === 2) {
            abort_unless($request->user()->role === Role::Admin, 403);
        }
        CorrespondenceFlow::advance($correspondence, $this->actor($request));

        return back();
    }

    public function sync(Request $request, Correspondence $correspondence): RedirectResponse
    {
        $this->guardOffice($request, $correspondence);
        CorrespondenceFlow::sync($correspondence);

        return back();
    }

    public function receive(Request $request, Correspondence $correspondence): RedirectResponse
    {
        $this->guardOffice($request, $correspondence);
        CorrespondenceFlow::receive($correspondence, $this->actor($request));

        return back();
    }

    public function brief(Request $request, Correspondence $correspondence): RedirectResponse
    {
        $this->guardOffice($request, $correspondence);
        $note = (string) $request->validate(['note' => ['required', 'string', 'max:4000']])['note'];
        CorrespondenceFlow::brief($correspondence, $note, $this->actor($request));

        return back();
    }

    public function close(Request $request, Correspondence $correspondence): RedirectResponse
    {
        $this->guardOffice($request, $correspondence);
        abort_unless($request->user()->role === Role::Admin, 403); // الإغلاق والأرشفة بيد الإدارة
        CorrespondenceFlow::close($correspondence, 'الإدارة');

        return back();
    }

    // ── شاشة العميل «مخاطباتي» ──

    public function mine(Request $request): Response
    {
        $corrs = Correspondence::where('user_id', $request->user()->id)
            ->latest('id')->get()->map(fn (Correspondence $c) => $c->toClientCard());

        return Inertia::render('mycorr', ['corrs' => $corrs]);
    }

    public function requestBrief(Request $request, Correspondence $correspondence): RedirectResponse
    {
        abort_unless($correspondence->user_id === $request->user()->id, 403);
        CorrespondenceFlow::requestBrief($correspondence);

        return back();
    }

    // ── مساعدات ──

    private function guardOffice(Request $request, Correspondence $correspondence): void
    {
        $user = $request->user();
        if ($user->role === Role::Admin) {
            return;
        }
        abort_unless($user->role === Role::Lawyer && $correspondence->assigned_lawyer_id === $user->id, 403);
    }

    private function base($user): string
    {
        return $user->role === Role::Admin ? '/admin/correspondences' : '/lawyer/correspondences';
    }

    private function actor(Request $request): string
    {
        return $request->user()->role === Role::Admin ? 'الإدارة' : $request->user()->name;
    }
}
