<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/**
 * تحويلة تاريخية: تبويب «دعوات الاجتماعات» لدى العميل طُوي في «الاجتماعات».
 */
class MeetRequestController extends Controller
{
    /**
     * تبويب «دعوات الاجتماعات» أُلغي بقرار صاحب المنتج: العميل لا يؤكّد حضوره، والدعوة
     * تُولَد مؤكَّدة لحظة إرسال الطاقم (App\Support\MeetInvitation) فيظهر الاجتماع مباشرةً
     * في تبويب «الاجتماعات».
     *
     * المسار يبقى ويُحوّل — لا يُحذف: بريد الدعوة المُرسل سابقاً يشير إلى /meetreqs،
     * وحذفه يعطي 404 لكل من يفتح رسالة قديمة.
     *
     * الجسمان السابقان (index وconfirm) محفوظان في تاريخ git؛ ومنطق confirm الحقيقي —
     * إنشاء جلسة Zoom والاجتماع بحارس «جلسة واحدة لكل دعوة» — منقول حرفياً إلى
     * MeetInvitation::schedule ويناديه مسار الإرسال.
     */
    public function index(): RedirectResponse
    {
        return redirect()->route('meetings');
    }
}
