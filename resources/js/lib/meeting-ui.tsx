import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import Modal from '@/components/babylon/Modal';
import TimeSlotPicker from '@/components/babylon/TimeSlotPicker';
import { todayISO } from '@/components/SpecialistPicker';
import { useToast } from '@/components/babylon/Toast';
import { nowClock, todayDate } from '@/lib/chat';
import { openMeeting } from '@/lib/consult-ui';
import { echo } from '@/lib/echo';
import { MR_FLOW, maskClient } from '@/lib/employee-data';
import Icon from '@/lib/icons';
import ZoomEmbedRoom from '@/lib/zoom-room';

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
    status: string;  // قادم/جارٍ/منتهٍ/مؤجل/ملغى
    priority: string;
    conf: string;
    attend: number;
    link: string;
    meetId: string;
    meetLink: string;
    hostLink: string | null;
    dur: string;
    summary: string | null;
    zoomSummary: string | null;
    recording: string | null;
    transcript: boolean;
    startsAt: string | null;
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
    zoomShareUrl?: string | null;
    zoomAudioUrl?: string | null;
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
    meetId: string | null;
    meetLink: string | null;
    hostLink: string | null;
    meetingRef: string | null; // مرجع الاجتماع المرتبط (M-…) للغرفة المضمّنة
    canJoin?: boolean; // زر الدخول يُفعَّل قبل الموعد بـ5 دقائق (يرسله MeetRequest::toCard)
}

export interface ClientDirEntry { id: number; name: string; items: string[] }

// غرفة الاجتماع المضمّنة لدور المكتب — فيديو Zoom داخل الموقع (المحضر/الملخص في صفحة الاجتماع)
export const StaffMeetingRoom: React.FC<{ meeting: FullMeetingCard; base: string }> = ({ meeting, base }) => (
    <ZoomEmbedRoom
        cref={meeting.id}
        kind="meeting"
        label={`${meeting.id} · ${meeting.title}`}
        back={`${base}/meeting?id=${encodeURIComponent(meeting.id)}`}
        fallbackUrl={meeting.hostLink || meeting.meetLink}
        viewer="staff"
        details={{
            title: meeting.title,
            status: meeting.status,
            rows: [
                { k: 'العميل', v: meeting.client },
                { k: 'المحامي', v: meeting.lawyer },
                { k: 'الموعد', v: meeting.when },
                { k: 'المدة', v: meeting.dur },
                ...(meeting.caseRef ? [{ k: 'المرجع', v: meeting.caseRef }] : []),
                ...(meeting.participants ? [{ k: 'المشاركون', v: meeting.participants }] : []),
            ],
            agenda: { before: meeting.before, during: meeting.during, after: meeting.after },
            summaryHref: `${base}/meeting?id=${encodeURIComponent(meeting.id)}`,
        }}
    />
);

// ============================================================
// طلبات الاجتماعات — صفحة مشتركة للموظف/المحامي/الإدارة
// يطابق meetReqsView + sendMeetInvite/mrCancel
// ============================================================

const MI_DURATIONS = [30, 45, 60, 90, 120];
// شبكة مواعيد ضمن ساعات العمل (09:00–20:30) كل 30 دقيقة
const MI_SLOTS: string[] = (() => {
    const out: string[] = [];
    for (let m = 9 * 60; m <= 20 * 60 + 30; m += 30) out.push(`${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`);
    return out;
})();
const hmToMin = (hm: string) => { const [h, m] = hm.split(':').map(Number); return h * 60 + m; };

export const MeetReqsPage: React.FC<{ requests: MeetReqCard[]; clients: ClientDirEntry[]; lawyers: { id: number; name: string }[]; selfLawyerId?: number | null; base: string }> = ({ requests, clients, lawyers, selfLawyerId, base }) => {
    const toast = useToast();
    const [open, setOpen] = useState(false);
    // إن كان المُنشئ محاميًا فهو المحامي المسؤول حصراً (لا يختار غيره)
    const selfLawyerName = selfLawyerId ? lawyers.find((l) => l.id === selfLawyerId)?.name : null;

    // حقول مودال إرسال الدعوة — بلا عميل افتراضي (اختيار صريح يمنع الإرسال الخاطئ)
    const [miClient, setMiClient] = useState<number | ''>('');
    const [miLawyer, setMiLawyer] = useState<number | ''>(selfLawyerId ?? '');
    const [miCase, setMiCase] = useState('');
    const [miService, setMiService] = useState('');
    const [miType, setMiType] = useState('استشارة مرئية');
    const [miDuration, setMiDuration] = useState(60);
    const [miDay, setMiDay] = useState(todayISO());
    const [miTime, setMiTime] = useState('');
    const [busy, setBusy] = useState<[string, string][]>([]); // فترات انشغال المحامي في اليوم
    useEffect(() => { setMiCase(''); }, [miClient]);

    // جلب المواعيد المحجوزة للمحامي في اليوم المختار
    useEffect(() => {
        if (!miLawyer || !miDay) { setBusy([]); return; }
        let alive = true;
        axios.get(`${base}/meetreqs/availability`, { params: { lawyer_id: miLawyer, day: miDay } })
            .then((r) => { if (alive) setBusy(r.data?.busy ?? []); })
            .catch(() => { if (alive) setBusy([]); });
        return () => { alive = false; };
    }, [miLawyer, miDay, base]);

    const caseOptions = clients.find((c) => c.id === miClient)?.items ?? [];
    const nowHM = () => new Date().toTimeString().slice(0, 5);
    // المواعيد المتاحة: تستبعد الماضية (اليوم) والمتعارضة مع حجوزات المحامي حسب المدة المختارة
    const availableSlots = useMemo(() => MI_SLOTS.filter((s) => {
        if (miDay === todayISO() && s <= nowHM()) return false;
        const cs = hmToMin(s); const ce = cs + miDuration;
        return !busy.some(([a, b]) => cs < hmToMin(b) && hmToMin(a) < ce);
    }), [busy, miDuration, miDay]);
    // صفّر الوقت إن لم يعد متاحاً بعد تغيير المحامي/اليوم/المدة
    useEffect(() => { if (miTime && !availableSlots.includes(miTime)) setMiTime(''); }, [availableSlots, miTime]);
    // قائمة كاملة بكل الأوقات — المحجوزة تُعلَّم taken:true لتُعرض رمادية في المكوّن
    const allSlotsWithStatus = useMemo(() => MI_SLOTS.map((s) => {
        const cs = hmToMin(s); const ce = cs + miDuration;
        const isBusy = busy.some(([a, b]) => cs < hmToMin(b) && hmToMin(a) < ce);
        return { time: s, taken: isBusy, label: isBusy ? 'محجوز' : undefined };
    }), [busy, miDuration, miDay]);

    const submitInvite = () => {
        if (!miClient) { toast('اختر العميل'); return; }
        if (!miLawyer) { toast('اختر المحامي المسؤول'); return; }
        if (!miTime) { toast('اختر موعداً متاحاً'); return; }
        const name = clients.find((c) => c.id === miClient)?.name ?? '';
        router.post(`${base}/meetreqs`, {
            client_id: miClient, lawyer_id: miLawyer, service: miService, type: miType,
            case_ref: miCase, day: miDay, time: miTime, duration: miDuration,
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setMiService(''); setMiTime('');
                toast(`تم إرسال الدعوة وإشعارها إلى العميل: ${name}`);
            },
            onError: (e) => toast(e.time || e.day || e.lawyer_id || 'تعذّر إرسال الدعوة'),
        });
    };

    const cancel = (r: MeetReqCard) =>
        router.post(`${base}/meetreqs/${r.dbId}/cancel`, {}, { preserveScroll: true, onSuccess: () => toast('تم إلغاء الدعوة') });

    // دخول الغرفة المضمّنة كمضيف ويعلّم «تنفيذ الجلسة»
    const enterRoom = (r: MeetReqCard) => {
        const go = () => {
            if (r.meetingRef) {
                router.visit(`${base}/meetingroom?ref=${encodeURIComponent(r.meetingRef)}`);
            } else if (r.type.indexOf('مرئية') >= 0) {
                openMeeting(r.hostLink || r.meetLink || '');
            } // احتياط
            else {
                toast('سيتم فتح رابط الاجتماع في موعده');
            }
        };

        if (r.stage === 1) {
            // onSuccess ثم الانتقال — كان visit يُجهض طلب البدء (سباق Inertia) فيدخل المضيف والجلسة لم تُعلَّم «جارية»
            router.post(`${base}/meetreqs/${r.dbId}/start`, {}, { preserveScroll: true, onSuccess: go, onError: () => toast('تعذّر بدء الجلسة') });
            return;
        }

        go();
    };

    // إعادة إرسال دعوة منتهية الصلاحية بموعد جديد — كانت stage 4 طريقاً مسدوداً بلا أي إجراء
    const [resendOf, setResendOf] = useState<MeetReqCard | null>(null);
    const [rsDay, setRsDay] = useState(todayISO());
    const [rsTime, setRsTime] = useState('');
    const submitResend = () => {
        if (!resendOf) { return; }
        if (!rsTime) { toast('اختر وقت الموعد الجديد'); return; }
        router.post(`${base}/meetreqs/${resendOf.dbId}/resend`, { day: rsDay, time: rsTime }, {
            preserveScroll: true,
            onSuccess: () => { setResendOf(null); setRsTime(''); toast('أُعيد إرسال الدعوة بالموعد الجديد وأُشعر العميل'); },
            onError: (e) => toast(e.time || e.day || 'تعذّرت إعادة الإرسال'),
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
                    يرسل المكتب دعوة الاجتماع للعميل، فيستقبلها ويؤكّد حضوره. <b>المسار:</b> إرسال الدعوة للعميل ← تأكيد حضور العميل ← تنفيذ الجلسة ← اعتماد الإدارة.
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
                                <b>{r.id} — {maskClient(r.client)}</b>
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
                                        <button className="btn sm" onClick={() => { setResendOf(r); setRsDay(todayISO()); setRsTime(''); }} type="button">
                                            <Icon name="send" /> إعادة إرسال بموعد جديد
                                        </button>
                                    </>
                                ) : r.stage >= 3 ? (
                                    <Badge text="معتمد" tone="b-green" />
                                ) : r.stage === 0 ? (
                                    <>
                                        <span className="chip muted">بانتظار تأكيد العميل</span>
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
                    يرسل المكتب الدعوة للعميل ليؤكّد حضوره — تصل لإشعاراته و«دعوات الاجتماعات».
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
                        <label>المدة</label>
                        <select className="input" value={miDuration} onChange={(e) => setMiDuration(Number(e.target.value))}>
                            {MI_DURATIONS.map((d) => <option key={d} value={d}>{d} دقيقة</option>)}
                        </select>
                    </div>
                </div>
                <div className="picker-grid">
                    <div className="field">
                        <label>قضية / استشارة العميل</label>
                        <select value={miCase} onChange={(e) => setMiCase(e.target.value)}>
                            <option value="">— اختر قضية/استشارة —</option>
                            {caseOptions.map((i) => <option key={i} value={i}>{i}</option>)}
                        </select>
                    </div>
                    <div className="field">
                        <label>الموضوع/الخدمة</label>
                        <input className="input" value={miService} onChange={(e) => setMiService(e.target.value)} placeholder="مثال: نزاع تجاري" />
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
                        allowCustom={false}
                        helperText={availableSlots.length === 0 ? 'لا مواعيد متاحة لهذا المحامي في هذا اليوم — جرّب يوماً آخر أو مدّة أقصر.' : undefined}
                    />
                )}
                <div className="action-hint" style={{ margin: '12px 0' }}>
                    <Icon name="info" /> تصل الدعوة للعميل عبر إشعار داخل النظام وبريد إلكتروني ليؤكّد حضوره.
                </div>
                <button className="btn block" onClick={submitInvite} type="button" disabled={!miClient || !miLawyer || !miTime}>
                    <Icon name="send" /> إرسال الدعوة للعميل
                </button>
            </Modal>

            <Modal title={`إعادة إرسال الدعوة ${resendOf?.id ?? ''} بموعد جديد`} open={!!resendOf} onClose={() => setResendOf(null)}>
                <p style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 12 }}>
                    انتهت صلاحية الدعوة دون تأكيد العميل — اختر موعداً جديداً لتعود الدعوة إلى «بانتظار التأكيد» ويُشعر العميل.
                </p>
                <div className="field">
                    <label>اليوم الجديد <span className="req">*</span></label>
                    <input className="input" type="date" min={todayISO()} value={rsDay} onChange={(e) => setRsDay(e.target.value)} />
                </div>
                <TimeSlotPicker
                    value={rsTime}
                    onChange={setRsTime}
                    date={rsDay}
                    label="وقت الاجتماع الجديد"
                    required
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

function defaultMinutes(m: FullMeetingCard): string {
    return `محضر اجتماع: ${m.title}\nالنوع: ${m.type}\nالتاريخ: ${m.when}\n\n` +
        `أبرز ما دار:\n- ${m.during.join('\n- ')}\n\n` +
        `القرارات والمهام:\n- ${m.after.join('\n- ')}`;
}

function defaultSummary(m: FullMeetingCard): string {
    return `ملخص اجتماع: ${m.title} — ${m.type}. أبرز ما دار: ${m.during.join(' ، ')}. ` +
        `الخلاصة والقرارات: ${m.after.join(' ، ')}.`;
}

export const MeetingDetailPage: React.FC<{ meeting: FullMeetingCard; base: string }> = ({ meeting: m, base }) => {
    const toast = useToast();

    // حالة لحظية: تتحدّث فور بثّ الخادم (إنهاء/اعتماد + الملخص/المحضر)
    const [status, setStatus] = useState(m.status);
    const [approve, setApprove] = useState(m.approve);
    const approved = approve === 'معتمد';

    const [summary, setSummary] = useState(m.summary || defaultSummary(m));
    const [minutes, setMinutes] = useState(m.minutes || defaultMinutes(m));
    const [decisions, setDecisions] = useState<string[]>(m.decisions ?? []);
    const [tasksDone, setTasksDone] = useState(m.tasksCreated);
    useEffect(() => {
        setSummary(m.summary || defaultSummary(m)); setMinutes(m.minutes || defaultMinutes(m));
        setDecisions(m.decisions ?? []); setTasksDone(m.tasksCreated); setStatus(m.status); setApprove(m.approve);
    }, [m.summary, m.minutes, m.decisions, m.tasksCreated, m.status, m.approve]);

    // بثّ لحظي لحالة الاجتماع (جارٍ→منتهٍ→معتمد + المخرجات بعد الاعتماد)
    useEffect(() => {
        const ch = echo.private(`meeting.${m.dbId}`).listen('.status', (e: { status: string; approve: string; summary: string | null; minutes: string | null }) => {
            setStatus(e.status); setApprove(e.approve);

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

    const saveSummary = () =>
        router.post(`${base}/meetings/${m.dbId}/summary`, { summary }, { preserveScroll: true, onSuccess: () => toast('تم حفظ الملخص') });
    const saveMinutes = () =>
        router.post(`${base}/meetings/${m.dbId}/minutes`, { minutes }, { preserveScroll: true, onSuccess: () => toast('تم حفظ المحضر') });

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
    const [lcMode, setLcMode] = useState<'reschedule' | 'end' | null>(null);
    const [reDay, setReDay] = useState('');
    const [reTime, setReTime] = useState('');
    const [endAttend, setEndAttend] = useState('90');
    const [endNotes, setEndNotes] = useState('');
    const manageable = !['منتهٍ', 'ملغى', 'لم ينعقد'].includes(status);
    // «لم ينعقد» (المشتقّة لاجتماع فات موعده) يجوز إعادة جدولته — دون بدء/إنهاء/دخول
    const canReschedule = manageable || status === 'لم ينعقد';
    const canStart = ['قادم', 'مؤجل'].includes(status);
    const canEnd = ['قادم', 'جارٍ'].includes(status);

    const startMeeting = () =>
        router.post(`${base}/meetings/${m.dbId}/start`, {}, { preserveScroll: true, onSuccess: () => toast('بدأت الجلسة') });
    const submitReschedule = () => {
        if (!reDay) { toast('اختر تاريخ الموعد الجديد'); return; }
        router.post(`${base}/meetings/${m.dbId}/reschedule`, { day: reDay, time: reTime }, {
            preserveScroll: true, onSuccess: () => { setLcMode(null); toast('أُعيدت جدولة الاجتماع'); },
        });
    };
    const submitEnd = () =>
        router.post(`${base}/meetings/${m.dbId}/end`, { attend: Number(endAttend) || 0, notes: endNotes }, {
            preserveScroll: true, onSuccess: () => { setLcMode(null); toast('أُنهي الاجتماع'); },
        });
    const cancelMeeting = () =>
        router.post(`${base}/meetings/${m.dbId}/cancel`, {}, { preserveScroll: true, onSuccess: () => toast('أُلغي الاجتماع') });

    // اعتماد الإدارة من صفحة التفاصيل — كان الاعتماد متاحاً من قائمة /admin/meetings فقط
    const approveMeeting = () =>
        router.post(`/admin/meetings/${m.dbId}/approve`, {}, {
            preserveScroll: true,
            onSuccess: () => { setApprove('معتمد'); toast('اعتُمد الاجتماع — وصل المحضر والملخص للعميل'); },
        });

    // ألوان حالة الاجتماع — مبنية على CSS variables المنصة (--deep / --primary / --cyan / --amber / --success / --red / --muted)
    const heroGradients: Record<string, string> = {
        'قادم':      'linear-gradient(135deg, #0A2A55 0%, #0E5C9C 55%, #11A0C8 100%)', // brand: --deep → --primary → --cyan
        'جارٍ':      'linear-gradient(135deg, #5a3500 0%, #C0832B 60%, #d9a450 100%)', // brand: --amber
        'منتهٍ':     'linear-gradient(135deg, #0b3320 0%, #1E9D6B 60%, #2abf85 100%)', // brand: --success
        'مؤجل':      'linear-gradient(135deg, #1e2d3d 0%, #607689 60%, #90A2B2 100%)', // brand: --muted / --faint
        'ملغى':      'linear-gradient(135deg, #3d0a0a 0%, #C0392B 60%, #d9504a 100%)', // brand: --red
        'لم ينعقد':  'linear-gradient(135deg, #1e2d3d 0%, #607689 60%, #90A2B2 100%)', // brand: --muted / --faint
    };
    const heroGrad = heroGradients[status] ?? heroGradients['قادم'];

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
                            <span style={{ display: 'flex', alignItems: 'center', gap: 5 }}>
                                <Icon name="cal" /> {m.dur}
                            </span>
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

                        {/* زر الاعتماد (إدارة فقط) */}
                        {base === '/admin' && !approved && (
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
                            <button
                                className="btn soft sm" type="button"
                                onClick={() => setLcMode(lcMode === 'reschedule' ? null : 'reschedule')}
                                style={{ display: 'flex', alignItems: 'center', gap: 6 }}
                            >
                                <Icon name="cal" /> إعادة جدولة
                            </button>
                            {manageable && (
                                <button
                                    className="btn soft sm" type="button" onClick={cancelMeeting}
                                    style={{ display: 'flex', alignItems: 'center', gap: 6, color: 'var(--red)' }}
                                >
                                    <Icon name="info" /> إلغاء الاجتماع
                                </button>
                            )}
                        </div>

                        {lcMode === 'reschedule' && (
                            <div style={{
                                marginTop: 16, padding: 16,
                                background: 'var(--surface-soft, #f8fafc)',
                                border: '1px solid var(--line-soft, #e2e8f0)',
                                borderRadius: 12,
                            }}>
                                <div style={{ fontSize: '13px', fontWeight: 700, color: 'var(--deep)', marginBottom: 12 }}>
                                    📅 تحديد الموعد الجديد
                                </div>
                                <div className="field" style={{ marginBottom: 12 }}>
                                    <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>التاريخ الجديد</label>
                                    <input className="input" type="date" value={reDay} onChange={(e) => setReDay(e.target.value)} style={{ borderRadius: 9 }} />
                                </div>
                                <TimeSlotPicker
                                    value={reTime}
                                    onChange={setReTime}
                                    date={reDay}
                                    label="الوقت الجديد للاجتماع"
                                    required
                                />
                                <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                                    <button className="btn sm" type="button" onClick={submitReschedule} disabled={!reTime}>
                                        <Icon name="cal" /> حفظ الموعد الجديد
                                    </button>
                                    <button className="btn soft sm" type="button" onClick={() => setLcMode(null)}>إلغاء</button>
                                </div>
                            </div>
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
                📡  بيانات جلسة Zoom الفعلية (إدارة فقط)
            ════════════════════════════════════════ */}
            {base === '/admin' && (
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
                                { label: 'نسبة الحضور', value: status === 'منتهٍ' ? `${m.attend || 0}%` : null },
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

                        {/* روابط التسجيل والتنزيل */}
                        <div style={{ padding: '12px 16px', display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                            {m.recording ? (
                                <>
                                    <a
                                        href={m.recording} target="_blank" rel="noopener noreferrer"
                                        style={{
                                            display: 'inline-flex', alignItems: 'center', gap: 5,
                                            padding: '7px 14px', borderRadius: 9,
                                            background: 'var(--primary)', color: '#fff',
                                            fontSize: '12px', fontWeight: 700,
                                        }}
                                    >
                                        <Icon name="video" /> مشاهدة التسجيل
                                    </a>
                                    <a
                                        href={`${base}/meetings/${m.dbId}/recording.zip`}
                                        style={{
                                            display: 'inline-flex', alignItems: 'center', gap: 5,
                                            padding: '7px 14px', borderRadius: 9,
                                            border: '1px solid var(--line-soft)',
                                            background: 'var(--paper-2)',
                                            fontSize: '12px', fontWeight: 600,
                                        }}
                                    >
                                        تنزيل (ZIP)
                                    </a>
                                </>
                            ) : (
                                <span style={{ fontSize: '12.5px', color: 'var(--muted)' }}>لا يوجد تسجيل مرئي بعد</span>
                            )}
                            {m.zoomAudioUrl && (
                                <a
                                    href={`${base}/meetings/${m.dbId}/audio.zip`}
                                    style={{
                                        display: 'inline-flex', alignItems: 'center', gap: 5,
                                        padding: '7px 14px', borderRadius: 9,
                                        border: '1px solid var(--line-soft)',
                                        background: 'var(--paper-2)',
                                        fontSize: '12px', fontWeight: 600,
                                    }}
                                >
                                    🎵 تسجيل صوتي (ZIP)
                                </a>
                            )}
                            {(m.transcript || m.recording) && (
                                <a
                                    href={`${base}/meetings/${m.dbId}/transcript`}
                                    style={{
                                        display: 'inline-flex', alignItems: 'center', gap: 5,
                                        padding: '7px 14px', borderRadius: 9,
                                        border: '1px solid var(--line-soft)',
                                        background: 'var(--paper-2)',
                                        fontSize: '12px', fontWeight: 600,
                                    }}
                                >
                                    📄 تنزيل النص الكامل
                                </a>
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
                                <p style={{ color: 'var(--muted)', fontSize: '12.5px', lineHeight: 1.7, whiteSpace: 'pre-wrap', margin: 0 }}>
                                    {m.zoomSummary}
                                </p>
                            </div>
                        )}

                        {!m.joinTime && !m.recording && !m.transcript && status !== 'منتهٍ' && (
                            <div className="action-hint" style={{ margin: '8px 16px' }}>
                                تظهر بيانات الجلسة الفعلية (الدخول/المدة/التسجيل/النص) تلقائيًّا بعد انعقاد الجلسة عبر ويبهوك Zoom.
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* ═══════════════════════════════════════
                📋  مخرجات الفريق القانوني
            ════════════════════════════════════════ */}
            <div style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))',
                gap: 12,
                marginBottom: 18,
            }}>
                {[
                    { label: 'قبل الاجتماع', icon: '📝', items: m.before, cssColor: 'var(--primary)', cssBg: 'rgba(14,92,156,0.08)', cssBorder: 'rgba(14,92,156,0.18)' },
                    { label: 'أثناء الاجتماع', icon: '🎙️', items: m.during, cssColor: 'var(--amber)', cssBg: 'var(--amber-bg)', cssBorder: 'rgba(192,131,43,0.2)' },
                    { label: 'بعد الاجتماع', icon: '✅', items: m.after, cssColor: 'var(--success)', cssBg: 'var(--success-bg)', cssBorder: 'rgba(30,157,107,0.2)' },
                ].map((col) => (
                    <div key={col.label} style={{
                        background: 'var(--paper)',
                        border: `1px solid ${col.cssBorder}`,
                        borderRadius: 14,
                        overflow: 'hidden',
                        boxShadow: 'var(--shadow)',
                    }}>
                        <div style={{
                            padding: '10px 14px',
                            background: col.cssBg,
                            borderBottom: `1px solid ${col.cssBorder}`,
                            display: 'flex', alignItems: 'center', gap: 7,
                            fontWeight: 700, fontSize: '13px', color: col.cssColor,
                        }}>
                            <span>{col.icon}</span> {col.label}
                        </div>
                        <ul style={{ margin: 0, padding: '10px 18px', listStyle: 'none' }}>
                            {col.items.map((x, i) => (
                                <li key={i} style={{
                                    padding: '6px 0',
                                    fontSize: '13px',
                                    color: 'var(--ink)',
                                    borderBottom: i < col.items.length - 1 ? '1px solid var(--line-soft)' : 'none',
                                    display: 'flex', alignItems: 'flex-start', gap: 8,
                                }}>
                                    <span style={{ color: col.cssColor, flexShrink: 0, marginTop: 2 }}>•</span>
                                    {x}
                                </li>
                            ))}
                            {col.items.length === 0 && (
                                <li style={{ padding: '10px 0', color: 'var(--muted)', fontSize: '12.5px' }}>لا بنود بعد</li>
                            )}
                        </ul>
                    </div>
                ))}
            </div>

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
                            background: m.sumApproved ? 'var(--success-bg)' : 'var(--amber-bg)',
                            color: m.sumApproved ? 'var(--success)' : 'var(--amber)',
                        }}>
                            {m.sumApproved ? '✓ معتمد' : 'مسودة'}
                        </span>
                    </div>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                        {m.sumApproved && <Badge text="الملخص معتمد ومُرسل للعميل" tone="b-green" />}
                        <button
                            className="btn soft sm" onClick={saveSummary} type="button"
                            style={{ fontSize: '12px' }}
                        >
                            حفظ الملخص
                        </button>
                    </div>
                </div>
                <div style={{ padding: '14px 18px' }}>
                    <textarea
                        value={summary}
                        onChange={(e) => setSummary(e.target.value)}
                        style={{
                            width: '100%', minHeight: 120,
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
                    </div>
                    <button
                        className="btn soft sm" onClick={saveMinutes} type="button"
                        style={{ fontSize: '12px' }}
                    >
                        حفظ المحضر
                    </button>
                </div>
                <div style={{ padding: '14px 18px' }}>
                    <textarea
                        value={minutes}
                        onChange={(e) => setMinutes(e.target.value)}
                        style={{
                            width: '100%', minHeight: 160,
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
        </div>
    );
};

// ============================================================
// قائمة اجتماعات المكتب — مشتركة للمحامي والموظف (يطابق lwMeetings)
// ============================================================

export const MeetingsListPage: React.FC<{ meetings: FullMeetingCard[]; base: string }> = ({ meetings, base }) => {
    const toast = useToast();
    const openPage = (id: string) => router.visit(`${base}/meeting?id=${encodeURIComponent(id)}`);

    return (
        <>
            <div className="ai-banner">
                <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
                <p>الفريق القانوني يجهّز الاجتماع قبله، يوثّقه أثناءه، ويستخرج المحضر والمهام والقرارات بعده.</p>
            </div>

            {meetings.length ? meetings.map((m) => (
                <div key={m.id} className="card">
                    <div className="card-h">
                        <h3>{m.title}</h3>
                        <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                            {/* شارة الحالة الحيّة — كانت البطاقة بلا حالة فلا يُفرَّق القادم عن «لم ينعقد» */}
                            <Badge text={m.status} tone={meetStatusTone(m.status)} />
                            <Badge text={m.approve} tone={m.approve === 'معتمد' ? 'b-green' : 'b-amber'} />
                        </div>
                    </div>
                    <div className="card-b" style={{ padding: '14px 18px' }}>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', marginBottom: 13 }}>
                            <span className="chip muted">{m.type}</span>
                            <span className="chip muted">{m.client}</span>
                            <span className="chip muted">{m.when}</span>
                        </div>
                        <div className="mpanel">
                            <div className="mbox">
                                <div className="h">قبل الاجتماع</div>
                                <ul>{m.before.map((x, i) => <li key={i}>{x}</li>)}</ul>
                            </div>
                            <div className="mbox">
                                <div className="h">أثناء الاجتماع</div>
                                <ul>{m.during.map((x, i) => <li key={i}>{x}</li>)}</ul>
                            </div>
                            <div className="mbox">
                                <div className="h">بعد الاجتماع</div>
                                <ul>{m.after.map((x, i) => <li key={i}>{x}</li>)}</ul>
                            </div>
                        </div>
                        <div style={{ display: 'flex', gap: 8, marginTop: 14, flexWrap: 'wrap' }}>
                            {/* «لم ينعقد» فات موعده — الدخول بلا معنى ويصدّه الخادم أصلاً */}
                            {m.meetLink && !['منتهٍ', 'ملغى', 'لم ينعقد'].includes(m.status) && (
                                <button className="btn sm" onClick={() => router.visit(`${base}/meetingroom?ref=${encodeURIComponent(m.id)}`)} type="button">
                                    <Icon name="video" /> دخول اجتماع Zoom
                                </button>
                            )}
                            <button className="btn soft sm" onClick={() => openPage(m.id)} type="button">
                                <Icon name="doc" /> فتح الصفحة
                            </button>
                            <button
                                className="btn soft sm"
                                onClick={() => (m.summary ? openPage(m.id) : toast('لم يُحفظ ملخص بعد — افتح الصفحة لإعداده'))}
                                type="button"
                            >
                                <Icon name="out" /> الملخص
                            </button>
                        </div>
                    </div>
                </div>
            )) : (
                <div className="card"><div className="card-b">
                    <div className="empty"><Icon name="video" /><b>لا اجتماعات بعد</b></div>
                </div></div>
            )}
        </>
    );
};
