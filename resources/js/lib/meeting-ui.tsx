import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useConfirm } from '@/components/babylon/ConfirmDialog';
import FlowLine from '@/components/babylon/FlowLine';
import Modal from '@/components/babylon/Modal';
import RescheduleDialog from '@/components/babylon/RescheduleDialog';
import StatRow from '@/components/babylon/StatRow';
import type {StatItem} from '@/components/babylon/StatRow';
import TimeSlotPicker from '@/components/babylon/TimeSlotPicker';
import type { TimeSlotItem } from '@/components/babylon/TimeSlotPicker';
import { useToast } from '@/components/babylon/Toast';
import { todayISO } from '@/lib/local-date';
import { nowClock, todayDate } from '@/lib/chat';
import { openMeeting, RichText } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { MR_FLOW } from '@/lib/employee-data';
import { useMasker } from '@/lib/permissions';
import { useSettings } from '@/lib/settings';
import Icon from '@/lib/icons';
import { meetingMediaUrls, SessionMediaPanel, TranscriptModal } from '@/lib/recording-ui';
import type { SessionMedia } from '@/lib/recording-ui';

// ============================================================
// واجهة الاجتماعات المشتركة (Meeting/MeetRequest الحقيقيان من الخادم)
// يطابق meetReqsView + meetingView في index (82).html
// ============================================================

/**
 * نغمة شارة حالة الاجتماع — مصدر وحيد لكل اللوحات.
 * حالة الاجتماع مشتقّة لا مخزّنة (لا عمود tone في الجدول)، فمكانها الصحيح هنا لا على الخادم.
 * كانت خريطتان متناقضتان: قائمة الإدارة تلوّن «جارٍ» عنبرياً وصفحة التفاصيل أزرق،
 * و«قادم» أزرق في القائمة ورمادي في التفاصيل — لنفس الاجتماع.
 */
export function meetStatusTone(status: string): string {
    const m: Record<string, string> = {
        'قادم': 'b-blue', 'جارٍ': 'b-amber', 'منتهٍ': 'b-green', 'مؤجل': 'b-grey', 'ملغى': 'b-red', 'لم ينعقد': 'b-grey',
        'بانتظار التأكيد': 'b-amber', // كانت يتيمة بلا نغمة فتسقط رمادية صامتة
    };

    return m[status] ?? 'b-grey';
}

// مدة الحضور الفعلية من Zoom (duration_sec) — دقائق، أو null إن لم تُسجَّل بعد
/**
 * وصفُ الحضور من سجلّ Zoom — أو null إن لم يُقَس.
 *
 * كان يُعرض `حضور {m.attend || 0}%` فيُعلَن «حضور ٠٪» لاجتماعٍ لم يُسجَّل حضورُه:
 * رقمٌ يدّعي قياساً لم يقع. والعمود `attend` لم يُكتب من Zoom قطّ — يُملأ يدوياً أو يُصفَّر.
 * المصدر الآن سجلّ Zoom (attendedCount/invitedCount/presenceRate)، والمُدخَل اليدويّ
 * يُعرض موسوماً بذلك، وما لا مصدر له لا يُعرض.
 */
export function attendanceLabel(m: {
    attend: number;
    attendedCount: number | null;
    invitedCount: number | null;
    presenceRate: number | null;
}): string | null {
    if (m.attendedCount !== null) {
        const head = m.invitedCount !== null
            ? `حضر ${m.attendedCount} من ${m.invitedCount}`
            : `حضر ${m.attendedCount}`;

        return m.presenceRate !== null ? `${head} · متوسّط البقاء ${m.presenceRate}%` : head;
    }

    return m.attend ? `حضور ${m.attend}% (مُدخَل يدوياً)` : null;
}

export function fmtActualDuration(sec: number | null): string | null {
    return sec && sec > 0 ? `${Math.round(sec / 60)} د` : null;
}

// نصّ قرار آمن للعرض — القرارات نصوص عادةً، لكن بيانات قديمة قد تحمل كائن مهمّة {title,...}
// (نظير الحارس نفسه في DecisionTasks::create على الخادم) فلا يُكسَر React عند عنصر غير نصّي.
function decisionText(x: unknown): string {
    if (typeof x === 'string') {
        return x;
    }

    return (x as { title?: string })?.title ?? JSON.stringify(x);
}

/** مفاتيح `MeetingStatus::key()` — المنطق يشرط بها، والنصّ العربيّ `status` للعرض. */
export type MeetingStatusKey = 'upcoming' | 'live' | 'ended' | 'cancelled' | 'missed' | 'postponed' | 'awaiting' | 'unknown';

// بطاقة الاجتماع الكامل (Meeting::toFullCard) — تطابق FullMeeting
export interface FullMeetingCard {
    id: string;      // M-26101
    dbId: number;
    title: string;
    type: string;
    client: string;
    lawyer: string;
    when: string;
    approve: string;
    before: string[];
    during: string[];
    after: string[];
    status: string;  // قادم/جارٍ/منتهٍ/مؤجل/ملغى — للعرض وحده
    /** مفتاح الحالة الحيّة للمنطق (`MeetingStatus::key`) — لا مقارنة بالنصّ العربيّ المعروض. */
    statusKey: MeetingStatusKey;
    /** اعتمدت الإدارة المحضر والملخص؟ (`Meeting::isApproved`) */
    approved: boolean;
    /** حكم حارس الاعتماد نفسه (`Meeting::approvalBlocker`) — القالبيّ ليس مخرجاً فلا يُعرض زرٌّ يُردّ بـ٤٢٢. */
    canApprove: boolean;
    priority: string;
    conf: string;
    attend: number;
    // القياس من سجلّ Zoom — null تعني «لم يُقَس» لا صفراً
    attendedCount: number | null;
    invitedCount: number | null;
    presenceRate: number | null;
    link: string;
    meetId: string;
    meetLink: string;
    hostLink: string | null;
    // لا `dur`: الاجتماع بلا مدّةٍ ثابتة — ينتهي حين يُنهى (قرار المالك 2026-09-26)، والمقيس بعده `durationSec`
    summary: string | null;
    zoomSummary: string | null;
    /** هل للاجتماع تسجيلٌ مرئيّ؟ علَمٌ لا رابط — رابط سحابة Zoom لا يغادر الخادم. */
    recording: boolean;
    transcript: boolean;
    /** مخرجات الجلسة للتشغيل والتنزيل عبر الخادم (`RecordingArchive::availability`). */
    media: SessionMedia;
    startsAt: string | null;
    // شقّا الحالة الحيّة من الخادم: قادم أم ماضٍ، وهل نافذة الدخول مفتوحة الآن
    up: boolean;
    canJoin: boolean;
    /** يجوز نقل موعده؟ حكم الخادم: لا نهائيّ ولا منعقدٌ فعلاً (تسجيلٌ أو دخولٌ مسجَّل). */
    reschedulable: boolean;
    /** أزرار دورة الحياة بحراس الانتقالات نفسها (`StartMeeting`/`EndMeeting`/`CancelMeeting`) — لا قوائم نصّية هنا. */
    actions: { start: boolean; end: boolean; cancel: boolean };
    joinTime: string | null;
    leaveTime: string | null;
    durationSec: number | null;
    zoomSummaryAt: string | null;
    reminderSentAt: string | null;
    createdBy: string | null;
    sumApproved: boolean;
    minutes: string | null;
    participants: string | null;
    caseRef: string | null;
    decisions: string[];
    tasksCreated: boolean;
    // بيانات جلسة Zoom الإضافية (يرسلها toFullCard — كانت غائبة عن الواجهة النوعية)
    zoomUuid?: string | null;
    zoomParticipantsLog?: unknown[];
    zoomAiNextSteps?: unknown[];
}

// بطاقة دعوة الاجتماع (MeetRequest::toCard) — تطابق MeetRequest
export interface MeetReqCard {
    id: string;      // MR-1042
    dbId: number;
    client: string;
    service: string;
    type: string;
    caseRef: string | null;
    day: string;
    time: string;
    by: string;
    stage: number;   // 0..3
    /** لفحص إتاحة المحامي في مودال إعادة الإرسال */
    lawyerId?: number | null;
    meetId: string | null;
    meetLink: string | null;
    hostLink: string | null;
    meetingRef: string | null; // مرجع الاجتماع المرتبط (M-…) للغرفة المضمّنة
    canJoin?: boolean; // زر الدخول يُفعَّل قبل الموعد بـ5 دقائق (يرسله MeetRequest::toCard)
}

/** خيارُ ملفٍّ للعميل: `subject` هو موضوعه في القاعدة — null إن لم يُسجَّل. */
export interface ClientFileOption { ref: string; kind: 'ticket' | 'case' | 'consult'; label: string; subject: string | null }
export interface ClientDirEntry { id: number; name: string; items: ClientFileOption[] }

// غرفة الاجتماع لدور المكتب: انتقلت إلى الغرفة الواحدة `RoomPage` (`lib/zoom-room.tsx`) — تفاصيلها
// وزرُّ إنهائها من عقد الخادم `room`، والإنهاء داخل الغرفة لا بطاقةٌ تحتها (قرار المالك 2026-09-26).

// ============================================================
// طلبات الاجتماعات — صفحة مشتركة للموظف/المحامي/الإدارة
// يطابق meetReqsView + sendMeetInvite/mrCancel
// ============================================================

// شبكة مواعيد ضمن ساعات العمل (09:00–20:30) كل 30 دقيقة
const MI_SLOTS: string[] = (() => {
    const out: string[] = [];

    for (let m = 9 * 60; m <= 20 * 60 + 30; m += 30) {
out.push(`${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`);
}

    return out;
})();
const hmToMin = (hm: string) => {
 const [h, m] = hm.split(':').map(Number);

 return h * 60 + m; 
};

/**
 * **شبكة أوقات الاجتماع لمحامٍ في يوم، معلَّماً فيها المحجوز** — مصدرٌ واحد لنافذة الدعوة، وإعادة إرسالها،
 * وإنشاء الاجتماع (`pages/admin/meetmgmt.tsx`). الانشغال من الخادم (`meetreqs/availability` ← المحرّك
 * `LawyerAvailability`، ومعه ما مضى من اليوم)، والتعارض بمسافة الحجز نفسها التي يفحص بها الخادم.
 * كانت نسختين هنا، والثانية تعلّم `busy` لا `taken` فيعرض منتقي إعادة الإرسال المحجوزَ متاحاً.
 */
export function useLawyerDaySlots(base: string, lawyerId: number | '' | null | undefined, day: string): TimeSlotItem[] {
    const { consult_slot_minutes: spacing } = useSettings();
    const key = lawyerId && day ? `${lawyerId}|${day}` : '';
    // الانشغال مقروناً بمفتاحه — فلا يُعرض انشغالُ محامٍ أو يومٍ سابق ريثما يصل الجديد
    const [loaded, setLoaded] = useState<{ key: string; busy: [string, string][] }>({ key: '', busy: [] });

    useEffect(() => {
        if (!key) {
            return;
        }

        let alive = true;
        axios.get(`${base}/meetreqs/availability`, { params: { lawyer_id: lawyerId, day } })
            .then((r) => alive && setLoaded({ key, busy: r.data?.busy ?? [] }))
            .catch(() => alive && setLoaded({ key, busy: [] }));

        return () => {
            alive = false;
        };
    }, [base, key, lawyerId, day]);

    return useMemo(() => {
        const busy = loaded.key === key ? loaded.busy : [];

        return MI_SLOTS.map((time) => {
            const cs = hmToMin(time);
            const taken = busy.some(([a, b]) => cs < hmToMin(b) && hmToMin(a) < cs + spacing);

            return { time, taken, label: taken ? 'محجوز' : undefined };
        });
    }, [loaded, key, spacing]);
}

export const MeetReqsPage: React.FC<{ requests: MeetReqCard[]; clients: ClientDirEntry[]; lawyers: { id: number; name: string }[]; selfLawyerId?: number | null; base: string }> = ({ requests, clients, lawyers, selfLawyerId, base }) => {
    const toast = useToast();
    const ask = useConfirm();
    const mask = useMasker();
    const [open, setOpen] = useState(false);
    // إن كان المُنشئ محاميًا فهو المحامي المسؤول حصراً (لا يختار غيره)
    const selfLawyerName = selfLawyerId ? lawyers.find((l) => l.id === selfLawyerId)?.name : null;

    // حقول مودال إرسال الدعوة — بلا عميل افتراضي (اختيار صريح يمنع الإرسال الخاطئ)
    const [miClient, setMiClient] = useState<number | ''>('');
    const [miLawyer, setMiLawyer] = useState<number | ''>(selfLawyerId ?? '');
    const [miCase, setMiCase] = useState('');
    const [miService, setMiService] = useState('');
    // هل كتب المستخدم الموضوع بيده؟ التعبئة التلقائية لا تطمس كتابةً بشرية
    const [serviceTyped, setServiceTyped] = useState(false);
    const [miType, setMiType] = useState('استشارة مرئية');
    // لا «مدة» في الدعوة (قرار المالك 2026-09-26) — والتعارض بمسافة الحجز داخل `useLawyerDaySlots`
    const [miDay, setMiDay] = useState(todayISO());
    const [miTime, setMiTime] = useState('');
    useEffect(() => {
 setMiCase(''); 
}, [miClient]);

    // المواعيد وانشغال المحامي في اليوم المختار — من المصدر الواحد
    const allSlotsWithStatus = useLawyerDaySlots(base, miLawyer, miDay);

    const caseOptions = clients.find((c) => c.id === miClient)?.items ?? [];
    // المتاح: غير المحجوز (والخادم يحجب ما مضى من اليوم — `MeetRequestController::availability`)
    const availableSlots = useMemo(() => allSlotsWithStatus.filter((x) => !x.taken).map((x) => x.time), [allSlotsWithStatus]);
    // صفّر الوقت إن لم يعد متاحاً بعد تغيير المحامي/اليوم/المدة
    useEffect(() => {
 if (miTime && !availableSlots.includes(miTime)) {
setMiTime('');
} 
}, [availableSlots, miTime]);

    const submitInvite = () => {
        if (!miClient) {
 toast('اختر العميل');

 return; 
}

        if (!miLawyer) {
 toast('اختر المحامي المسؤول');

 return; 
}

        if (!miTime) {
 toast('اختر موعداً متاحاً');

 return; 
}

        const name = clients.find((c) => c.id === miClient)?.name ?? '';
        router.post(`${base}/meetreqs`, {
            client_id: miClient, lawyer_id: miLawyer, service: miService, type: miType,
            case_ref: miCase, day: miDay, time: miTime,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setMiService(''); setMiTime('');
                toast(`تم إرسال الدعوة وإشعارها إلى العميل: ${name}`);
            },
            onError: (e) => toast(e.time || e.day || e.lawyer_id || e.message || 'تعذّر إرسال الدعوة'),
        });
    };

    // onError في الاثنين: الحارس الخادميّ يردّ ٤٢٢ (دعوة غير معلّقة / ليست لك)
    // وكان الردّ يسقط صامتاً — فتُنقر الموافقة مرّتين ولا يظهر شيء.
    // **الإلغاء بعد تأكيدٍ يقول أثره** (قرار المالك 2026-09-26): إلغاء الدعوة يُلغي اجتماعها
    // ويحذف غرفته على Zoom — لا يُتراجع عنه، فلا يقع بنقرةٍ عابرة.
    const cancel = async (r: MeetReqCard) => {
        if (!(await ask({
            title: 'إلغاء الدعوة؟',
            message: 'تُلغى الدعوة ويُلغى اجتماعها وتُحذف غرفته في Zoom، ويُبلَّغ العميل. لا يمكن التراجع.',
            confirmLabel: 'إلغاء الدعوة',
            cancelLabel: 'تراجع',
            tone: 'danger',
        }))) {
            return;
        }

        router.post(`${base}/meetreqs/${r.dbId}/cancel`, {}, {
            preserveScroll: true,
            onSuccess: () => toast('أُلغيت الدعوة واجتماعها'),
            onError: (e) => toast(Object.values(e)[0] ?? 'تعذّر إلغاء الدعوة'),
        });
    };

    // موافقة الإدارة على دعوة معلّقة ونشرها للعميل (الزرّ يظهر في لوحة الإدارة فقط)
    const approveReq = (r: MeetReqCard) =>
        router.post(`${base}/meetreqs/${r.dbId}/approve`, {}, {
            preserveScroll: true,
            onSuccess: () => toast('تمت الموافقة على الدعوة ونشرها للعميل'),
            onError: (e) => toast(Object.values(e)[0] ?? 'الموافقة متاحة للدعوات المعلّقة فقط'),
        });

    // دخول الغرفة المضمّنة كمضيف ويعلّم «تنفيذ الجلسة»
    const enterRoom = (r: MeetReqCard) => {
        const go = () => {
            if (r.meetingRef) {
                router.visit(`${base}/meetingroom?ref=${encodeURIComponent(r.meetingRef)}`);
            } else if (r.type.indexOf('مرئية') >= 0) {
                openMeeting(r.hostLink || r.meetLink || '');
            } else {
                // احتياط
                toast('سيتم فتح رابط الاجتماع في موعده');
            }
        };

        if (r.stage === 1) {
            // onSuccess ثم الانتقال — كان visit يُجهض طلب البدء (سباق Inertia) فيدخل المضيف والجلسة لم تُعلَّم «جارية»
            router.post(`${base}/meetreqs/${r.dbId}/start`, {}, { preserveScroll: true, onSuccess: go, onError: (e) => toast(e.message || 'تعذّر بدء الجلسة') });

            return;
        }

        go();
    };

    // إعادة إرسال دعوة منتهية الصلاحية بموعد جديد — كانت stage 4 طريقاً مسدوداً بلا أي إجراء
    const [resendOf, setResendOf] = useState<MeetReqCard | null>(null);
    const [rsDay, setRsDay] = useState(todayISO());
    const [rsTime, setRsTime] = useState('');
    // إتاحة المحامي لإعادة الإرسال — المصدر نفسه لمودال الإرسال الأوّل (`useLawyerDaySlots`)
    const rsSlotsWithStatus = useLawyerDaySlots(base, resendOf?.lawyerId, rsDay);
    const submitResend = () => {
        if (!resendOf) {
 return; 
}

        if (!rsTime) {
 toast('اختر وقت الموعد الجديد');

 return; 
}

        router.post(`${base}/meetreqs/${resendOf.dbId}/resend`, { day: rsDay, time: rsTime }, {
            preserveScroll: true,
            onSuccess: () => {
 setResendOf(null); setRsTime(''); toast('أُعيد إرسال الدعوة بالموعد الجديد وأُشعر العميل'); 
},
            onError: (e) => toast(e.time || e.day || e.message || 'تعذّرت إعادة الإرسال'),
        });
    };

    const copyLink = (r: MeetReqCard) => {
        if (navigator.clipboard && r.meetLink) {
            void navigator.clipboard.writeText(r.meetLink);
        }

        toast('تم نسخ رابط الاجتماع');
    };

    return (
        <>
            <div className="ai-banner">
                <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
                <p>
                    يرسل الموظف/المحامي الدعوة فتمرّ بموافقة الإدارة العليا قبل نشرها للعميل. <b>المسار:</b> بانتظار موافقة الإدارة ← نشر الدعوة للعميل ← تنفيذ الجلسة ← اعتماد المحضر والملخص.
                </p>
            </div>

            <div style={{ display: 'flex', justifyContent: 'flex-end', marginBottom: 14 }}>
                <button className="btn" onClick={() => setOpen(true)} type="button">
                    <Icon name="send" /> إرسال دعوة اجتماع للعميل
                </button>
            </div>

            <div className="card">
                <div className="card-h">
                    <h3>طلبات الاجتماعات</h3>
                    <span className="sub">{requests.length} دعوة</span>
                </div>
                <div className="card-b">
                    {requests.length ? requests.map((r) => (
                        <div key={r.id} className="item">
                            <div className="iico"><Icon name="video" /></div>
                            <div className="imeta">
                                <b>{r.id} — {mask(r.client)}</b>
                                <span style={{ display: 'block', margin: '3px 0' }}>
                                    {r.type} · {r.service} · {r.day} {r.time} · أرسلها: {r.by || 'المكتب'}
                                </span>

                                {r.stage < 4 && (
                                    <span><FlowLine steps={MR_FLOW} cur={r.stage} /></span>
                                )}
                            </div>
                            <div className="iact">
                                {r.stage === 5 ? (
                                    /* أُلغيت — سجلّ تاريخي بلا أي إجراء (كان الحذف الصلب يُخفيها بلا أثر) */
                                    <Badge text="أُلغيت" tone="b-red" />
                                ) : r.stage === 4 ? (
                                    <>
                                        <Badge text="منتهية الصلاحية" tone="b-red" />
                                        <button className="btn sm" onClick={() => {
 setResendOf(r); setRsDay(todayISO()); setRsTime(''); 
}} type="button">
                                            <Icon name="send" /> إعادة إرسال بموعد جديد
                                        </button>
                                    </>
                                ) : r.stage >= 3 ? (
                                    <Badge text="معتمد" tone="b-green" />
                                ) : r.stage === 0 ? (
                                    <>
                                        <span className="chip muted">بانتظار موافقة الإدارة</span>
                                        {/* بوّابة النشر: الإدارة وحدها توافق فتُنشأ الجلسة ويُشعر العميل */}
                                        {base === '/admin' && (
                                            <button className="btn sm" onClick={() => approveReq(r)} type="button">
                                                <Icon name="check" /> موافقة ونشر
                                            </button>
                                        )}
                                        <button className="btn soft sm" onClick={() => cancel(r)} type="button">
                                            <Icon name="out" /> إلغاء
                                        </button>
                                    </>
                                ) : r.stage === 1 ? (
                                    /*
                                     * **المرحلة ١ نجاحٌ لا انتظار.** كان الشرط `stage < 2` يبتلعها،
                                     * فبعد الموافقة تُعاد البطاقة «بانتظار موافقة الإدارة» وزرُّ
                                     * «موافقة ونشر» قائم — فتُنقر ثانيةً ويردّ الخادم ٤٢٢ صامتاً.
                                     * و`MR_FLOW[1]` نفسها تقول: «معتمدة ومنشورة للعميل».
                                     */
                                    <>
                                        <Badge text={MR_FLOW[1]} tone="b-green" />
                                        {/* الإلغاء يبقى متاحاً قبل الانعقاد — والخادم يشترط stage < 2 */}
                                        <button className="btn soft sm" onClick={() => cancel(r)} type="button">
                                            <Icon name="out" /> إلغاء
                                        </button>
                                    </>
                                ) : (
                                    <span className="chip muted">{MR_FLOW[r.stage] ?? 'قيد المعالجة'}</span>
                                )}
                                {/* كان stage===1 حصراً: بدء الجلسة يرفعها لـ2 فتختفي أزرار المكتب لحظة انعقادها */}
                                {r.stage >= 1 && r.stage < 3 && r.meetLink && (
                                    r.canJoin === false ? (
                                        <button className="btn sm" type="button" disabled style={{ opacity: 0.65, cursor: 'not-allowed' }} title="يُفعَّل الدخول قبل الموعد بـ 5 دقائق">
                                            <Icon name="clock" /> الدخول (قبل الموعد بـ5 د)
                                        </button>
                                    ) : (
                                        <>
                                            <button className="btn soft sm" onClick={() => copyLink(r)} type="button">
                                                <Icon name="link" /> نسخ الرابط
                                            </button>
                                            <button className="btn sm" onClick={() => enterRoom(r)} type="button">
                                                <Icon name="video" /> {r.type.indexOf('مرئية') >= 0 ? 'دخول جلسة Zoom' : 'دخول'}
                                            </button>
                                        </>
                                    )
                                )}
                            </div>
                        </div>
                    )) : (
                        <div className="empty"><Icon name="video" /><b>لا دعوات اجتماعات حالياً</b></div>
                    )}
                </div>
            </div>

            <Modal title="إرسال دعوة اجتماع للعميل" open={open} onClose={() => setOpen(false)}>
                <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
                    تمرّ الدعوة بموافقة الإدارة العليا، وبعد الموافقة تصل العميل مؤكَّدة في «الاجتماعات» (لا تأكيد حضور مطلوباً منه).
                </p>
                <div className="form-sec-h"><span className="si"><Icon name="user" /></span> الأطراف</div>
                <div className="picker-grid">
                    <div className="field">
                        <label>العميل (من المسجّلين) <span className="req">*</span></label>
                        <select value={miClient} onChange={(e) => setMiClient(e.target.value === '' ? '' : Number(e.target.value))}>
                            <option value="">— اختر العميل —</option>
                            {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                        </select>
                    </div>
                    <div className="field">
                        <label>المحامي المسؤول <span className="req">*</span></label>
                        {selfLawyerName ? (
                            <input className="input" value={selfLawyerName} readOnly disabled title="أنت المحامي المسؤول عن دعواتك" style={{ opacity: 0.85 }} />
                        ) : (
                            <select value={miLawyer} onChange={(e) => setMiLawyer(e.target.value === '' ? '' : Number(e.target.value))}>
                                <option value="">— اختر المحامي —</option>
                                {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
                            </select>
                        )}
                    </div>
                </div>

                <div className="form-sec-h"><span className="si"><Icon name="video" /></span> تفاصيل الدعوة</div>
                <div className="picker-grid">
                    <div className="field">
                        <label>نوع الاجتماع</label>
                        <select value={miType} onChange={(e) => setMiType(e.target.value)}>
                            <option>استشارة مرئية</option>
                            <option>استشارة حضورية</option>
                            <option>استشارة هاتفية</option>
                        </select>
                    </div>
                    <div className="field">
                        <label>نهاية الاجتماع</label>
                        <div className="input" style={{ color: 'var(--muted)' }}>ينتهي حين يُنهيه المضيف — بلا مدّةٍ ثابتة</div>
                    </div>
                </div>
                <div className="picker-grid">
                    <div className="field">
                        <label>قضية / استشارة العميل</label>
                        <select value={miCase} onChange={(e) => {
                            const label = e.target.value;
                            setMiCase(label);
                            // الموضوع من عنوان الملفّ في القاعدة — وما لم يُسجَّل له موضوع لا يُملأ بشيء
                            const subject = caseOptions.find((o) => o.label === label)?.subject;

                            if (subject && !serviceTyped) {
                                setMiService(subject);
                            }
                        }}>
                            <option value="">— اختر قضية/استشارة —</option>
                            {caseOptions.map((o) => <option key={o.label} value={o.label}>{o.label}</option>)}
                        </select>
                    </div>
                    <div className="field">
                        <label>الموضوع/الخدمة</label>
                        <input
                            className="input"
                            value={miService}
                            onChange={(e) => {
                                setMiService(e.target.value);
                                setServiceTyped(true);
                            }}
                            placeholder="يُملأ من عنوان الملفّ — أو اكتبه"
                        />
                    </div>
                </div>

                <div className="form-sec-h"><span className="si"><Icon name="cal" /></span> الموعد</div>
                <div className="field">
                    <label>اليوم <span className="req">*</span></label>
                    <input className="input" type="date" min={todayISO()} value={miDay} onChange={(e) => setMiDay(e.target.value)} />
                </div>
                {!miLawyer ? (
                    <div style={{ fontSize: 12.5, color: 'var(--muted)', padding: '10px 0', display: 'flex', alignItems: 'center', gap: 6 }}>
                        <Icon name="info" /> اختر المحامي المسؤول أولاً لعرض المواعيد المتاحة
                    </div>
                ) : (
                    <TimeSlotPicker
                        value={miTime}
                        onChange={setMiTime}
                        date={miDay}
                        slots={allSlotsWithStatus}
                        label="الموعد المتاح"
                        required
                        // وقتٌ بدقّة الدقيقة خارج الفترات الجاهزة — والخادم يبقى الحكم:
                        // يرفض الماضي ويرفض التعارض مع حجوزات المحامي
                        allowCustom
                        helperText={availableSlots.length === 0 ? 'لا مواعيد متاحة لهذا المحامي في هذا اليوم — جرّب يوماً آخر أو مدّة أقصر.' : undefined}
                    />
                )}
                <div className="action-hint" style={{ margin: '12px 0' }}>
                    <Icon name="info" /> بعد موافقة الإدارة تصل الدعوة للعميل مؤكَّدة عبر إشعار داخل النظام وبريد إلكتروني.
                </div>
                <button className="btn block" onClick={submitInvite} type="button" disabled={!miClient || !miLawyer || !miTime}>
                    <Icon name="send" /> إرسال الدعوة للعميل
                </button>
            </Modal>

            <Modal title={`إعادة إرسال الدعوة ${resendOf?.id ?? ''} بموعد جديد`} open={!!resendOf} onClose={() => setResendOf(null)}>
                <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
                    انتهت صلاحية الدعوة دون موافقة الإدارة — اختر موعداً جديداً لتعود إلى «بانتظار موافقة الإدارة» قبل النشر (إعادة إرسال الإدارة تُنشر فوراً).
                </p>
                <div className="field">
                    <label>اليوم الجديد <span className="req">*</span></label>
                    <input className="input" type="date" min={todayISO()} value={rsDay} onChange={(e) => setRsDay(e.target.value)} />
                </div>
                {/* الدقيقة المخصّصة متاحة: الخادم يقبل أيّ `H:i` (`MeetRequestController::resend`) */}
                <TimeSlotPicker
                    value={rsTime}
                    onChange={setRsTime}
                    date={rsDay}
                    slots={rsSlotsWithStatus}
                    label="وقت الاجتماع الجديد"
                    required
                    allowCustom
                />
                <button className="btn block" onClick={submitResend} type="button" disabled={!rsDay || !rsTime}>
                    <Icon name="send" /> إعادة الإرسال للعميل
                </button>
            </Modal>
        </>
    );
};

// ============================================================
// تفاصيل الاجتماع — مشتركة للمحامي/الإدارة (يطابق meetingView)
// ============================================================

// عُلّقت (قرار: لا قالب وهمي) — كانت تحشو الحقول بنصّ مركَّب من قوائم افتراضية فيُحفَظ كأنه محضر
/* function defaultMinutes(m: FullMeetingCard): string {
    return `محضر اجتماع: ${m.title}\nالنوع: ${m.type}\nالتاريخ: ${m.when}\n\n` +
        `أبرز ما دار:\n- ${m.during.join('\n- ')}\n\n` +
        `القرارات والمهام:\n- ${m.after.join('\n- ')}`;
}

function defaultSummary(m: FullMeetingCard): string {
    return `ملخص اجتماع: ${m.title} — ${m.type}. أبرز ما دار: ${m.during.join(' ، ')}. ` +
        `الخلاصة والقرارات: ${m.after.join(' ، ')}.`;
} */

/**
 * **إعادة جدولة الاجتماع — بالنافذة المشتركة وسببٍ إلزاميّ.**
 *
 * كانت لوحةً مضمَّنة تنقل الموعد بضغطة بلا سبب، فيصل العميلَ «أُعيدت جدولة اجتماعك» ولا يُعرف
 * بعدها أطلبها هو أم اعتذر المحامي. الموعد الجديد (أو «أجّل بلا موعد») يُمرَّر أبناءً للنافذة
 * المشتركة، والسبب تحرسه هي — كما في الاستشارات وجلسات المحكمة.
 *
 * تُركَّب عند فتحها (`{rescheduling && …}`) فتبدأ حقولها نظيفة.
 */
const MeetingRescheduleDialog: React.FC<{ meeting: FullMeetingCard; base: string; onClose: () => void }> = ({ meeting: m, base, onClose }) => {
    const toast = useToast();
    // «أجّل بلا موعد» نيّةٌ صريحة لا حصيلةُ حقلٍ فارغ — كان التأجيل يقع بنصٍّ لا يُفكّ في حقل التاريخ
    const [postpone, setPostpone] = useState(false);
    const [day, setDay] = useState('');
    const [time, setTime] = useState('');
    // الخادم يرفض الماضي (٤٢٢)؛ التنبيه هنا كي لا يكتشفه المستخدم بعد الإرسال
    const past = !postpone && day !== '' && time !== '' && `${day} ${time}` <= `${todayISO()} ${new Date().toTimeString().slice(0, 5)}`;
    const dated = day !== '' && time !== '' && !past;

    return (
        <RescheduleDialog
            open
            domain="meeting"
            title={`إعادة جدولة الاجتماع ${m.id}`}
            consequence={postpone ? (
                <>
                    يصير الاجتماع «مؤجلاً» بلا موعد ويبقى اجتماع Zoom قائماً، ويُبلَّغ العميل والمحامي المسنَد بالسبب.
                    ويُحدَّد الموعد لاحقاً بإعادة الجدولة من هنا.
                </>
            ) : (
                <>
                    ينتقل الاجتماع إلى الموعد الجديد ويُحدَّث على Zoom، ويُعاد ضبط التذكير.
                    ويُبلَّغ العميل والمحامي المسنَد بالموعد الجديد وسببه.
                </>
            )}
            extraReady={postpone || dated}
            confirmLabel={postpone ? 'تأجيل بلا موعد' : 'نقل الموعد'}
            onClose={onClose}
            onSubmit={(choice) =>
                new Promise<void>((resolve) => {
                    router.post(`${base}/meetings/${m.dbId}/reschedule`, postpone ? { postpone: true, ...choice } : { day, time, ...choice }, {
                        preserveScroll: true,
                        onSuccess: () => {
                            toast(postpone ? 'أُجّل الاجتماع بلا موعد' : 'أُعيدت جدولة الاجتماع', 'success');
                            onClose();
                        },
                        onError: (errors) => toast(String(Object.values(errors)[0] ?? 'تعذّرت إعادة الجدولة'), 'error'),
                        onFinish: () => resolve(),
                    });
                })
            }
        >
            <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}>
                <button type="button" className={`btn sm${postpone ? ' soft' : ''}`} onClick={() => setPostpone(false)} aria-pressed={!postpone}>
                    <Icon name="cal" /> موعدٌ جديد
                </button>
                <button type="button" className={`btn sm${postpone ? '' : ' soft'}`} onClick={() => setPostpone(true)} aria-pressed={postpone}>
                    <Icon name="clock" /> أجّل بلا موعد
                </button>
            </div>
            {!postpone && (
                <div style={{ marginBottom: 12 }}>
                    <div className="field" style={{ marginBottom: 12 }}>
                        <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>التاريخ الجديد</label>
                        <input className="input" type="date" min={todayISO()} value={day} onChange={(e) => setDay(e.target.value)} style={{ borderRadius: 9 }} />
                    </div>
                    {/* الدقيقة المخصّصة متاحة: الخادم يقبل أيّ `H:i` (`MeetingController::reschedule` ⇐ `BookingMoment::rules`) */}
                    <TimeSlotPicker
                        value={time}
                        onChange={setTime}
                        date={day}
                        label="الوقت الجديد للاجتماع"
                        required
                        allowCustom
                    />
                    {past && (
                        <p style={{ margin: '8px 0 0', fontSize: 12.5, color: 'var(--red, #ef4444)' }}>
                            هذا الموعد مضى — اختر وقتاً لاحقاً.
                        </p>
                    )}
                </div>
            )}
        </RescheduleDialog>
    );
};

export const MeetingDetailPage: React.FC<{ meeting: FullMeetingCard; base: string }> = ({ meeting: m, base }) => {
    const toast = useToast();
    const ask = useConfirm();

    // حالة لحظية: تتحدّث فور بثّ الخادم (إنهاء/اعتماد + الملخص/المحضر)
    const [status, setStatus] = useState(m.status);
    // المفتاح للمنطق والنصّ للعرض — كلاهما من الخادم ويتحدّثان بالبثّ معاً
    const [statusKey, setStatusKey] = useState<MeetingStatusKey>(m.statusKey);
    // أزرار البدء/الإنهاء/الإلغاء بحكم الخادم (`Meeting::lifecycleActions`) — تتحدّث بالبثّ أيضاً
    const [actions, setActions] = useState(m.actions);
    const [approve, setApprove] = useState(m.approve);
    const [approved, setApproved] = useState(m.approved);
    // زرّ الاعتماد بحكم حارسه (`Meeting::approvalBlocker`) — «النصّ غير فارغ» كان يُظهره لقالبٍ يردّه الخادم
    const [canApprove, setCanApprove] = useState(m.canApprove);
    /**
     * الاعتماد نهائيّ: ما اعتمدته الإدارة وصل العميل بشهادتها، فتعديله بعدها يجعل
     * الشهادة تصف نصّاً لا وجود له. الخادم يردّ ٤٢٢، والواجهة لا تدعو لفعلٍ مردود.
     * وقبل الاعتماد النصّ **مسوّدة** تُحفظ وتُعدَّل بحرّية — الحفظ ليس اعتماداً.
     */
    const locked = approved || m.sumApproved;

    // لا قالب وهمي (قرار صاحب المنتج): الحقول تبدأ بمحتواها الفعلي أو فارغة —
    // التلميح في placeholder لا في القيمة، فلا يُحفَظ نصّ مركَّب لم يكتبه أحد
    const [summary, setSummary] = useState(m.summary || '');
    const [minutes, setMinutes] = useState(m.minutes || '');
    const [decisions, setDecisions] = useState<string[]>(m.decisions ?? []);
    const [tasksDone, setTasksDone] = useState(m.tasksCreated);
    // استعلام يدوي من Zoom API — يسحب كل بيانات الجلسة ويحدّثها (بديل فوري للويبهوك المتأخّر)
    const [syncing, setSyncing] = useState(false);
    const syncFromZoom = () => {
        setSyncing(true);
        router.post(`${base}/meetings/${m.dbId}/zoom-sync`, {}, {
            preserveScroll: true,
            onFinish: () => setSyncing(false),
            onSuccess: () => toast('اكتمل الاستعلام من Zoom — حُدّثت بيانات الجلسة المتوفرة'),
            onError: (e) => toast(e.message || 'تعذّر الاستعلام من Zoom — حاول بعد قليل'),
        });
    };

    // النصّ الحرفي للجلسة (VTT من Zoom): المتحدث + الوقت + الكلام — يُعرض كما ورد بلا أي تعديل
    const [transcriptOpen, setTranscriptOpen] = useState(false);
    // الجلب والتحليل في `TranscriptModal` (recording-ui) — مصدرٌ واحد للاجتماع والاستشارة
    const openTranscript = () => setTranscriptOpen(true);
    useEffect(() => {
        setSummary(m.summary || ''); setMinutes(m.minutes || '');
        setDecisions(m.decisions ?? []); setTasksDone(m.tasksCreated); setStatus(m.status); setApprove(m.approve);
        setStatusKey(m.statusKey); setApproved(m.approved); setCanApprove(m.canApprove); setActions(m.actions);
    }, [m.summary, m.minutes, m.decisions, m.tasksCreated, m.status, m.statusKey, m.approve, m.approved, m.canApprove, m.actions]);

    // بثّ لحظي لحالة الاجتماع (جارٍ→منتهٍ→معتمد + المخرجات بعد الاعتماد)
    useEffect(() => {
        const ch = echo.private(`meeting.${m.dbId}`).listen('.status', (e: {
            status: string; liveStatus?: string; statusKey?: MeetingStatusKey; approve: string; approved?: boolean; canApprove?: boolean;
            summary: string | null; minutes: string | null; actions?: FullMeetingCard['actions'];
        }) => {
            // الحالة الحيّة المشتقّة كالبطاقة — المخزّنة تتأخّر («قادم» فات يُعرض «لم ينعقد»)
            setStatus(e.liveStatus ?? e.status); setApprove(e.approve);

            if (e.statusKey) {
                setStatusKey(e.statusKey);
            }

            if (e.approved !== undefined) {
                setApproved(e.approved);
            }

            if (e.canApprove !== undefined) {
                setCanApprove(e.canApprove);
            }

            if (e.actions) {
                setActions(e.actions);
            }

            if (e.summary) {
                setSummary(e.summary);
            }

            if (e.minutes) {
                setMinutes(e.minutes);
            }
        });

        return () => {
            void ch; echo.leave(`meeting.${m.dbId}`);
        };
    }, [m.dbId]);

    // الحفظ اليدوي مصدر مخرجات مكافئ لتلخيص Zoom — والخادم يعيد البطاقة بعده فيحكم `canApprove`
    // (المحفوظ قالبيّاً لا يُفعّل الاعتماد). لا يُحفظ فراغ: الحقل الفارغ يعني «بانتظار Zoom أو التدوين».
    const saveSummary = () => {
        if (!summary.trim()) {
            toast('لا يُحفظ ملخص فارغ — دوّن نصاً فعلياً أو انتظر ملخص Zoom');

            return;
        }

        router.post(`${base}/meetings/${m.dbId}/summary`, { summary }, {
            preserveScroll: true,
            onSuccess: () => toast('تم حفظ الملخص'),
            onError: (e) => toast(Object.values(e)[0] ?? 'تعذّر حفظ الملخص'),
        });
    };
    const saveMinutes = () => {
        if (!minutes.trim()) {
            toast('لا يُحفظ محضر فارغ — دوّن نصاً فعلياً أو انتظر ملخص Zoom');

            return;
        }

        router.post(`${base}/meetings/${m.dbId}/minutes`, { minutes }, {
            preserveScroll: true,
            onSuccess: () => toast('تم حفظ المحضر'),
            onError: (e) => toast(Object.values(e)[0] ?? 'تعذّر حفظ المحضر'),
        });
    };

    const copyLink = () => {
        if (navigator.clipboard) {
            void navigator.clipboard.writeText(m.meetLink);
        }

        toast('تم نسخ رابط الاجتماع');
    };

    // تحويل قرارات الاجتماع إلى مهام حقيقية (موديل Task) — لمرة واحدة
    const decisionsToTasks = () => {
        if (tasksDone || decisions.length === 0) {
            return;
        }

        router.post(`${base}/meetings/${m.dbId}/tasks`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                setTasksDone(true); toast(`تم تحويل ${decisions.length} قرار إلى مهام`);
            },
        });
    };

    // إدارة دورة حياة الاجتماع (متزامنة مع Zoom خادميًّا): بدء/إنهاء/إعادة جدولة/إلغاء
    const [lcMode, setLcMode] = useState<'end' | null>(null);
    // نافذة إعادة الجدولة تُركَّب عند فتحها فتبدأ نظيفة (سببها وحقولها)
    const [rescheduling, setRescheduling] = useState(false);
    // يبدأ فارغاً: 90 الافتراضية كانت تُحفظ كنسبة «حقيقية» بلا إدخال من أحد
    const [endAttend, setEndAttend] = useState('');
    const [endNotes, setEndNotes] = useState('');
    const manageable = !(['ended', 'cancelled', 'missed'] as MeetingStatusKey[]).includes(statusKey);
    // «لم ينعقد» (المشتقّة لاجتماع فات موعده) يجوز إعادة جدولته — دون بدء/إنهاء/دخول
    const canReschedule = manageable || statusKey === 'missed';
    // بحكم الخادم لا بقائمة حالات: كان «إنهاء» يُعرض لـ«قادم» و«إلغاء» لـ«جارٍ» والخادم يرفضهما
    const canStart = actions.start;
    const canEnd = actions.end;

    const startMeeting = () =>
        router.post(`${base}/meetings/${m.dbId}/start`, {}, {
            preserveScroll: true,
            onSuccess: () => toast('بدأت الجلسة'),
            onError: (e) => toast(Object.values(e)[0] ?? 'تعذّر بدء الاجتماع'),
        });
    // الإنهاء والإلغاء بعد تأكيدٍ يقول أثره (قرار المالك 2026-09-26) — لا يُتراجع عنهما
    const submitEnd = async () => {
        if (!(await ask({
            title: 'إنهاء الاجتماع للجميع؟',
            message: 'يُنهى الاجتماع لكلّ الحاضرين وتُغلق غرفته في Zoom، ويُعدّ محضره. لا يمكن استئنافه بعد الإنهاء.',
            confirmLabel: 'إنهاء الاجتماع',
            cancelLabel: 'تراجع',
            tone: 'danger',
        }))) {
            return;
        }

        router.post(`${base}/meetings/${m.dbId}/end`, { attend: Number(endAttend) || 0, notes: endNotes }, {
            preserveScroll: true,
            onSuccess: () => {
                setLcMode(null); toast('أُنهي الاجتماع وأُغلقت غرفته');
            },
            onError: (e) => toast(Object.values(e)[0] ?? 'تعذّر إنهاء الاجتماع'),
        });
    };
    const cancelMeeting = async () => {
        if (!(await ask({
            title: 'إلغاء الاجتماع؟',
            message: 'يُلغى الاجتماع وتُحذف غرفته في Zoom، ويُبلَّغ العميل والمحامي. لا يمكن التراجع.',
            confirmLabel: 'إلغاء الاجتماع',
            cancelLabel: 'تراجع',
            tone: 'danger',
        }))) {
            return;
        }

        router.post(`${base}/meetings/${m.dbId}/cancel`, {}, {
            preserveScroll: true,
            onSuccess: () => toast('أُلغي الاجتماع وحُذفت غرفته'),
            onError: (e) => toast(Object.values(e)[0] ?? 'تعذّر إلغاء الاجتماع'),
        });
    };

    // اعتماد الإدارة من صفحة التفاصيل — كان الاعتماد متاحاً من قائمة /admin/meetings فقط
    const approveMeeting = () =>
        router.post(`/admin/meetings/${m.dbId}/approve`, {}, {
            preserveScroll: true,
            // الحالة الجديدة تصل بإعادة تحميل البطاقة من الخادم (والبثّ) — لا نصّ «معتمد» يُكتب هنا
            onSuccess: () => toast('اعتُمد الاجتماع — وصل المحضر والملخص للعميل'),
            onError: (e) => toast(Object.values(e)[0] ?? 'الاعتماد متاح بعد انتهاء الاجتماع ووصول ملخص Zoom أو التدوين اليدوي'),
        });

    // ألوان حالة الاجتماع — مبنية على CSS variables المنصة (--deep / --primary / --cyan / --amber / --success / --red / --muted)
    const heroGradients: Partial<Record<MeetingStatusKey, string>> = {
        upcoming:  'linear-gradient(135deg, #0A2A55 0%, #0E5C9C 55%, #11A0C8 100%)', // brand: --deep → --primary → --cyan
        live:      'linear-gradient(135deg, #5a3500 0%, #C0832B 60%, #d9a450 100%)', // brand: --amber
        ended:     'linear-gradient(135deg, #0b3320 0%, #1E9D6B 60%, #2abf85 100%)', // brand: --success
        postponed: 'linear-gradient(135deg, #1e2d3d 0%, #607689 60%, #90A2B2 100%)', // brand: --muted / --faint
        cancelled: 'linear-gradient(135deg, #3d0a0a 0%, #C0392B 60%, #d9504a 100%)', // brand: --red
        missed:    'linear-gradient(135deg, #1e2d3d 0%, #607689 60%, #90A2B2 100%)', // brand: --muted / --faint
    };
    const heroGrad = heroGradients[statusKey] ?? heroGradients.upcoming;

    return (
        <div className="detail-wrap">
            {/* ═══════════════════════════════════════
                🏛️  بانر البطل — هوية الاجتماع الكاملة
            ════════════════════════════════════════ */}
            <div style={{
                background: heroGrad,
                borderRadius: 18,
                padding: '28px 28px 24px',
                marginBottom: 20,
                color: '#fff',
                position: 'relative',
                overflow: 'hidden',
            }}>
                {/* خلفية زخرفية */}
                <div style={{
                    position: 'absolute', inset: 0,
                    background: 'url("data:image/svg+xml,%3Csvg width=\'60\' height=\'60\' viewBox=\'0 0 60 60\' fill=\'none\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Ccircle cx=\'30\' cy=\'30\' r=\'28\' stroke=\'white\' stroke-opacity=\'0.04\' stroke-width=\'2\'/%3E%3C/svg\'")',
                    backgroundSize: '80px 80px',
                    opacity: 0.6,
                    pointerEvents: 'none',
                }} />

                {/* رجوع */}
                <div style={{ marginBottom: 18 }}>
                    <Link
                        href={`${base}/meetings`}
                        style={{
                            display: 'inline-flex', alignItems: 'center', gap: 6,
                            background: 'rgba(255,255,255,0.15)', border: '1px solid rgba(255,255,255,0.25)',
                            borderRadius: 8, padding: '5px 12px', fontSize: '12.5px',
                            color: '#fff', fontWeight: 600, backdropFilter: 'blur(4px)',
                            transition: 'background 0.2s',
                        }}
                    >
                        <Icon name="reply" /> العودة للاجتماعات
                    </Link>
                </div>

                {/* عنوان الاجتماع + شارات الحالة */}
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 16, flexWrap: 'wrap' }}>
                    <div style={{ flex: 1, minWidth: 0 }}>
                        {/* مرجع الاجتماع */}
                        <div style={{ fontSize: '11.5px', fontWeight: 700, opacity: 0.7, letterSpacing: 1, marginBottom: 6 }}>
                            {m.id} · {m.type}
                        </div>
                        <h2 style={{ fontSize: '22px', fontWeight: 800, lineHeight: 1.3, marginBottom: 10, color: '#fff' }}>
                            {m.title}
                        </h2>
                        {/* معلومات سريعة */}
                        <div style={{ display: 'flex', gap: 16, flexWrap: 'wrap', fontSize: '13px', opacity: 0.9 }}>
                            <span style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                                <Icon name="user" /> {m.client}
                            </span>
                            <span style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                                <Icon name="clock" /> {m.when}
                            </span>
                            {/* لا «المدة» سلفاً — والمقيسة بعد الإنهاء في «مدة الحضور الفعلية» أدناه */}
                            {m.caseRef && (
                                <span style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                                    <Icon name="folder" /> {m.caseRef}
                                </span>
                            )}
                        </div>
                    </div>

                    {/* شارات الحالة والاعتماد */}
                    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end', gap: 10, flexShrink: 0 }}>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', justifyContent: 'flex-end' }}>
                            <Badge text={status} tone={meetStatusTone(status)} />
                            <Badge text={approve} tone={approved ? 'b-green' : 'b-amber'} />
                            {m.conf === 'سري' && (
                                <span style={{
                                    display: 'inline-flex', alignItems: 'center', gap: 4,
                                    background: 'rgba(192,57,43,0.85)', borderRadius: 8,
                                    padding: '3px 10px', fontSize: '11.5px', fontWeight: 700,
                                }}>
                                    <Icon name="lock" /> سري
                                </span>
                            )}
                        </div>

                        {/* زر الاعتماد (إدارة فقط) — بعد انتهاء الجلسة وتوليد مخرجاتها فقط:
                            اعتماد اجتماع لم ينعقد أو بلا ملخص/محضر كان يوسمه «معتمداً» بلا شيء يُعرض */}
                        {base === '/admin' && canApprove && (
                            <button
                                type="button"
                                onClick={approveMeeting}
                                style={{
                                    display: 'inline-flex', alignItems: 'center', gap: 6,
                                    background: '#fff', color: '#0E5C9C',
                                    border: 'none', borderRadius: 10,
                                    padding: '8px 18px', fontSize: '13px', fontWeight: 800,
                                    cursor: 'pointer',
                                    boxShadow: '0 4px 14px rgba(0,0,0,0.15)',
                                    transition: 'transform 0.15s, box-shadow 0.15s',
                                }}
                            >
                                <Icon name="check" /> اعتماد المحضر والملخص
                            </button>
                        )}
                    </div>
                </div>

                {/* شريط الإجراءات السريعة */}
                {(m.meetLink && manageable) && (
                    <div style={{
                        marginTop: 20, paddingTop: 16,
                        borderTop: '1px solid rgba(255,255,255,0.2)',
                        display: 'flex', gap: 10, flexWrap: 'wrap',
                    }}>
                        <button
                            type="button"
                            onClick={copyLink}
                            style={{
                                display: 'inline-flex', alignItems: 'center', gap: 6,
                                background: 'rgba(255,255,255,0.15)', border: '1px solid rgba(255,255,255,0.3)',
                                borderRadius: 9, padding: '7px 14px', fontSize: '12.5px',
                                color: '#fff', fontWeight: 700, cursor: 'pointer',
                                backdropFilter: 'blur(4px)',
                            }}
                        >
                            <Icon name="link" /> نسخ رابط Zoom
                        </button>
                        <button
                            type="button"
                            onClick={() => router.visit(`${base}/meetingroom?ref=${encodeURIComponent(m.id)}`)}
                            style={{
                                display: 'inline-flex', alignItems: 'center', gap: 6,
                                background: '#fff', border: 'none',
                                borderRadius: 9, padding: '7px 16px', fontSize: '12.5px',
                                color: '#0E5C9C', fontWeight: 800, cursor: 'pointer',
                                boxShadow: '0 3px 10px rgba(0,0,0,0.12)',
                            }}
                        >
                            <Icon name="video" /> دخول اجتماع Zoom
                        </button>
                    </div>
                )}
            </div>

            {/* ═══════════════════════════════════════
                🎮  إدارة دورة حياة الاجتماع
            ════════════════════════════════════════ */}
            {canReschedule && (
                <div className="card" style={{ marginBottom: 18 }}>
                    <div className="card-h">
                        <h3 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <span style={{
                                width: 28, height: 28, borderRadius: 8,
                                background: 'var(--brand)', color: '#fff',
                                display: 'grid', placeItems: 'center', flexShrink: 0,
                            }}><Icon name="cal" /></span>
                            إدارة الجلسة
                        </h3>
                    </div>
                    <div className="card-b" style={{ padding: '16px 18px' }}>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            {canStart && (
                                <button
                                    className="btn sm" type="button" onClick={startMeeting}
                                    style={{ display: 'flex', alignItems: 'center', gap: 6 }}
                                >
                                    <Icon name="video" /> بدء الجلسة
                                </button>
                            )}
                            {canEnd && (
                                <button
                                    className="btn soft sm" type="button"
                                    onClick={() => setLcMode(lcMode === 'end' ? null : 'end')}
                                    style={{ display: 'flex', alignItems: 'center', gap: 6 }}
                                >
                                    <Icon name="check" /> إنهاء الاجتماع
                                </button>
                            )}
                            {m.reschedulable && (
                                <button
                                    className="btn soft sm" type="button"
                                    onClick={() => setRescheduling(true)}
                                    style={{ display: 'flex', alignItems: 'center', gap: 6 }}
                                >
                                    <Icon name="cal" /> إعادة جدولة
                                </button>
                            )}
                            {/* الجاري لا يُلغى — يُنهى أوّلاً (`CancelMeeting::guard`)؛ فالزرّ بحكم الخادم */}
                            {actions.cancel && (
                                <button
                                    className="btn soft sm" type="button" onClick={cancelMeeting}
                                    style={{ display: 'flex', alignItems: 'center', gap: 6, color: 'var(--red)' }}
                                >
                                    <Icon name="info" /> إلغاء الاجتماع
                                </button>
                            )}
                        </div>

                        {rescheduling && (
                            <MeetingRescheduleDialog
                                meeting={m}
                                base={base}
                                onClose={() => setRescheduling(false)}
                            />
                        )}

                        {lcMode === 'end' && (
                            <div style={{
                                marginTop: 16, padding: 16,
                                background: 'var(--surface-soft, #f8fafc)',
                                border: '1px solid var(--line-soft, #e2e8f0)',
                                borderRadius: 12,
                            }}>
                                <div style={{ fontSize: '13px', fontWeight: 700, color: 'var(--deep)', marginBottom: 12 }}>
                                    ✅ تفاصيل إنهاء الجلسة
                                </div>
                                <div className="picker-grid">
                                    <div className="field">
                                        <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>نسبة الحضور %</label>
                                        <input className="input" type="number" min={0} max={100} value={endAttend} onChange={(e) => setEndAttend(e.target.value)} style={{ borderRadius: 9 }} />
                                    </div>
                                </div>
                                <div className="field" style={{ marginTop: 10 }}>
                                    <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>ملاحظات/نقاط الجلسة (تُغذّي الملخّص)</label>
                                    <textarea value={endNotes} onChange={(e) => setEndNotes(e.target.value)} style={{ minHeight: 80, borderRadius: 9 }} />
                                </div>
                                <div style={{ display: 'flex', gap: 8, marginTop: 12 }}>
                                    <button className="btn sm" type="button" onClick={submitEnd}>
                                        <Icon name="check" /> تأكيد الإنهاء
                                    </button>
                                    <button className="btn soft sm" type="button" onClick={() => setLcMode(null)}>إلغاء</button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* ═══════════════════════════════════════
                📡  بيانات جلسة Zoom الفعلية
                (كانت «إدارة فقط» بينما مساراتها الخمسة مسجّلة للموظف والمحامي أيضاً)
            ════════════════════════════════════════ */}
            {(
                <div className="card" style={{ marginBottom: 18 }}>
                    <div className="card-h">
                        <h3 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                            <span style={{
                                width: 28, height: 28, borderRadius: 8,
                                background: 'var(--cyan)', color: '#fff',
                                display: 'grid', placeItems: 'center', flexShrink: 0,
                            }}><Icon name="video" /></span>
                            بيانات جلسة Zoom
                        </h3>
                        <span className="sub">بيانات فعلية من الويبهوك</span>
                    </div>
                    <div className="card-b" style={{ padding: '0 0 4px' }}>
                        {/* شبكة مؤشرات Zoom */}
                        <div style={{
                            display: 'grid',
                            gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                            gap: 1,
                            background: 'var(--line-soft, #e2e8f0)',
                            borderRadius: '0 0 14px 14px',
                            overflow: 'hidden',
                        }}>
                            {[
                                { label: 'دخول أول مشارك', value: m.joinTime, ltr: true },
                                { label: 'آخر مغادرة', value: m.leaveTime, ltr: true },
                                { label: 'مدة الحضور الفعلية', value: fmtActualDuration(m.durationSec) },
                                // الحضور من سجلّ Zoom — وما لم يُقَس لا يُعرض. الصفر كان يُقرأ
                                // «لم يحضر أحد» والحقيقة «لم يُقَس».
                                {
                                    label: 'الحضور',
                                    value: m.attendedCount !== null
                                        ? `${m.attendedCount}${m.invitedCount !== null ? ` من ${m.invitedCount}` : ''}`
                                        : (statusKey === 'ended' && m.attend ? `${m.attend}% (مُدخَل يدوياً)` : null),
                                },
                                { label: 'متوسّط البقاء', value: m.presenceRate !== null ? `${m.presenceRate}%` : null },
                            ].filter(r => r.value).map((row, i) => (
                                <div key={i} style={{
                                    background: 'var(--paper)',
                                    padding: '12px 16px',
                                    display: 'flex', flexDirection: 'column', gap: 3,
                                }}>
                                    <span style={{ fontSize: '11.5px', color: 'var(--muted)', fontWeight: 600 }}>{row.label}</span>
                                    <span style={{ fontSize: '14px', fontWeight: 700, color: 'var(--deep)', direction: row.ltr ? 'ltr' : 'inherit' }}>
                                        {row.value}
                                    </span>
                                </div>
                            ))}
                        </div>

                        {/* مخرجات الجلسة عبر الخادم — تشغيلٌ وتنزيلٌ داخل النظام، لا نافذةَ سحابة Zoom */}
                        <div style={{ padding: '12px 16px', display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                            <SessionMediaPanel media={m.media} urls={meetingMediaUrls(base, m.dbId)} />
                            {/* النصّ يُجلب من مسار التسجيلات نفسه — يُخفى عمّن لا يملك «تشغيل تسجيلات الجلسات» */}
                            {(m.transcript || statusKey === 'ended') && !m.media?.locked && (
                                <button type="button" onClick={openTranscript}
                                    style={{
                                        display: 'inline-flex', alignItems: 'center', gap: 5,
                                        padding: '7px 14px', borderRadius: 9,
                                        border: '1px solid var(--line-soft)',
                                        background: 'var(--paper-2)',
                                        fontSize: '12px', fontWeight: 600, cursor: 'pointer',
                                    }}>
                                    🗣️ النص الحرفي للجلسة (المتحدث والوقت)
                                </button>
                            )}
                            {/* المزامنة تُغيّر القرارات والمشاركين، وهما ممّا يشمله الاعتماد — فتُمنع بعده */}
                            {statusKey === 'ended' && !locked && (
                                <button type="button" onClick={syncFromZoom} disabled={syncing}
                                    style={{
                                        display: 'inline-flex', alignItems: 'center', gap: 5,
                                        padding: '7px 14px', borderRadius: 9,
                                        border: '1px solid rgba(14,92,156,0.35)',
                                        background: 'rgba(14,92,156,0.06)', color: 'var(--primary)',
                                        fontSize: '12px', fontWeight: 700,
                                        cursor: syncing ? 'wait' : 'pointer', opacity: syncing ? 0.6 : 1,
                                    }}>
                                    🔄 {syncing ? 'جارٍ الاستعلام من Zoom…' : 'تحديث بيانات الجلسة من Zoom'}
                                </button>
                            )}
                        </div>

                        {/* ملخّص Zoom AI */}
                        {m.zoomSummary && (
                            <div style={{
                                margin: '0 16px 12px',
                                padding: 14,
                                background: 'linear-gradient(135deg, rgba(10,42,85,0.04) 0%, rgba(14,92,156,0.08) 100%)',
                                border: '1px solid rgba(14,92,156,0.15)',
                                borderRadius: 10,
                            }}>
                                <div style={{ fontSize: '12.5px', fontWeight: 700, color: 'var(--deep)', marginBottom: 6 }}>
                                    🤖 ملخّص Zoom AI {m.zoomSummaryAt && <span style={{ fontWeight: 400, color: 'var(--muted)' }}>— {m.zoomSummaryAt}</span>}
                                </div>
                                {/* نصّ نموذجٍ توليديّ: يأتي بنجوم Markdown — RichText يصيّرها بلا حقن HTML */}
                                <div style={{ color: 'var(--muted)', fontSize: '12.5px', lineHeight: 1.7 }}>
                                    <RichText text={m.zoomSummary} />
                                </div>
                            </div>
                        )}

                        {!m.joinTime && !m.recording && !m.transcript && statusKey !== 'ended' && (
                            <div className="action-hint" style={{ margin: '8px 16px' }}>
                                تظهر بيانات الجلسة الفعلية (الدخول/المدة/التسجيل/النص) تلقائيًّا بعد انعقاد الجلسة عبر ويبهوك Zoom.
                            </div>
                        )}
                    </div>
                </div>
            )}


            {/* ═══════════════════════════════════════
                📄  ملخص الاجتماع
            ════════════════════════════════════════ */}
            <div style={{
                background: 'var(--paper)',
                border: '1px solid var(--line-soft, #e2e8f0)',
                borderRadius: 14,
                overflow: 'hidden',
                marginBottom: 16,
                boxShadow: 'var(--shadow)',
            }}>
                <div style={{
                    padding: '12px 18px',
                    background: 'var(--paper-2)',
                    borderBottom: '1px solid var(--line-soft)',
                    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                    flexWrap: 'wrap', gap: 8,
                }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{
                            width: 28, height: 28, borderRadius: 7,
                            background: 'var(--brand)', color: '#fff',
                            display: 'grid', placeItems: 'center',
                        }}><Icon name="doc" /></span>
                        <b style={{ fontSize: '14px', color: 'var(--deep)' }}>ملخص الاجتماع</b>
                        <span style={{
                            fontSize: '11px', fontWeight: 700, padding: '2px 8px',
                            borderRadius: 6,
                            background: locked ? 'var(--success-bg)' : 'var(--amber-bg)',
                            color: locked ? 'var(--success)' : 'var(--amber)',
                        }}>
                            {locked ? '✓ معتمد' : 'مسودة'}
                        </span>
                    </div>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                        {locked && <Badge text="الملخص معتمد ومُرسل للعميل" tone="b-green" />}
                        {locked ? (
                            <span style={{ fontSize: '11.5px', color: 'var(--muted)' }}>
                                اعتمدت الإدارة هذا النصّ ووصل العميل — لا يُعدَّل
                            </span>
                        ) : (
                            <button
                                className="btn soft sm" onClick={saveSummary} type="button"
                                style={{ fontSize: '12px' }}
                            >
                                حفظ المسودّة
                            </button>
                        )}
                    </div>
                </div>
                <div style={{ padding: '14px 18px' }}>
                    <textarea
                        value={summary}
                        readOnly={locked}
                        placeholder="بانتظار ملخص الجلسة من Zoom — أو دوّن الملخص يدوياً هنا"
                        onChange={(e) => setSummary(e.target.value)}
                        style={{
                            width: '100%', minHeight: 120,
                            opacity: locked ? 0.75 : 1,
                            cursor: locked ? 'not-allowed' : 'auto',
                            border: '1px solid var(--line-soft)',
                            borderRadius: 10, padding: '10px 14px',
                            fontSize: '13.5px', lineHeight: 1.7,
                            background: 'var(--paper-2)',
                            color: 'var(--ink)',
                            resize: 'vertical',
                        }}
                    />
                </div>
            </div>

            {/* ═══════════════════════════════════════
                📋  محضر الاجتماع
            ════════════════════════════════════════ */}
            <div style={{
                background: 'var(--paper)',
                border: '1px solid var(--line-soft, #e2e8f0)',
                borderRadius: 14,
                overflow: 'hidden',
                marginBottom: 18,
                boxShadow: 'var(--shadow)',
            }}>
                <div style={{
                    padding: '12px 18px',
                    background: 'var(--paper-2)',
                    borderBottom: '1px solid var(--line-soft)',
                    display: 'flex', alignItems: 'center', justifyContent: 'space-between',
                    flexWrap: 'wrap', gap: 8,
                }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{
                            width: 28, height: 28, borderRadius: 7,
                            background: 'var(--brand)', color: '#fff',
                            display: 'grid', placeItems: 'center',
                        }}><Icon name="doc" /></span>
                        <b style={{ fontSize: '14px', color: 'var(--deep)' }}>محضر الاجتماع</b>
                        <span style={{
                            fontSize: '11px', fontWeight: 700, padding: '2px 8px',
                            borderRadius: 6,
                            background: 'var(--paper-2)',
                            border: '1px solid var(--line-soft)',
                            color: 'var(--muted)',
                        }}>
                            {m.id}
                        </span>
                        {/* الاعتماد يشمل المحضر كما يشمل الملخّص — وكان بلا وسم فيُظنّ مسوّدةً دائمة */}
                        <span style={{
                            fontSize: '11px', fontWeight: 700, padding: '2px 8px',
                            borderRadius: 6,
                            background: locked ? 'var(--success-bg)' : 'var(--amber-bg)',
                            color: locked ? 'var(--success)' : 'var(--amber)',
                        }}>
                            {locked ? '✓ معتمد' : 'مسودة'}
                        </span>
                    </div>
                        {locked ? (
                            <span style={{ fontSize: '11.5px', color: 'var(--muted)' }}>
                                اعتمدت الإدارة هذا النصّ ووصل العميل — لا يُعدَّل
                            </span>
                        ) : (
                        <button
                            className="btn soft sm" onClick={saveMinutes} type="button"
                            style={{ fontSize: '12px' }}
                        >
                            حفظ المسودّة
                        </button>
                    )}
                </div>
                <div style={{ padding: '14px 18px' }}>
                    <textarea
                        value={minutes}
                        readOnly={locked}
                        placeholder="بانتظار ملخص الجلسة من Zoom — أو دوّن أبرز ما دار والقرارات يدوياً هنا"
                        onChange={(e) => setMinutes(e.target.value)}
                        style={{
                            width: '100%', minHeight: 160,
                            opacity: locked ? 0.75 : 1,
                            cursor: locked ? 'not-allowed' : 'auto',
                            border: '1px solid var(--line-soft)',
                            borderRadius: 10, padding: '10px 14px',
                            fontSize: '13.5px', lineHeight: 1.7,
                            background: 'var(--paper-2)',
                            color: 'var(--ink)',
                            resize: 'vertical',
                        }}
                    />
                </div>
            </div>

            {/* ═══════════════════════════════════════
                ⚡  القرارات والمهام
            ════════════════════════════════════════ */}
            <div className="card" style={{ marginBottom: 18 }}>
                <div className="card-h">
                    <h3 style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{
                            width: 28, height: 28, borderRadius: 8,
                            background: 'var(--success)', color: '#fff',
                            display: 'grid', placeItems: 'center', flexShrink: 0,
                        }}><Icon name="check" /></span>
                        القرارات والمهام
                    </h3>
                    <button
                        className="btn soft sm" onClick={decisionsToTasks} type="button"
                        disabled={tasksDone || decisions.length === 0}
                        style={{ display: 'flex', alignItems: 'center', gap: 6 }}
                    >
                        <Icon name="check" /> {tasksDone ? '✓ حُوّلت إلى مهام' : 'تحويل القرارات إلى مهام'}
                    </button>
                </div>
                <div className="card-b" style={{ padding: '14px 18px' }}>
                    {decisions.length ? (
                        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                            {decisions.map((x, i) => (
                                <div key={i} style={{
                                    display: 'flex', alignItems: 'flex-start', gap: 10,
                                    padding: '10px 14px',
                                    background: 'var(--paper-2)',
                                    border: '1px solid var(--line-soft)',
                                    borderRadius: 10,
                                    fontSize: '13.5px',
                                }}>
                                    <span style={{
                                        width: 22, height: 22, borderRadius: 6,
                                        background: 'var(--success-bg)',
                                        color: 'var(--success)',
                                        display: 'grid', placeItems: 'center',
                                        fontWeight: 800, fontSize: '11px', flexShrink: 0,
                                    }}>{i + 1}</span>
                                    <span style={{ lineHeight: 1.6 }}>{decisionText(x)}</span>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="empty" style={{ padding: '20px 0' }}>
                            <Icon name="check" /><b>تُستخرج القرارات تلقائياً بعد إنهاء الاجتماع</b>
                        </div>
                    )}
                </div>
            </div>

            {/* ═══════════════════════════════════════
                🔒  حماية الاجتماع والتوثيق
            ════════════════════════════════════════ */}
            <div style={{
                padding: '14px 18px',
                borderRadius: 12,
                background: 'linear-gradient(135deg, rgba(10,42,85,0.03) 0%, rgba(14,92,156,0.06) 100%)',
                border: '1px solid rgba(14,92,156,0.12)',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'space-between',
                flexWrap: 'wrap',
                gap: 12,
                marginBottom: 8,
            }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <div style={{
                        width: 32, height: 32, borderRadius: 8,
                        background: 'var(--brand)', color: '#fff',
                        display: 'grid', placeItems: 'center', flexShrink: 0,
                    }}><Icon name="lock" /></div>
                    <div>
                        <div style={{ fontSize: '12.5px', fontWeight: 700, color: 'var(--deep)', marginBottom: 4 }}>حماية الاجتماع والتوثيق</div>
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                            {['منع التحميل', 'منع النسخ', 'منع الطباعة', 'منع المشاركة', 'علامة مائية ديناميكية'].map((chip) => (
                                <span key={chip} style={{
                                    fontSize: '11px', fontWeight: 600, padding: '2px 8px',
                                    borderRadius: 6, background: 'rgba(14,92,156,0.08)',
                                    color: 'var(--primary)', border: '1px solid rgba(14,92,156,0.15)',
                                }}>{chip}</span>
                            ))}
                        </div>
                    </div>
                </div>
                <div style={{ fontSize: '11px', color: 'var(--muted)', fontFamily: 'monospace', direction: 'ltr', textAlign: 'left' }}>
                    Audit Log · {m.client} · {m.id} · {todayDate()} {nowClock()}
                </div>
            </div>

            {/* النصّ الحرفي الراجع من Zoom — يُعرض كما ورد حرفياً: المتحدث والوقت والكلام، بلا أي تعديل */}
            <TranscriptModal
                title={`النص الحرفي للجلسة — ${m.id}`}
                url={meetingMediaUrls(base, m.dbId).transcript}
                open={transcriptOpen}
                onClose={() => setTranscriptOpen(false)}
            />
        </div>
    );
};

// ============================================================
// قائمة اجتماعات المكتب — مشتركة للمحامي والموظف (يطابق lwMeetings)
// ============================================================

/**
 * قائمة اجتماعات المكتب — مشتركة بين لوحتَي الموظّف والمحامي.
 *
 * كانت قائمةً مسطّحة بترتيب الإنشاء (`cards()` يفرز `latest('id')`)، فاجتماعُ الأسبوع
 * القادم يقع أسفل اجتماعِ الشهر الماضي، ولا فصلَ بين قادمةٍ ومنتهية. والبطاقة تحمل
 * الملخّص والمحضر والقرارات والنصّ الحرفيّ ولا تعرض منها شيئاً — والموظّف **هو من
 * يُعدّها**، فلا يعرف أيَّها ناقصٌ إلا بفتح كلّ اجتماعٍ على حدة.
 *
 * الفرزُ هنا لا في SQL لأنّه باتّجاهين: القادمةُ تصاعديّاً والماضيةُ تنازليّاً.
 * و`up`/`canJoin` يأتيان من الخادم (`Meeting::liveState()`/`canJoin()`) فلا تُعاد
 * كتابةُ القاعدتين في JS.
 */
type MeetTab = 'up' | 'past' | 'needsOutput' | 'needsApproval' | 'missed';

// ينتظر عملَ الموظّف: انعقد ولم يكتمل توثيقه
const needsOutput = (m: FullMeetingCard) => m.statusKey === 'ended' && (!m.summary || !m.minutes);
// اكتمل توثيقه وينتظر شهادة الإدارة — وبالاعتماد يصل الموكّل
// حكم حارس الاعتماد نفسه (`canApprove`) — «النصّ غير فارغ» كان يعدّ القالبيّ مخرجاً
const needsApproval = (m: FullMeetingCard) => m.canApprove;

export const MeetingsListPage: React.FC<{ meetings: FullMeetingCard[]; base: string }> = ({ meetings, base }) => {
    const openPage = (id: string) => router.visit(`${base}/meeting?id=${encodeURIComponent(id)}`);
    // يُفتح على «قادمة»، وإن لم يكن ثمّة قادمٌ فعلى «المنتهية» — لا شاشةٍ فارغةٍ
    // والقائمةُ مليئة. (تهيئةٌ كسولة لا تأثيرٌ جانبيّ: بلا إعادة تصيير.)
    const [tab, setTab] = useState<MeetTab>(() => (meetings.some((m) => m.up) ? 'up' : 'past'));
    const [q, setQ] = useState('');

    const counts = useMemo(() => {
        const today = new Date().toDateString();

        return {
            today: meetings.filter((m) => !!m.startsAt && new Date(m.startsAt).toDateString() === today).length,
            up: meetings.filter((m) => m.up).length,
            needsOutput: meetings.filter(needsOutput).length,
            needsApproval: meetings.filter(needsApproval).length,
            missed: meetings.filter((m) => m.statusKey === 'missed').length,
            past: meetings.filter((m) => !m.up).length,
        };
    }, [meetings]);

    // الصفر هنا قياسٌ صادق (لا اجتماعَ ينتظر محضراً) لا ادّعاءَ قياسٍ لم يقع
    const stats: StatItem[] = [
        ['t-cyan', 'clock', counts.today, 'اجتماعات اليوم'],
        ['t-blue', 'video', counts.up, 'قادمة'],
        ['t-amber', 'doc', counts.needsOutput, 'تنتظر محضراً أو ملخّصاً'],
        ['t-green', 'check', counts.needsApproval, 'تنتظر اعتماد الإدارة'],
        ['t-grey', 'alert', counts.missed, 'لم تنعقد'],
    ];
    const STAT_TABS: MeetTab[] = ['up', 'up', 'needsOutput', 'needsApproval', 'missed'];

    const shown = useMemo(() => {
        const bucket = meetings.filter((m) => {
            if (tab === 'up') {
                return m.up;
            }

            if (tab === 'past') {
                return !m.up;
            }

            if (tab === 'needsOutput') {
                return needsOutput(m);
            }

            if (tab === 'needsApproval') {
                return needsApproval(m);
            }

            return m.statusKey === 'missed';
        });

        const needle = q.trim().toLowerCase();
        const matched = needle === '' ? bucket : bucket.filter((m) =>
            [m.title, m.client, m.lawyer, m.id, m.caseRef ?? '', m.type]
                .some((f) => String(f).toLowerCase().includes(needle)));

        // القادمةُ الأقربُ أوّلاً، والماضيةُ الأحدثُ أوّلاً، وما لا موعدَ له في الذيل
        const asc = tab === 'up';

        return [...matched].sort((a, b) => {
            if (!a.startsAt && !b.startsAt) {
                return 0;
            }

            if (!a.startsAt) {
                return 1;
            }

            if (!b.startsAt) {
                return -1;
            }

            const d = new Date(a.startsAt).getTime() - new Date(b.startsAt).getTime();

            return asc ? d : -d;
        });
    }, [meetings, tab, q]);

    const TABS: [MeetTab, string, string, number][] = [
        ['up', 'video', 'قادمة', counts.up],
        ['needsOutput', 'doc', 'تنتظر توثيقاً', counts.needsOutput],
        ['needsApproval', 'check', 'تنتظر الاعتماد', counts.needsApproval],
        ['missed', 'alert', 'لم تنعقد', counts.missed],
        ['past', 'folder', 'المنتهية والسابقة', counts.past],
    ];

    return (
        <>
            <div className="ai-banner">
                <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
                <p>تابع جدول اجتماعات المكتب، وأعِدّ محاضرها وملخّصاتها، واعرضها على الإدارة لاعتمادها — وبالاعتماد تصل الموكّل.</p>
            </div>

            <StatRow items={stats} onSelect={(i) => setTab(STAT_TABS[i])} />

            <div className="card" style={{ marginBottom: 18 }}>
                <div className="card-b" style={{ padding: '14px 18px', display: 'flex', flexDirection: 'column', gap: 12 }}>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', borderBottom: '1px solid var(--line-soft)', paddingBottom: 12 }}>
                        {TABS.map(([key, icon, label, n]) => (
                            <button
                                key={key}
                                type="button"
                                className={`btn sm ${tab === key ? '' : 'soft'}`}
                                style={{ boxShadow: tab === key ? undefined : 'none' }}
                                onClick={() => setTab(key)}
                            >
                                <Icon name={icon} /> {label} ({n})
                            </button>
                        ))}
                    </div>
                    <div className="search" style={{ maxWidth: 320, padding: '7px 12px' }}>
                        <Icon name="search" />
                        <input
                            placeholder="بحث بالعنوان أو الموكّل أو المستشار أو المرجع…"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                        {q && (
                            <button type="button" onClick={() => setQ('')} style={{ color: 'var(--faint)' }}>
                                <Icon name="close" />
                            </button>
                        )}
                    </div>
                </div>
            </div>

            {shown.length ? shown.map((m) => (
                <div key={m.id} className="card">
                    <div className="card-h">
                        <h3>{m.title}</h3>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                            {/* شارة الحالة الحيّة — كانت البطاقة بلا حالة فلا يُفرَّق القادم عن «لم ينعقد» */}
                            <Badge text={m.status} tone={meetStatusTone(m.status)} />
                            <Badge text={m.approve} tone={m.approved ? 'b-green' : 'b-amber'} />
                        </div>
                    </div>
                    <div className="card-b" style={{ padding: '14px 18px' }}>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 10 }}>
                            <span className="chip muted">{m.type}</span>
                            <span className="chip muted">{m.client}</span>
                            <span className="chip muted">{m.when}</span>
                            {m.lawyer !== '—' && <span className="chip muted"><Icon name="user" /> {m.lawyer}</span>}
                            {m.caseRef && <span className="chip muted"><Icon name="scale" /> {m.caseRef}</span>}
                        </div>

                        {/* ما لدى الاجتماع من مخرجات — يُعرف الناقصُ بلا فتح كلّ ملفّ */}
                        <div className="prot-list" style={{ marginBottom: 12 }}>
                            <span className="chip" style={{ opacity: m.summary ? 1 : 0.5 }}>{m.summary ? '✓ ملخّص' : 'بلا ملخّص'}</span>
                            <span className="chip" style={{ opacity: m.minutes ? 1 : 0.5 }}>{m.minutes ? '✓ محضر' : 'بلا محضر'}</span>
                            <span className="chip" style={{ opacity: m.decisions.length ? 1 : 0.5 }}>
                                {m.decisions.length ? `✓ قرارات (${m.decisions.length})` : 'بلا قرارات'}
                            </span>
                            {m.transcript && <span className="chip">✓ نصّ حرفيّ</span>}
                            {m.recording && <span className="chip">✓ تسجيل</span>}
                            {/* الحضور من سجلّ Zoom — وما لم يُقَس لا يُذكر */}
                            {attendanceLabel(m) && <span className="chip"><Icon name="user" /> {attendanceLabel(m)}</span>}
                        </div>

                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'center' }}>
                            {/* نافذة الدخول من الخادم (canJoin) — كما تحترمها بطاقة الموكّل تماماً */}
                            {m.canJoin ? (
                                <button className="btn sm" onClick={() => router.visit(`${base}/meetingroom?ref=${encodeURIComponent(m.id)}`)} type="button">
                                    <Icon name="video" /> دخول اجتماع Zoom
                                </button>
                            ) : m.up ? (
                                <span className="chip muted"><Icon name="clock" /> يُفتح الدخول قبل الموعد بخمس دقائق</span>
                            ) : null}
                            <button className="btn soft sm" onClick={() => openPage(m.id)} type="button">
                                <Icon name="doc" /> فتح الصفحة
                            </button>
                            {m.statusKey === 'missed' && (
                                <span className="chip" style={{ color: 'var(--amber)' }}>
                                    <Icon name="calplus" /> فات موعده — أعِد جدولته من صفحته
                                </span>
                            )}
                        </div>
                    </div>
                </div>
            )) : (
                <div className="card"><div className="card-b">
                    <div className="empty">
                        <Icon name="video" />
                        <b>{meetings.length ? 'لا اجتماع يطابق هذا الترشيح' : 'لا اجتماعات بعد'}</b>
                        {!meetings.length && (
                            <button className="btn sm" style={{ marginTop: 12 }} type="button"
                                onClick={() => router.visit(`${base}/meetreqs`)}>
                                <Icon name="send" /> أرسِل دعوة اجتماع لموكّل
                            </button>
                        )}
                    </div>
                </div></div>
            )}
        </>
    );
};
