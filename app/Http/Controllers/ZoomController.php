<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Consult;
use App\Models\Meeting;
use App\Models\User;
use App\Services\ZoomService;
use App\Support\ChannelAccess;
use App\Support\ChatSenderLabel;
use App\Support\SessionWindow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * توقيع Meeting SDK لتضمين مكالمة Zoom داخل المنصّة (استشارة مرئية أو اجتماع مكتب).
 * التفويض موحّد عبر ChannelAccess (يسدّ IDOR): المالك، أو المحامي المسنَد، أو الموظف، أو الإدارة.
 * المضيف (الموظف/المحامي) ينضمّ بدور 1 مع ZAK؛ العميل مشارك (0) بلا ZAK إطلاقاً.
 */
class ZoomController extends Controller
{
    public function __construct(private readonly ZoomService $zoom) {}

    public function sdkSignature(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ref' => 'required|string',
            'kind' => 'nullable|in:consult,meeting',
        ]);

        $joinable = $this->resolveJoinable($data['kind'] ?? 'consult', $data['ref']);

        $user = $request->user();
        abort_unless(ChannelAccess::ownerOrStaff($user, $joinable), 403);
        // القناة المرئية شرط للاستشارة فقط (اجتماع المكتب مرئيّ دائماً)
        abort_if($joinable instanceof Consult && $joinable->channel !== 'مرئية', 422, SessionWindow::REFUSE_NOT_VIDEO);
        // نافذة الدخول تُفرض خادمياً هنا أيضاً — تعطيل الزر في الواجهة وحده يُلتفّ عليه بطلب مباشر.
        // **والسبب الحقيقيّ** (`joinBlocker`): كان «انتهت نافذة دخول الجلسة أو لم تُفتح بعد» يُقال
        // لسببين متعاكسين، فلا يعرف المنتظر أيعود بعد دقائق أم انتهى الأمر. والواجهة تعرض `message`.
        if (($why = $joinable->joinBlocker()) !== null) {
            abort(403, $why);
        }
        // لا اجتماع Zoom حقيقي (لم تُهيّأ مفاتيح S2S) ⇒ لا تضمين — ولا فتحٌ خارجيّ بديل: الدخول من غرفة المنصّة وحدها
        abort_unless(! empty($joinable->meet_id), 422, 'لا يوجد اجتماع Zoom مرتبط بهذه الجلسة.');
        abort_unless($this->zoom->sdkConfigured(), 503, 'تضمين Zoom غير مُهيّأ.');

        // المضيف يحتاج ZAK؛ إن تعذّر جلبه (نطاق user:read:token غير مُفعّل) يُخفَّض إلى مشارك
        // (شبكة أمان فقط كي لا يفشل الانضمام). العميل مشارك دائماً بلا ZAK.
        $isStaff = $user->role !== Role::Client;
        $zak = $isStaff ? $this->zoom->zakToken() : null;
        $role = ($isStaff && $zak !== null) ? 1 : 0;

        return response()->json([
            'sdkKey' => config('services.zoom.sdk_key'),
            'signature' => $this->zoom->sdkSignature((string) $joinable->meet_id, $role),
            'meetingNumber' => (string) $joinable->meet_id,
            'password' => $joinable->meet_password ?? '',
            'userName' => $this->displayName($user, $joinable),
            'userEmail' => $user->email,
            // يعود في أحداث الدخول/الخروج (`customer_key`): به يُعرف المحامي داخل أيّ جلسةٍ هو الآن
            // (`RoomPresence::staffInSession`)، ومن دخل بلا مفتاحٍ دخل من خارج المنصّة (`outsiders`).
            // لكلّ داخلٍ من المنصّة — عميلاً وطاقماً (`ZoomWebhookController::platformUser`).
            'customerKey' => 'u'.$user->id,
            'role' => $role,
            'zak' => $zak,
        ]);
    }

    /**
     * **الاسم الظاهر على مربّع المشارك** — والعميل في الغرفة يقرؤه.
     *
     * كان يُمرَّر `$user->name` للجميع، فيرى العميل الاسمَ الكامل للمحامي أو الموظّف على مربّعه
     * بينما محادثته تسمّيه «محمد. ب» أو «الفريق القانوني». فالطاقم في جلسةٍ يملكها عميلٌ يدخل
     * بتسميته للعميل من المصدر الواحد (`ChatSenderLabel::forAccount`)، واجتماعٌ داخليّ بلا عميل
     * يبقى بالأسماء الحقيقيّة (قرار المالك: الطاقم يرى الأسماء).
     */
    private function displayName(User $user, Model $joinable): string
    {
        $ownerId = $joinable->getAttribute('user_id');
        $clientOwned = $ownerId !== null && User::find($ownerId)?->isClient() === true;

        return $clientOwned && ! $user->isClient()
            ? ChatSenderLabel::forAccount($user)
            : (string) $user->name;
    }

    /** يحلّ الكيان القابل للانضمام بحسب النوع (قائمة بيضاء صارمة)؛ 404 إن لم يوجد. */
    private function resolveJoinable(string $kind, string $ref): Model
    {
        return match ($kind) {
            'meeting' => Meeting::where('ref', $ref)->firstOrFail(),
            default => Consult::where('ref', $ref)->firstOrFail(),
        };
    }
}
