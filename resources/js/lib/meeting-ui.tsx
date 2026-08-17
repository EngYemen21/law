import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useMemo, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import Modal from '@/components/babylon/Modal';
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
    branch: string;
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
        if (r.stage === 1) {
            router.post(`${base}/meetreqs/${r.dbId}/start`, {}, { preserveScroll: true });
        }

        if (r.meetingRef) {
            router.visit(`${base}/meetingroom?ref=${encodeURIComponent(r.meetingRef)}`);
        } else if (r.type.indexOf('مرئية') >= 0) {
            openMeeting(r.hostLink || r.meetLink || '');
        } // احتياط
        else {
            toast('سيتم فتح رابط الاجتماع في موعده');
        }
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
                                {r.stage === 4 ? (
                                    <Badge text="منتهية الصلاحية" tone="b-red" />
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
                                {r.stage === 1 && r.meetLink && (
                                    <>
                                        <button className="btn soft sm" onClick={() => copyLink(r)} type="button">
                                            <Icon name="link" /> نسخ الرابط
                                        </button>
                                        <button className="btn sm" onClick={() => enterRoom(r)} type="button">
                                            <Icon name="video" /> {r.type.indexOf('مرئية') >= 0 ? 'دخول جلسة Zoom' : 'دخول'}
                                        </button>
                                    </>
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
                <div className="picker-grid">
                    <div className="field">
                        <label>اليوم <span className="req">*</span></label>
                        <input className="input" type="date" min={todayISO()} value={miDay} onChange={(e) => setMiDay(e.target.value)} />
                    </div>
                    <div className="field">
                        <label>الموعد المتاح <span className="req">*</span></label>
                        <select className="input" value={miTime} onChange={(e) => setMiTime(e.target.value)} disabled={!miLawyer}>
                            <option value="">{!miLawyer ? '— اختر المحامي أولاً —' : (availableSlots.length ? '— اختر موعداً —' : 'لا مواعيد متاحة')}</option>
                            {availableSlots.map((s) => <option key={s} value={s}>{s}</option>)}
                        </select>
                    </div>
                </div>
                {miLawyer && miDay && availableSlots.length === 0 && (
                    <div style={{ color: 'var(--danger, #c0392b)', fontSize: 12, margin: '2px 0 8px' }}>
                        لا مواعيد متاحة لهذا المحامي في هذا اليوم — جرّب يوماً آخر أو مدّة أقصر.
                    </div>
                )}
                <div className="action-hint" style={{ margin: '12px 0' }}>
                    <Icon name="info" /> تصل الدعوة للعميل عبر إشعار داخل النظام وبريد إلكتروني ليؤكّد حضوره.
                </div>
                <button className="btn block" onClick={submitInvite} type="button" disabled={!miClient || !miLawyer || !miTime}>
                    <Icon name="send" /> إرسال الدعوة للعميل
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

    return (
        <div className="detail-wrap">
            <div style={{ marginBottom: 14 }}>
                <Link href={`${base}/meetings`} className="btn soft sm">
                    <Icon name="reply" /> رجوع للاجتماعات
                </Link>
            </div>

            <div className="card" style={{ marginBottom: 16 }}>
                <div className="card-h">
                    <h3>{m.title}</h3>
                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                        <Badge text={status} tone={meetStatusTone(status)} />
                        <Badge text={approve} tone={approved ? 'b-green' : 'b-amber'} />
                    </div>
                </div>
                <div className="card-b" style={{ padding: '14px 18px' }}>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <span className="chip muted">{m.type}</span>
                        <span className="chip muted">{m.client}</span>
                        <span className="chip muted">{m.when}</span>
                        <span className="chip muted">{m.dur}</span>
                        {m.caseRef && <span className="chip muted">{m.caseRef}</span>}
                    </div>
                    {m.participants && (
                        <div style={{ marginTop: 11, fontSize: '12.5px', color: 'var(--ink)' }}>
                            <b>المشاركون:</b> <span style={{ color: 'var(--muted)' }}>{m.participants}</span>
                        </div>
                    )}
                    {m.meetLink && manageable && (
                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap', marginTop: 12, paddingTop: 12, borderTop: '1px solid var(--line-soft)' }}>
                            <button className="btn soft sm" onClick={copyLink} type="button">
                                <Icon name="link" /> نسخ الرابط
                            </button>
                            <button className="btn sm" onClick={() => router.visit(`${base}/meetingroom?ref=${encodeURIComponent(m.id)}`)} type="button">
                                <Icon name="video" /> دخول اجتماع Zoom
                            </button>
                        </div>
                    )}
                </div>
            </div>

            {/* إدارة دورة حياة الاجتماع — متزامنة مع Zoom خادميًّا */}
            {manageable && (
                <div className="card" style={{ marginBottom: 16 }}>
                    <div className="card-h"><h3>إدارة الجلسة</h3></div>
                    <div className="card-b" style={{ padding: 14 }}>
                        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            {canStart && <button className="btn sm" type="button" onClick={startMeeting}><Icon name="video" /> بدء الجلسة</button>}
                            {canEnd && <button className="btn soft sm" type="button" onClick={() => setLcMode(lcMode === 'end' ? null : 'end')}><Icon name="check" /> إنهاء الاجتماع</button>}
                            <button className="btn soft sm" type="button" onClick={() => setLcMode(lcMode === 'reschedule' ? null : 'reschedule')}><Icon name="cal" /> إعادة جدولة</button>
                            <button className="btn soft sm" type="button" onClick={cancelMeeting}><Icon name="info" /> إلغاء الاجتماع</button>
                        </div>

                        {lcMode === 'reschedule' && (
                            <div className="picker-grid" style={{ marginTop: 12 }}>
                                <div className="field"><label>التاريخ الجديد</label><input className="input" type="date" value={reDay} onChange={(e) => setReDay(e.target.value)} /></div>
                                <div className="field"><label>الوقت</label><input className="input" type="time" value={reTime} onChange={(e) => setReTime(e.target.value)} /></div>
                                <div style={{ gridColumn: '1 / -1', display: 'flex', gap: 6 }}>
                                    <button className="btn sm" type="button" onClick={submitReschedule}><Icon name="cal" /> حفظ الموعد الجديد</button>
                                    <button className="btn soft sm" type="button" onClick={() => setLcMode(null)}>إلغاء</button>
                                </div>
                            </div>
                        )}

                        {lcMode === 'end' && (
                            <div style={{ marginTop: 12 }}>
                                <div className="picker-grid">
                                    <div className="field"><label>نسبة الحضور %</label><input className="input" type="number" min={0} max={100} value={endAttend} onChange={(e) => setEndAttend(e.target.value)} /></div>
                                </div>
                                <div className="field"><label>ملاحظات/نقاط الجلسة (تُغذّي الملخّص)</label><textarea value={endNotes} onChange={(e) => setEndNotes(e.target.value)} style={{ minHeight: 70 }} /></div>
                                <div style={{ display: 'flex', gap: 6 }}>
                                    <button className="btn sm" type="button" onClick={submitEnd}><Icon name="check" /> تأكيد الإنهاء</button>
                                    <button className="btn soft sm" type="button" onClick={() => setLcMode(null)}>إلغاء</button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}

            {/* بيانات جلسة Zoom الفعلية (من الويبهوك) — الإدارة العليا فقط */}
            {base === '/admin' && (
                <div className="card" style={{ marginBottom: 16 }}>
                    <div className="card-h"><h3>بيانات جلسة Zoom</h3><span className="sub">بيانات فعلية من الويبهوك</span></div>
                    <div className="card-b" style={{ padding: 16 }}>
                        {m.joinTime && <div className="kpi-row"><span className="t">دخول أول مشارك</span><span className="v" style={{ direction: 'ltr' }}>{m.joinTime}</span></div>}
                        {m.leaveTime && <div className="kpi-row"><span className="t">آخر مغادرة</span><span className="v" style={{ direction: 'ltr' }}>{m.leaveTime}</span></div>}
                        {fmtActualDuration(m.durationSec) && <div className="kpi-row"><span className="t">مدة الحضور الفعلية</span><span className="v">{fmtActualDuration(m.durationSec)}</span></div>}
                        {status === 'منتهٍ' && <div className="kpi-row"><span className="t">نسبة الحضور</span><span className="v">{m.attend || 0}%</span></div>}
                        <div className="kpi-row"><span className="t">التسجيل المرئي</span><span className="v">{m.recording ? <a href={m.recording} target="_blank" rel="noopener noreferrer">فتح التسجيل</a> : '—'}</span></div>
                        <div className="kpi-row"><span className="t">النص الكامل</span><span className="v">{m.transcript ? <a href={`${base}/meetings/${m.dbId}/transcript`}>تنزيل النص</a> : '—'}</span></div>
                        {m.zoomSummaryAt && <div className="kpi-row"><span className="t">ملخّص Zoom AI بتاريخ</span><span className="v" style={{ direction: 'ltr' }}>{m.zoomSummaryAt}</span></div>}
                        {m.zoomSummary && (
                            <div style={{ marginTop: 10 }}>
                                <b style={{ fontSize: '12.5px' }}>ملخّص Zoom AI:</b>
                                <p style={{ color: 'var(--muted)', fontSize: '12.5px', marginTop: 4, whiteSpace: 'pre-wrap' }}>{m.zoomSummary}</p>
                            </div>
                        )}
                        {!m.joinTime && !m.recording && !m.transcript && status !== 'منتهٍ' && (
                            <div className="action-hint">تظهر بيانات الجلسة الفعلية (الدخول/المدة/التسجيل/النص) تلقائيًّا بعد انعقاد الجلسة عبر ويبهوك Zoom.</div>
                        )}
                    </div>
                </div>
            )}

            <div className="ai-banner">
                <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
                <p>مخرجات الفريق القانوني للاجتماع (قبل/أثناء/بعد)، مع إمكانية تعديل المحضر واعتماده.</p>
            </div>

            <div className="mpanel" style={{ marginBottom: 16 }}>
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

            <div className="doc-edit" style={{ marginBottom: 8 }}>
                <div className="doc-head">
                    <span className="di"><Icon name="doc" /></span>
                    <b>ملخص الاجتماع</b>
                    <span className="tag">{m.sumApproved ? 'معتمد' : 'مسودة'}</span>
                </div>
                <textarea value={summary} onChange={(e) => setSummary(e.target.value)} />
            </div>
            <div style={{ display: 'flex', gap: 9, margin: '10px 0 18px', flexWrap: 'wrap' }}>
                <button className="btn soft" onClick={saveSummary} type="button">حفظ الملخص</button>
                {m.sumApproved && <Badge text="الملخص معتمد ومُرسل للعميل" tone="b-green" />}
            </div>

            <div className="doc-edit">
                <div className="doc-head">
                    <span className="di"><Icon name="doc" /></span>
                    <b>محضر الاجتماع</b>
                    <span className="tag">{m.id}</span>
                </div>
                <textarea value={minutes} onChange={(e) => setMinutes(e.target.value)} />
            </div>

            <div className="card" style={{ marginTop: 16 }}>
                <div className="card-h">
                    <h3>القرارات والمهام</h3>
                    <button className="btn soft sm" onClick={decisionsToTasks} type="button" disabled={tasksDone || decisions.length === 0}>
                        <Icon name="check" /> {tasksDone ? 'حُوّلت إلى مهام' : 'تحويل القرارات إلى مهام'}
                    </button>
                </div>
                <div className="card-b" style={{ padding: '14px 16px' }}>
                    {decisions.length ? (
                        <ul style={{ margin: 0, paddingInlineStart: 18, lineHeight: 2 }}>
                            {decisions.map((x, i) => <li key={i}>{decisionText(x)}</li>)}
                        </ul>
                    ) : (
                        <div className="empty" style={{ padding: '8px 0' }}>
                            <Icon name="check" /><b>تُستخرج القرارات تلقائياً بعد إنهاء الاجتماع</b>
                        </div>
                    )}
                </div>
            </div>

            <div className="prot-box">
                <div className="ph"><Icon name="lock" /> حماية الاجتماع</div>
                <div className="prot-list">
                    <span className="chip">منع التحميل</span>
                    <span className="chip">منع النسخ</span>
                    <span className="chip">منع الطباعة</span>
                    <span className="chip">منع المشاركة</span>
                    <span className="chip">علامة مائية ديناميكية</span>
                </div>
                <div className="audit">Audit Log · {m.client} · {m.id} · {todayDate()} {nowClock()}</div>
            </div>

            <div style={{ display: 'flex', gap: 9, marginTop: 16, flexWrap: 'wrap' }}>
                <button className="btn soft" onClick={saveMinutes} type="button">حفظ المحضر</button>
            </div>
        </div>
    );
};
