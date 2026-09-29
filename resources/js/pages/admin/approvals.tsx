import { Link, router } from '@inertiajs/react';
import React, { useEffect, useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { type SummaryData } from '@/lib/lawyer-data';

// ============================================================================
// مركز الاعتمادات والقرارات الإدارية (Executive Approval & Decision Hub)
// عرض جدولي تنفيذي مسطح (Flat)، أزرار أيقونية معبرة، وثبات تام في التصميم
// ============================================================================

export interface TicketSummaryRow {
    id?: number;
    no: string;
    type: string;
    department?: string;
    priority?: string;
    client: string;
    clientPhone?: string;
    lawyer: string;
    facts?: string | null;
    keyPoints?: string | null;
    caseSummary?: string | null;
    since?: string | null;
    at?: string | null;
}

export interface TicketTrackProposalRow {
    id?: number;
    no: string;
    type: string;
    department?: string;
    priority?: string;
    client: string;
    clientPhone?: string;
    proposedTrack: string;
    proposedTrackLabel: string;
    /** `TicketOutcomeTrack::tone()` من الخادم. */
    proposedTrackTone: string;
    proposedTrackReason: string;
    proposedBy: string;
    proposedByRole?: string;
    aiSuggestedTrack?: string | null;
    aiSuggestedTrackLabel?: string | null;
    aiSuggestedReason?: string | null;
    /** مانع الاعتماد من حارس المآل نفسه (`OutcomeSummaryGate::blocker`) — `null` = لا مانع. */
    outcomeBlocker?: string | null;
    consultBlocker?: string | null;
    /** سبب التجاوز الذي دوّنه هذا المدير حين رفع المقترح — يُورَث فلا يُطلب ثانيةً. */
    inheritedWaiver?: string | null;
    since?: string | null;
    at?: string | null;
}

/** أقصر سبب تجاوزٍ يقبله الخادم (`OutcomeSummaryGate::WAIVER_MIN`). */
const WAIVER_MIN = 10;

export interface SessionSummaryRow {
    id?: number;
    ref: string;
    ticketNo?: string | null;
    client: string;
    clientPhone?: string;
    lawyer: string;
    channel?: string;
    summary?: string | null;
    since?: string | null;
    at?: string | null;
}

export interface AppointmentRow {
    id?: number;
    ref: string;
    client: string;
    clientPhone?: string;
    day?: string | null;
    time?: string | null;
    lawyer: string;
    channel: string;
    since?: string | null;
}

export type HistorySummaryItem = SummaryData & {
    type?: string;
    client?: string;
    clientPhone?: string;
    department?: string;
    createdAtFormatted?: string;
};

interface CountsData {
    proposals: number;
    summaries: number;
    sessions: number;
    appointments: number;
    history: number;
    totalPending: number;
}

interface Props {
    ticketSummaries: TicketSummaryRow[];
    ticketTrackProposals?: TicketTrackProposalRow[];
    sessionSummaries: SessionSummaryRow[];
    appointments: AppointmentRow[];
    approvedHistory?: HistorySummaryItem[];
    counts?: CountsData;
    /** أسباب الإغلاق من الكتالوج (`ClosureReasonCode::options`) — لاعتماد مسار «إغلاق». */
    closureReasons?: { code: string; label: string }[];
}

type TabKey = 'all' | 'tracks' | 'summaries' | 'sessions' | 'appointments' | 'history';
type ActionType = 'approve' | 'reject' | 'view';
type ItemCategory = 'track' | 'summary' | 'session' | 'appointment' | 'history';

interface ActiveModalState {
    action: ActionType;
    category: ItemCategory;
    item: any;
}

const AdminApprovals: React.FC<Props> = ({
    ticketSummaries = [],
    ticketTrackProposals = [],
    sessionSummaries = [],
    appointments = [],
    approvedHistory = [],
    counts,
    closureReasons = [],
}) => {
    const toast = useToast();

    // قراءة التبويب الافتراضي من الرابط (?tab=history — سجلّ الملخّصات المعتمدة)
    const initialTab = useMemo<TabKey>(() => {
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const tabParam = urlParams.get('tab') as TabKey;
            if (['all', 'tracks', 'summaries', 'sessions', 'appointments', 'history'].includes(tabParam)) {
                return tabParam;
            }
        } catch {
            // fallback
        }
        return 'all';
    }, []);

    const [activeTab, setActiveTab] = useState<TabKey>(initialTab);
    const [searchQuery, setSearchQuery] = useState('');

    // حالة النافذة التأكيدية المنبثقة
    const [modalState, setModalState] = useState<ActiveModalState | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    // اعتماد المسار: سبب تجاوز الملخّص (حيث يمنعه الحارس) وسبب الإغلاق (لمسار «إغلاق»)
    const [waiver, setWaiver] = useState('');
    const [closureCode, setClosureCode] = useState('');
    const [isProcessing, setIsProcessing] = useState(false);
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        setMounted(true);
    }, []);

    // العدادات الإجمالية
    const metricCounts: CountsData = useMemo(() => {
        if (counts) return counts;
        const p = ticketTrackProposals.length;
        const s = ticketSummaries.length;
        const sess = sessionSummaries.length;
        const a = appointments.length;
        const h = approvedHistory.length;
        return {
            proposals: p,
            summaries: s,
            sessions: sess,
            appointments: a,
            history: h,
            totalPending: p + s + sess + a,
        };
    }, [counts, ticketTrackProposals, ticketSummaries, sessionSummaries, appointments, approvedHistory]);

    // فتح نافذة الإجراء
    const openModal = (action: ActionType, item: any, category: ItemCategory) => {
        setRejectReason('');
        setWaiver('');
        setClosureCode('');
        setModalState({ action, item, category });
    };

    const closeModal = () => {
        if (isProcessing) return;
        setModalState(null);
        setRejectReason('');
    };

    /**
     * خطأ الخادم كما قاله — كانت كلّ الإجراءات تقول «حدث خطأ» فيضيع سبب الرفض (مانع الملخّص،
     * موعدٌ فات، محضرٌ اعتُمد). ورسالة النجاح من الخادم (flash) يعرضها التخطيط — لا إشعار ثانٍ هنا.
     */
    const failWith = (fallback: string) => (errors: Record<string, string>) =>
        toast(`⚠️ ${Object.values(errors)[0] ?? fallback}`, 'error');

    const post = (url: string, data: Record<string, unknown>, fallback: string) =>
        router.post(url, data as never, {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: failWith(fallback),
            onFinish: () => setIsProcessing(false),
        });

    // حاجة صفّ المسار المفتوح: هل يلزمه سبب تجاوز؟ وهل هو «إغلاق» يلزمه سببٌ من الكتالوج؟
    const trackItem = modalState?.category === 'track' ? (modalState.item as TicketTrackProposalRow) : null;
    const needsWaiver = !!trackItem?.outcomeBlocker && !trackItem?.inheritedWaiver;
    const needsClosureCode = trackItem?.proposedTrack === 'close';
    const approveReady = !trackItem
        || (!trackItem.consultBlocker && (!needsWaiver || waiver.trim().length >= WAIVER_MIN) && (!needsClosureCode || closureCode !== ''));

    // تنفيذ الموافقة الرسمية المباشرة لجميع الفئات
    const handleApprove = () => {
        if (!modalState?.item || !approveReady) {
            return;
        }

        const { item, category } = modalState;
        setIsProcessing(true);

        if (category === 'track') {
            const reason = (item.proposedTrackReason && item.proposedTrackReason.trim().length >= 10)
                ? item.proposedTrackReason.trim()
                : 'تم اعتماد المسار رسمياً من قبل الإدارة العليا بموجب الصلاحية الإدارية.';
            post(
                `/admin/tickets/${encodeURIComponent(item.no)}/track/approve`,
                {
                    track: item.proposedTrack,
                    reason,
                    closure_reason_code: needsClosureCode ? closureCode : undefined,
                    // المسار السريع للإدارة بلا ملخّصٍ معتمد — الموروث يقرؤه الخادم بنفسه
                    summary_waiver_reason: needsWaiver ? waiver.trim() : undefined,
                },
                'تعذّر اعتماد المسار',
            );
            return;
        }

        if (category === 'summary') {
            post(`/admin/summary/${encodeURIComponent(item.no)}/approve`, {}, 'تعذّر اعتماد الملخّص');
            return;
        }

        if (category === 'session') {
            post(`/admin/consults/${item.id}/summary/approve`, {}, 'تعذّر اعتماد محضر الجلسة');
            return;
        }

        if (category === 'appointment') {
            post(`/admin/consults/${item.id}/appointment/approve`, {}, 'تعذّر اعتماد الموعد');
            return;
        }

        setIsProcessing(false);
    };

    // تنفيذ الرفض مع تدوين السبب
    const handleReject = () => {
        if (!modalState?.item) return;
        const { item, category } = modalState;
        const ref = item.no || item.ref;

        // الخادم يشترط ٣ أحرف على الأقلّ (`ApprovalsController::reject`) — يُفحص هنا برسالةٍ واضحة لا خطأٍ عامّ
        if (rejectReason.trim().length < 3) {
            toast('يرجى كتابة سبب الرفض أو التوجيهات للمستشار/الموظف (ثلاثة أحرف على الأقلّ)');
            return;
        }

        setIsProcessing(true);
        router.post(
            '/admin/approvals/reject',
            {
                type: category,
                ref,
                reason: rejectReason.trim(),
            },
            {
                preserveScroll: true,
                onSuccess: () => closeModal(),
                onError: failWith('تعذّر رفض الطلب'),
                onFinish: () => setIsProcessing(false),
            },
        );
    };

    // فلترة نصية سريعة
    const q = searchQuery.trim().toLowerCase();

    const filteredProposals = useMemo(() => {
        if (!q) return ticketTrackProposals;
        return ticketTrackProposals.filter(
            (p) =>
                p.no.toLowerCase().includes(q) ||
                p.client.toLowerCase().includes(q) ||
                p.type.toLowerCase().includes(q) ||
                p.proposedTrackLabel.toLowerCase().includes(q) ||
                p.proposedBy.toLowerCase().includes(q),
        );
    }, [ticketTrackProposals, q]);

    const filteredTicketSummaries = useMemo(() => {
        if (!q) return ticketSummaries;
        return ticketSummaries.filter(
            (t) =>
                t.no.toLowerCase().includes(q) ||
                t.client.toLowerCase().includes(q) ||
                t.type.toLowerCase().includes(q) ||
                t.lawyer.toLowerCase().includes(q),
        );
    }, [ticketSummaries, q]);

    const filteredSessionSummaries = useMemo(() => {
        if (!q) return sessionSummaries;
        return sessionSummaries.filter(
            (c) =>
                c.ref.toLowerCase().includes(q) ||
                (c.ticketNo && c.ticketNo.toLowerCase().includes(q)) ||
                c.client.toLowerCase().includes(q) ||
                c.lawyer.toLowerCase().includes(q),
        );
    }, [sessionSummaries, q]);

    const filteredAppointments = useMemo(() => {
        if (!q) return appointments;
        return appointments.filter(
            (a) =>
                a.ref.toLowerCase().includes(q) ||
                a.client.toLowerCase().includes(q) ||
                a.lawyer.toLowerCase().includes(q) ||
                a.channel.toLowerCase().includes(q),
        );
    }, [appointments, q]);

    const filteredHistory = useMemo(() => {
        if (!q) return approvedHistory;
        return approvedHistory.filter(
            (h) =>
                (h.ref && h.ref.toLowerCase().includes(q)) ||
                (h.client && h.client.toLowerCase().includes(q)) ||
                (h.type && h.type.toLowerCase().includes(q)),
        );
    }, [approvedHistory, q]);

    // رابط العرض الكامل لكل فئة
    const getFullLink = (item: any, category: ItemCategory): string => {
        switch (category) {
            case 'track':
                return `/admin/tickets/${encodeURIComponent(item.no)}`;
            case 'summary':
            case 'history':
                return `/admin/summary/${encodeURIComponent(item.no || item.ref)}`;
            case 'session':
                return `/admin/consult?ref=${encodeURIComponent(item.ref)}`;
            case 'appointment':
                return `/admin/consult-requests?ref=${encodeURIComponent(item.ref)}`;
            default:
                return '/admin/dashboard';
        }
    };

    // رندر أزرار الإجراءات الموحدة
    const renderRowActions = (item: any, category: ItemCategory) => {
        // سجلّ النتائج والملخّصات للاطّلاع وحده: المعاينة والاستعراض بلا موافقة ولا رفض.
        // كان فيه اعتمادٌ لنتيجةٍ «بانتظار الإدارة» (pending_admin) — مصدرها الوحيد اعتماد المحامي للنتيجة، وحُذف (2026-09-19)
        if (category === 'history') {
            return (
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, justifyContent: 'center' }}>
                    <button
                        type="button"
                        style={{
                            height: 28,
                            padding: '0 10px',
                            borderRadius: 6,
                            background: 'rgba(37, 99, 235, 0.08)',
                            color: '#2563eb',
                            border: '1px solid rgba(37, 99, 235, 0.25)',
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            gap: 5,
                            fontSize: 12,
                            fontWeight: 600,
                            cursor: 'pointer',
                            boxShadow: 'none',
                            transition: 'none',
                            transform: 'none',
                        }}
                        onClick={(e) => {
                            e.preventDefault();
                            e.stopPropagation();
                            openModal('view', item, category);
                        }}
                        title="معاينة واستعراض تفاصيل الملف المعتمد"
                        aria-label="معاينة واستعراض تفاصيل الملف المعتمد"
                    >
                        <Icon name="eye" />
                        <span>معاينة</span>
                    </button>
                    <Link
                        href={`/admin/summary/${encodeURIComponent(item.ref || item.no)}`}
                        style={{
                            width: 28,
                            height: 28,
                            borderRadius: 6,
                            border: '1px solid var(--border)',
                            background: 'var(--paper)',
                            color: 'var(--muted)',
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            textDecoration: 'none',
                            fontSize: 12,
                        }}
                        title="فتح صفحة الملخص الكاملة"
                        aria-label="فتح صفحة الملخص الكاملة"
                    >
                        <Icon name="link" />
                    </Link>
                </div>
            );
        }

        return (
            <div style={{ display: 'flex', alignItems: 'center', gap: 6, justifyContent: 'center' }}>
                {/* 1. زر موافقة (أخضر) */}
                <button
                    type="button"
                    style={{
                        width: 30,
                        height: 30,
                        borderRadius: 6,
                        background: 'rgba(16, 185, 129, 0.12)',
                        color: '#059669',
                        border: '1px solid rgba(16, 185, 129, 0.3)',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        cursor: 'pointer',
                        boxShadow: 'none',
                        transition: 'none',
                        transform: 'none',
                        padding: 0,
                    }}
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        openModal('approve', item, category);
                    }}
                    title="موافقة واعتماد"
                    aria-label="موافقة واعتماد"
                >
                    <Icon name="check" />
                </button>

                {/* 2. زر رفض (أحمر) */}
                <button
                    type="button"
                    style={{
                        width: 30,
                        height: 30,
                        borderRadius: 6,
                        background: 'rgba(239, 68, 68, 0.1)',
                        color: '#dc2626',
                        border: '1px solid rgba(239, 68, 68, 0.25)',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        cursor: 'pointer',
                        boxShadow: 'none',
                        transition: 'none',
                        transform: 'none',
                        padding: 0,
                    }}
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        openModal('reject', item, category);
                    }}
                    title="رفض وإعادة الملف"
                    aria-label="رفض وإعادة الملف"
                >
                    <Icon name="close" />
                </button>

                {/* 3. زر عرض (أزرق) */}
                <button
                    type="button"
                    style={{
                        width: 30,
                        height: 30,
                        borderRadius: 6,
                        background: 'rgba(37, 99, 235, 0.1)',
                        color: '#2563eb',
                        border: '1px solid rgba(37, 99, 235, 0.25)',
                        display: 'inline-flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        cursor: 'pointer',
                        boxShadow: 'none',
                        transition: 'none',
                        transform: 'none',
                        padding: 0,
                    }}
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        openModal('view', item, category);
                    }}
                    title="عرض واستعراض التفاصيل"
                    aria-label="عرض واستعراض التفاصيل"
                >
                    <Icon name="eye" />
                </button>

                {/* 4. زر تنسيق في المحرر القانوني (لملخصات التذاكر والجلسات غير المعتمدة) */}
                {(category === 'summary' || category === 'session') && (
                    <Link
                        href={`/admin/editor/create?importType=${category === 'summary' ? 'ticket_summary' : 'session_summary'}&id=${item.summaryId || item.id}`}
                        style={{
                            width: 30,
                            height: 30,
                            borderRadius: 6,
                            background: 'rgba(14, 92, 156, 0.1)',
                            color: '#0e5c9c',
                            border: '1px solid rgba(14, 92, 156, 0.25)',
                            display: 'inline-flex',
                            alignItems: 'center',
                            justifyContent: 'center',
                            cursor: 'pointer',
                            textDecoration: 'none',
                            padding: 0,
                        }}
                        title="تنسيق وصياغة في المحرر القانوني ⚖️"
                        aria-label="تنسيق وصياغة في المحرر القانوني"
                    >
                        <Icon name="edit" />
                    </Link>
                )}
            </div>
        );
    };

    const tabsList: { key: TabKey; label: string; count: number; icon?: string }[] = [
        { key: 'all', label: 'جميع المعلق', count: metricCounts.totalPending },
        { key: 'tracks', label: 'مسارات المآل', count: metricCounts.proposals, icon: '🎯' },
        { key: 'summaries', label: 'ملخصات التذاكر', count: metricCounts.summaries, icon: '📄' },
        { key: 'sessions', label: 'محاضر الجلسات', count: metricCounts.sessions, icon: '⚖️' },
        { key: 'appointments', label: 'المواعيد', count: metricCounts.appointments, icon: '📅' },
        { key: 'history', label: 'سجل المعتمد والنتائج', count: metricCounts.history, icon: '🏛️' },
    ];

    return (
        <div className="approvals-hub" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
            {/* ── 1. قسم مركز الاعتمادات والقرارات الإدارية (حجم مصغر وراقي وبلا شادو) ── */}
            <div
                className="card"
                style={{
                    background: 'var(--paper)',
                    border: '1px solid var(--border)',
                    borderRadius: 10,
                    padding: '12px 18px',
                    boxShadow: 'none',
                    marginBottom: 0,
                }}
            >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 10 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                        <div
                            style={{
                                width: 32,
                                height: 32,
                                borderRadius: 8,
                                background: 'rgba(217, 119, 6, 0.12)',
                                color: '#d97706',
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'center',
                                fontSize: 15,
                            }}
                        >
                            <Icon name="check" />
                        </div>
                        <div>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <h2 style={{ margin: 0, fontSize: 15.5, fontWeight: 700, color: 'var(--text)' }}>
                                    مركز الاعتمادات والقرارات الإدارية
                                </h2>
                                {metricCounts.totalPending > 0 ? (
                                    <Badge text={`${metricCounts.totalPending} معلق`} tone="b-amber" />
                                ) : (
                                    <Badge text="مكتمل" tone="b-green" />
                                )}
                            </div>
                            <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--muted)' }}>
                                المصادقة الإدارية العليا لمسارات المآل ومخرجات العمل القانوني والمواعيد قبل نشرها للعميل.
                            </p>
                        </div>
                    </div>

                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <Link
                            href="/admin/dashboard"
                            className="btn soft sm"
                            style={{ boxShadow: 'none', transition: 'none', transform: 'none', height: 30, fontSize: 12 }}
                        >
                            <Icon name="home" /> الرئيسية
                        </Link>
                        <Link
                            href="/admin/tickets"
                            className="btn soft sm"
                            style={{ boxShadow: 'none', transition: 'none', transform: 'none', height: 30, fontSize: 12 }}
                        >
                            <Icon name="folder" /> كل التذاكر
                        </Link>
                    </div>
                </div>
            </div>

            {/* ── 2. شريط التبويبات والبحث (ثبات مطلق 100% بدون أي انميشن وبلا شادو وبلا حركة) ── */}
            <div
                style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    flexWrap: 'wrap',
                    gap: 8,
                }}
            >
                {/* التبويبات المستقرة تماماً بدون كلاس btn أو انميشن */}
                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 4,
                        background: 'var(--paper-2)',
                        padding: 3,
                        borderRadius: 8,
                        border: '1px solid var(--border)',
                        boxShadow: 'none',
                        flexWrap: 'wrap',
                    }}
                >
                    {tabsList.map((tab) => {
                        const isActive = activeTab === tab.key;
                        return (
                            <button
                                key={tab.key}
                                type="button"
                                onClick={() => setActiveTab(tab.key)}
                                style={{
                                    height: 30,
                                    padding: '0 11px',
                                    fontSize: 12,
                                    fontWeight: 600,
                                    borderRadius: 6,
                                    cursor: 'pointer',
                                    border: isActive ? '1px solid var(--primary)' : '1px solid transparent',
                                    background: isActive ? 'var(--primary)' : 'transparent',
                                    color: isActive ? '#fff' : 'var(--text)',
                                    boxShadow: 'none',
                                    transition: 'none',
                                    transform: 'none',
                                    outline: 'none',
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: 5,
                                    userSelect: 'none',
                                    lineHeight: '1',
                                }}
                            >
                                {tab.icon && <span style={{ fontSize: 13 }}>{tab.icon}</span>}
                                <span>{tab.label}</span>
                                <span
                                    style={{
                                        fontSize: 11,
                                        fontWeight: 700,
                                        padding: '1px 6px',
                                        borderRadius: 10,
                                        background: isActive ? 'rgba(255, 255, 255, 0.22)' : 'var(--paper)',
                                        color: isActive ? '#fff' : 'var(--muted)',
                                        lineHeight: '1.2',
                                    }}
                                >
                                    {tab.count}
                                </span>
                            </button>
                        );
                    })}
                </div>

                {/* حقل البحث السريع */}
                <div style={{ position: 'relative', width: 240 }}>
                    <input
                        type="text"
                        className="input"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="بحث بالرقم، العميل، أو النوع..."
                        style={{
                            height: 32,
                            fontSize: 12,
                            paddingRight: 30,
                            boxShadow: 'none',
                            border: '1px solid var(--border)',
                            borderRadius: 6,
                            background: 'var(--paper)',
                        }}
                    />
                    <span
                        style={{
                            position: 'absolute',
                            right: 9,
                            top: '50%',
                            transform: 'translateY(-50%)',
                            color: 'var(--muted)',
                            pointerEvents: 'none',
                            display: 'flex',
                            alignItems: 'center',
                        }}
                    >
                        <Icon name="search" />
                    </span>
                </div>
            </div>

            {/* ── 3. حاوية الجداول التنفيذية الموحدة (ثبات هيكلي تام وارتفاع متزن يمنع القفزات) ── */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12, minHeight: 400 }}>
                {/* (أ) جدول مقترحات مسار مآل التذاكر */}
                {(activeTab === 'tracks' || (activeTab === 'all' && filteredProposals.length > 0)) && (
                    <div className="card" style={{ overflow: 'hidden', boxShadow: 'none' }}>
                        <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <h3>🎯 مقترحات مسار مآل التذاكر</h3>
                                <Badge text={String(filteredProposals.length)} tone={filteredProposals.length > 0 ? 'b-amber' : 'b-grey'} />
                            </div>
                            <span className="sub" style={{ fontSize: 12 }}>
                                القرارات الأربعة (استشارة / قضية / تنفيذ / إغلاق) — لن تنشر للعميل إلا باعتماد الإدارة
                            </span>
                        </div>

                        <div className="card-b t-wrap" style={{ padding: 0 }}>
                            {filteredProposals.length > 0 ? (
                                <table className="tbl" style={{ width: '100%', minWidth: 920, borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: 'var(--paper-2)', borderBottom: '1px solid var(--border)' }}>
                                            <th style={{ padding: '12px 16px', textAlign: 'right', minWidth: 130 }}>المرجع والتذكرة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 150 }}>العميل والتواصل</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>الموضوع والقسم</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>مقدم المقترح</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>المسار المقترح</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 200 }}>توصية AI والتسبيب</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 100 }}>التاريخ</th>
                                            <th style={{ padding: '12px 16px', textAlign: 'center', minWidth: 150 }}>الإجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredProposals.map((p) => (
                                            <tr key={p.no} style={{ borderBottom: '1px solid var(--border)' }}>
                                                <td style={{ padding: '12px 16px' }}>
                                                    <Link href={`/admin/tickets/${encodeURIComponent(p.no)}`} style={{ fontWeight: 700, color: 'var(--accent)' }}>
                                                        {p.no}
                                                    </Link>
                                                    {p.priority && (
                                                        <div style={{ marginTop: 4 }}>
                                                            <span style={{ fontSize: 11, color: 'var(--muted)' }}>الأولوية: {p.priority}</span>
                                                        </div>
                                                    )}
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{p.client}</b>
                                                    {p.clientPhone && <div style={{ fontSize: 11.5, color: 'var(--muted)', direction: 'ltr', textAlign: 'right' }}>{p.clientPhone}</div>}
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <div style={{ fontWeight: 600 }}>{p.type}</div>
                                                    <span style={{ fontSize: 11, color: 'var(--muted)' }}>{p.department || 'عام'}</span>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{p.proposedBy}</b>
                                                    <div style={{ fontSize: 11, color: 'var(--muted)' }}>{p.proposedByRole || 'المسؤول'}</div>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <Badge text={p.proposedTrackLabel} tone={p.proposedTrackTone} />
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    {p.aiSuggestedTrackLabel && (
                                                        <div style={{ marginBottom: 4 }}>
                                                            <span
                                                                style={{
                                                                    fontSize: 11,
                                                                    color: '#4338ca',
                                                                    background: 'rgba(99, 102, 241, 0.1)',
                                                                    padding: '1px 6px',
                                                                    borderRadius: 4,
                                                                    border: '1px solid rgba(99, 102, 241, 0.25)',
                                                                }}
                                                            >
                                                                ✨ AI: {p.aiSuggestedTrackLabel}
                                                            </span>
                                                        </div>
                                                    )}
                                                    <div
                                                        style={{
                                                            fontSize: 12,
                                                            color: 'var(--text)',
                                                            background: 'var(--paper)',
                                                            padding: '6px 8px',
                                                            borderRadius: 6,
                                                            lineHeight: 1.4,
                                                            maxWidth: 240,
                                                            border: '1px solid var(--border)',
                                                        }}
                                                        title={p.proposedTrackReason}
                                                    >
                                                        {p.proposedTrackReason.length > 70 ? `${p.proposedTrackReason.slice(0, 70)}...` : p.proposedTrackReason}
                                                    </div>
                                                </td>

                                                <td style={{ padding: '12px 14px', fontSize: 12, color: 'var(--muted)' }}>
                                                    {p.since || 'الآن'}
                                                </td>

                                                <td style={{ padding: '12px 16px' }}>
                                                    {renderRowActions(p, 'track')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <div className="empty" style={{ padding: '28px 0' }}>
                                    <Icon name="check" />
                                    <b>لا توجد مقترحات مسارات مآل بانتظار الاعتماد</b>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* (ب) جدول ملخصات التذاكر والرأي القانوني */}
                {(activeTab === 'summaries' || (activeTab === 'all' && filteredTicketSummaries.length > 0)) && (
                    <div className="card" style={{ overflow: 'hidden', boxShadow: 'none' }}>
                        <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <h3>📄 ملخصات التذاكر والرأي القانوني المبدئي</h3>
                                <Badge text={String(filteredTicketSummaries.length)} tone={filteredTicketSummaries.length > 0 ? 'b-amber' : 'b-grey'} />
                            </div>
                            <span className="sub" style={{ fontSize: 12 }}>
                                اعتمدها المستشار — اعتمادك النهائي ينشر الرأي القانوني المبدئي للعميل
                            </span>
                        </div>

                        <div className="card-b t-wrap" style={{ padding: 0 }}>
                            {filteredTicketSummaries.length > 0 ? (
                                <table className="tbl" style={{ width: '100%', minWidth: 920, borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: 'var(--paper-2)', borderBottom: '1px solid var(--border)' }}>
                                            <th style={{ padding: '12px 16px', textAlign: 'right', minWidth: 130 }}>رقم التذكرة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 150 }}>العميل</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>النوع والقسم</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>المستشار المسند</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>حالة المراجعة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 200 }}>ملخص الوقائع والرأي</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 100 }}>التاريخ</th>
                                            <th style={{ padding: '12px 16px', textAlign: 'center', minWidth: 150 }}>الإجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredTicketSummaries.map((t) => (
                                            <tr key={t.no} style={{ borderBottom: '1px solid var(--border)' }}>
                                                <td style={{ padding: '12px 16px' }}>
                                                    <Link href={`/admin/summary/${encodeURIComponent(t.no)}`} style={{ fontWeight: 700, color: 'var(--accent)' }}>
                                                        {t.no}
                                                    </Link>
                                                    {t.priority && (
                                                        <div style={{ marginTop: 4 }}>
                                                            <span style={{ fontSize: 11, color: 'var(--muted)' }}>الأولوية: {t.priority}</span>
                                                        </div>
                                                    )}
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{t.client}</b>
                                                    {t.clientPhone && <div style={{ fontSize: 11.5, color: 'var(--muted)', direction: 'ltr', textAlign: 'right' }}>{t.clientPhone}</div>}
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <div style={{ fontWeight: 600 }}>{t.type}</div>
                                                    <span style={{ fontSize: 11, color: 'var(--muted)' }}>{t.department || 'عام'}</span>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{t.lawyer}</b>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <Badge text="بانتظار اعتماد الإدارة" tone="b-amber" />
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <div
                                                        style={{
                                                            fontSize: 12,
                                                            color: 'var(--text)',
                                                            background: 'var(--paper)',
                                                            padding: '6px 8px',
                                                            borderRadius: 6,
                                                            lineHeight: 1.4,
                                                            maxWidth: 240,
                                                            border: '1px solid var(--border)',
                                                        }}
                                                        title={t.facts || t.caseSummary || ''}
                                                    >
                                                        {t.facts ? `${t.facts.slice(0, 65)}...` : t.caseSummary ? `${t.caseSummary.slice(0, 65)}...` : 'ملخص قانوني جاهز'}
                                                    </div>
                                                </td>

                                                <td style={{ padding: '12px 14px', fontSize: 12, color: 'var(--muted)' }}>
                                                    {t.since || 'الآن'}
                                                </td>

                                                <td style={{ padding: '12px 16px' }}>
                                                    {renderRowActions(t, 'summary')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <div className="empty" style={{ padding: '28px 0' }}>
                                    <Icon name="check" />
                                    <b>لا ملخصات تذاكر تنتظر اعتماد الإدارة حالياً</b>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* (ج) جدول محاضر الجلسات ونتائج الاستشارات */}
                {(activeTab === 'sessions' || (activeTab === 'all' && filteredSessionSummaries.length > 0)) && (
                    <div className="card" style={{ overflow: 'hidden', boxShadow: 'none' }}>
                        <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <h3>⚖️ محاضر الجلسات ونتائج الاستشارات</h3>
                                <Badge text={String(filteredSessionSummaries.length)} tone={filteredSessionSummaries.length > 0 ? 'b-amber' : 'b-grey'} />
                            </div>
                            <span className="sub" style={{ fontSize: 12 }}>
                                اعتمدها المستشار — اعتمادك يرسل محضر الجلسة والنتيجة للعميل ويكمل ملف الاستشارة
                            </span>
                        </div>

                        <div className="card-b t-wrap" style={{ padding: 0 }}>
                            {filteredSessionSummaries.length > 0 ? (
                                <table className="tbl" style={{ width: '100%', minWidth: 920, borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: 'var(--paper-2)', borderBottom: '1px solid var(--border)' }}>
                                            <th style={{ padding: '12px 16px', textAlign: 'right', minWidth: 140 }}>مرجع الاستشارة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 150 }}>العميل</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>القناة والجلسة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>المستشار</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>الحالة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 200 }}>موجز المحضر</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 100 }}>التاريخ</th>
                                            <th style={{ padding: '12px 16px', textAlign: 'center', minWidth: 150 }}>الإجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredSessionSummaries.map((c) => (
                                            <tr key={c.ref} style={{ borderBottom: '1px solid var(--border)' }}>
                                                <td style={{ padding: '12px 16px' }}>
                                                    <Link href={`/admin/consult?ref=${encodeURIComponent(c.ref)}`} style={{ fontWeight: 700, color: 'var(--accent)' }}>
                                                        {c.ref}
                                                    </Link>
                                                    {c.ticketNo && <div style={{ fontSize: 11, color: 'var(--muted)' }}>تذكرة: {c.ticketNo}</div>}
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{c.client}</b>
                                                    {c.clientPhone && <div style={{ fontSize: 11.5, color: 'var(--muted)', direction: 'ltr', textAlign: 'right' }}>{c.clientPhone}</div>}
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{c.channel || 'مرئية'}</b>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{c.lawyer}</b>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <Badge text="بانتظار اعتماد المحضر" tone="b-amber" />
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <div
                                                        style={{
                                                            fontSize: 12,
                                                            color: 'var(--text)',
                                                            background: 'var(--paper)',
                                                            padding: '6px 8px',
                                                            borderRadius: 6,
                                                            lineHeight: 1.4,
                                                            maxWidth: 240,
                                                            border: '1px solid var(--border)',
                                                        }}
                                                        title={c.summary || ''}
                                                    >
                                                        {c.summary ? `${c.summary.slice(0, 60)}...` : 'محضر جلسة استشارة قانونية'}
                                                    </div>
                                                </td>

                                                <td style={{ padding: '12px 14px', fontSize: 12, color: 'var(--muted)' }}>
                                                    {c.since || 'الآن'}
                                                </td>

                                                <td style={{ padding: '12px 16px' }}>
                                                    {renderRowActions(c, 'session')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <div className="empty" style={{ padding: '28px 0' }}>
                                    <Icon name="check" />
                                    <b>لا محاضر جلسات تنتظر الاعتماد حالياً</b>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* (د) جدول مواعيد الاستشارات المحجوزة */}
                {(activeTab === 'appointments' || (activeTab === 'all' && filteredAppointments.length > 0)) && (
                    <div className="card" style={{ overflow: 'hidden', boxShadow: 'none' }}>
                        <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <h3>📅 مواعيد الاستشارات المحجوزة من الموظفين</h3>
                                <Badge text={String(filteredAppointments.length)} tone={filteredAppointments.length > 0 ? 'b-amber' : 'b-grey'} />
                            </div>
                            <span className="sub" style={{ fontSize: 12 }}>
                                حجزها الموظف وتحتاج مصادقة الإدارة — يمكنك تعديل الوقت أو المستشار ثم الاعتماد
                            </span>
                        </div>

                        <div className="card-b t-wrap" style={{ padding: 0 }}>
                            {filteredAppointments.length > 0 ? (
                                <table className="tbl" style={{ width: '100%', minWidth: 920, borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: 'var(--paper-2)', borderBottom: '1px solid var(--border)' }}>
                                            <th style={{ padding: '12px 16px', textAlign: 'right', minWidth: 140 }}>مرجع الاستشارة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 150 }}>العميل</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 140 }}>اليوم والموعد</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 120 }}>نوع القناة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>المستشار المخصص</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 130 }}>الحالة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 100 }}>منذ</th>
                                            <th style={{ padding: '12px 16px', textAlign: 'center', minWidth: 150 }}>الإجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredAppointments.map((a) => (
                                            <tr key={a.ref} style={{ borderBottom: '1px solid var(--border)' }}>
                                                <td style={{ padding: '12px 16px' }}>
                                                    <Link href={`/admin/consult-requests?ref=${encodeURIComponent(a.ref)}`} style={{ fontWeight: 700, color: 'var(--accent)' }}>
                                                        {a.ref}
                                                    </Link>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{a.client}</b>
                                                    {a.clientPhone && <div style={{ fontSize: 11.5, color: 'var(--muted)', direction: 'ltr', textAlign: 'right' }}>{a.clientPhone}</div>}
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{a.day || '—'}</b>
                                                    <div style={{ fontSize: 11.5, color: 'var(--muted)' }}>الساعة: {a.time || '—'}</div>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <span className="doc-chip" style={{ fontSize: 11.5 }}>
                                                        {a.channel || 'مرئية'}
                                                    </span>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{a.lawyer}</b>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <Badge text="بانتظار اعتماد الموعد" tone="b-amber" />
                                                </td>

                                                <td style={{ padding: '12px 14px', fontSize: 12, color: 'var(--muted)' }}>
                                                    {a.since || 'الآن'}
                                                </td>

                                                <td style={{ padding: '12px 16px' }}>
                                                    {renderRowActions(a, 'appointment')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <div className="empty" style={{ padding: '28px 0' }}>
                                    <Icon name="check" />
                                    <b>لا مواعيد استشارات بانتظار الاعتماد حالياً</b>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {/* (هـ) جدول سجل الملخصات والنتائج التاريخية ومسار المراجعة (بدون FlowLine وبلا شادو) */}
                {(activeTab === 'all' || activeTab === 'history') && (
                    <div className="card" style={{ overflow: 'hidden', boxShadow: 'none' }}>
                        <div className="card-h" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                                <h3>🏛️ سجل الملخصات والنتائج المعتمدة</h3>
                                <Badge text={String(filteredHistory.length)} tone={filteredHistory.length > 0 ? 'b-blue' : 'b-grey'} />
                            </div>
                            <span className="sub" style={{ fontSize: 12 }}>
                                سجل مخرجات العمل القانوني والقرارات الإدارية والنتائج المعتمدة
                            </span>
                        </div>

                        <div className="card-b t-wrap" style={{ padding: 0 }}>
                            {filteredHistory.length > 0 ? (
                                <table className="tbl" style={{ width: '100%', minWidth: 920, borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ background: 'var(--paper-2)', borderBottom: '1px solid var(--border)' }}>
                                            <th style={{ padding: '12px 16px', textAlign: 'right', minWidth: 140 }}>رقم الملف / التذكرة</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 160 }}>العميل والتواصل</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 150 }}>نوع المعاملة والقسم</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 150 }}>حالة الاعتماد</th>
                                            <th style={{ padding: '12px 14px', textAlign: 'right', minWidth: 120 }}>التاريخ</th>
                                            <th style={{ padding: '12px 16px', textAlign: 'center', minWidth: 150 }}>الإجراءات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filteredHistory.map((s) => (
                                            <tr key={s.ref || s.id} style={{ borderBottom: '1px solid var(--border)' }}>
                                                <td style={{ padding: '12px 16px' }}>
                                                    <Link href={`/admin/summary/${encodeURIComponent(s.ref!)}`} style={{ fontWeight: 700, color: 'var(--accent)' }}>
                                                        {s.ref}
                                                    </Link>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <b>{s.client || '—'}</b>
                                                    {s.clientPhone && <div style={{ fontSize: 11.5, color: 'var(--muted)', direction: 'ltr', textAlign: 'right' }}>{s.clientPhone}</div>}
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    <div style={{ fontWeight: 600 }}>{s.type || 'استشارة / قضية'}</div>
                                                    <span style={{ fontSize: 11, color: 'var(--muted)' }}>{s.department || 'عام'}</span>
                                                </td>

                                                <td style={{ padding: '12px 14px' }}>
                                                    {s.resultStatus === 'approved' ? (
                                                        <Badge text="مكتملة — أُرسلت النتيجة" tone="b-green" />
                                                    ) : s.approved ? (
                                                        <Badge text="الملخص معتمد" tone="b-cyan" />
                                                    ) : (
                                                        <Badge text={s.lawyerApproved ? 'معتمد من المستشار' : 'قيد الإعداد'} tone="b-blue" />
                                                    )}
                                                </td>

                                                <td style={{ padding: '12px 14px', fontSize: 12, color: 'var(--muted)' }}>
                                                    {s.createdAtFormatted || 'مؤخراً'}
                                                </td>

                                                <td style={{ padding: '12px 16px' }}>
                                                    {renderRowActions(s, 'history')}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            ) : (
                                <div className="empty" style={{ padding: '28px 0' }}>
                                    <Icon name="doc" />
                                    <b>لا ملخصات في السجل</b>
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* ── 4. النوافذ التأكيدية المنبثقة (عبر createPortal إلى body لتخرج فورياً في مقدمة الشاشة) ── */}
            {mounted && modalState && createPortal(
                <div
                    style={{
                        position: 'fixed',
                        inset: 0,
                        background: 'rgba(15, 23, 42, 0.72)',
                        backdropFilter: 'blur(4px)',
                        zIndex: 999999,
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        padding: 16,
                    }}
                    onClick={closeModal}
                >
                    <div
                        style={{
                            background: 'var(--paper)',
                            border: '1px solid var(--border)',
                            borderRadius: 14,
                            maxWidth: modalState.action === 'view' ? 620 : 520,
                            width: '100%',
                            overflow: 'hidden',
                            boxShadow: 'none',
                            position: 'relative',
                            zIndex: 1000000,
                        }}
                        onClick={(e) => e.stopPropagation()}
                    >
                        {/* رأس النافذة */}
                        <div
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                padding: '14px 18px',
                                borderBottom: '1px solid var(--border)',
                                background: 'var(--paper-2)',
                            }}
                        >
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                                <div
                                    style={{
                                        width: 32,
                                        height: 32,
                                        borderRadius: 8,
                                        display: 'flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        background:
                                            modalState.action === 'approve'
                                                ? 'rgba(16, 185, 129, 0.15)'
                                                : modalState.action === 'reject'
                                                    ? 'rgba(239, 68, 68, 0.15)'
                                                    : 'rgba(37, 99, 235, 0.15)',
                                        color:
                                            modalState.action === 'approve'
                                                ? '#059669'
                                                : modalState.action === 'reject'
                                                    ? '#dc2626'
                                                    : '#2563eb',
                                    }}
                                >
                                    <Icon
                                        name={
                                            modalState.action === 'approve'
                                                ? 'check'
                                                : modalState.action === 'reject'
                                                    ? 'close'
                                                    : 'eye'
                                        }
                                    />
                                </div>
                                <h3 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>
                                    {modalState.action === 'approve' && 'تأكيد الموافقة والاعتماد الرسمي'}
                                    {modalState.action === 'reject' && 'تأكيد رفض الطلب / الإعادة للمراجعة'}
                                    {modalState.action === 'view' && 'استعراض ومعاينة تفاصيل البند'}
                                </h3>
                            </div>

                            <button
                                type="button"
                                className="btn-icon"
                                onClick={closeModal}
                                style={{ cursor: 'pointer', border: 'none', background: 'transparent', color: 'var(--muted)', fontSize: 16 }}
                            >
                                ✕
                            </button>
                        </div>

                        {/* جسم النافذة */}
                        <div style={{ padding: '18px', maxHeight: '72vh', overflowY: 'auto' }}>
                            {/* ملخص البند المحدد */}
                            <div
                                style={{
                                    background: 'var(--paper-2)',
                                    border: '1px solid var(--border)',
                                    borderRadius: 8,
                                    padding: '10px 14px',
                                    marginBottom: 14,
                                }}
                            >
                                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                                    <span style={{ fontSize: 12, color: 'var(--muted)' }}>الرقم المرجعي:</span>
                                    <strong style={{ fontSize: 13, color: 'var(--accent)' }}>
                                        {modalState.item?.no || modalState.item?.ref || '—'}
                                    </strong>
                                </div>
                                <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: 4 }}>
                                    <span style={{ fontSize: 12, color: 'var(--muted)' }}>العميل:</span>
                                    <strong style={{ fontSize: 13 }}>{modalState.item?.client || '—'}</strong>
                                </div>
                                <div style={{ display: 'flex', justifyContent: 'space-between' }}>
                                    <span style={{ fontSize: 12, color: 'var(--muted)' }}>النوع / الموضوع:</span>
                                    <span style={{ fontSize: 12.5, fontWeight: 600 }}>{modalState.item?.type || modalState.item?.channel || 'عام'}</span>
                                </div>
                            </div>

                            {/* 1. محتوى نافذة الموافقة */}
                            {modalState.action === 'approve' && (
                                <div>
                                    <p style={{ fontSize: 13, color: 'var(--text)', lineHeight: 1.5, margin: '0 0 14px 0' }}>
                                        هل أنت متأكد من <strong>الموافقة والاعتماد الرسمي</strong> لهذا البند ونشر نتيجته المعتمدة؟
                                    </p>

                                    {modalState.category === 'track' && (
                                        <div
                                            style={{
                                                background: 'rgba(217, 119, 6, 0.08)',
                                                border: '1px solid rgba(217, 119, 6, 0.25)',
                                                borderRadius: 6,
                                                padding: '10px 12px',
                                                fontSize: 12,
                                                color: '#92400e',
                                                lineHeight: 1.5,
                                            }}
                                        >
                                            سيتم اعتماد مسار <strong>({modalState.item?.proposedTrackLabel})</strong> رسمياً، وإنشاء ملف القضية أو التنفيذ أو فتح حجز الاستشارة وإشعار العميل فورياً بالتسبيب الحقيقي.
                                        </div>
                                    )}

                                    {/* سبب الإغلاق من الكتالوج — كان «إغلاق» يُعتمد بسببٍ افتراضيّ صامت */}
                                    {needsClosureCode && (
                                        <div className="field" style={{ marginTop: 12 }}>
                                            <label style={{ fontSize: 12, fontWeight: 700 }}>تصنيف سبب الإغلاق</label>
                                            <select className="input" value={closureCode} onChange={(e) => setClosureCode(e.target.value)} style={{ width: '100%', fontSize: 12.5 }}>
                                                <option value="">— اختر سبب الإغلاق —</option>
                                                {closureReasons.map((r) => <option key={r.code} value={r.code}>{r.label}</option>)}
                                            </select>
                                        </div>
                                    )}

                                    {/* استشارةٌ قائمة: مانعٌ بلا تجاوز — القرار بعد الجلسة */}
                                    {trackItem?.consultBlocker && (
                                        <div style={{ marginTop: 12, fontSize: 12, lineHeight: 1.6, color: '#78350f', fontWeight: 700 }}>
                                            {trackItem.consultBlocker}
                                        </div>
                                    )}

                                    {/* مانع الملخّص من الحارس نفسه، والمسار السريع للإدارة بسببٍ مدوَّن */}
                                    {trackItem?.outcomeBlocker && !trackItem?.consultBlocker && (
                                        <div style={{ marginTop: 12, fontSize: 12, lineHeight: 1.6 }}>
                                            <div style={{ color: '#991b1b', marginBottom: 6 }}>{trackItem.outcomeBlocker}</div>
                                            {trackItem.inheritedWaiver ? (
                                                <div style={{ color: 'var(--muted)' }}>سبب التجاوز الذي دوّنته عند رفع المقترح: «{trackItem.inheritedWaiver}»</div>
                                            ) : (
                                                <>
                                                    <label style={{ fontWeight: 700, display: 'block', marginBottom: 4 }}>سبب المضيّ بلا ملخّصٍ معتمد ({WAIVER_MIN} أحرف على الأقل)</label>
                                                    <textarea
                                                        className="input"
                                                        rows={3}
                                                        value={waiver}
                                                        onChange={(e) => setWaiver(e.target.value)}
                                                        placeholder="يُحفظ في سجلّ الرحلة وسجلّ التدقيق…"
                                                        style={{ width: '100%', padding: '8px 10px', fontSize: 12.5, borderRadius: 6, border: '1px solid var(--border)', boxSizing: 'border-box' }}
                                                    />
                                                </>
                                            )}
                                        </div>
                                    )}

                                    {modalState.category === 'summary' && (
                                        <div
                                            style={{
                                                background: 'rgba(37, 99, 235, 0.08)',
                                                border: '1px solid rgba(37, 99, 235, 0.25)',
                                                borderRadius: 6,
                                                padding: '10px 12px',
                                                fontSize: 12,
                                                color: '#1e40af',
                                                lineHeight: 1.5,
                                            }}
                                        >
                                            سيتم نشر الرأي القانوني المبدئي للتذكرة في صندوق محادثة العميل ونقله لمرحلة اتخاذ قرار المآل.
                                        </div>
                                    )}

                                    {modalState.category === 'session' && (
                                        <div
                                            style={{
                                                background: 'rgba(59, 130, 246, 0.08)',
                                                border: '1px solid rgba(59, 130, 246, 0.25)',
                                                borderRadius: 6,
                                                padding: '10px 12px',
                                                fontSize: 12,
                                                color: '#1e40af',
                                                lineHeight: 1.5,
                                            }}
                                        >
                                            سيتم اعتماد محضر وخلاصة جلسة الاستشارة رسمياً ونشر التقرير للموكل وإشعار أطراف الجلسة.
                                        </div>
                                    )}

                                    {modalState.category === 'appointment' && (
                                        <div
                                            style={{
                                                background: 'rgba(16, 185, 129, 0.08)',
                                                border: '1px solid rgba(16, 185, 129, 0.25)',
                                                borderRadius: 6,
                                                padding: '10px 12px',
                                                fontSize: 12,
                                                color: '#065f46',
                                                lineHeight: 1.5,
                                            }}
                                        >
                                            سيتم اعتماد وتثبيت موعد الاستشارة المقترح رسمياً، وإشعار العميل والمستشار بالموعد النهائي.
                                        </div>
                                    )}
                                </div>
                            )}

                            {/* 2. محتوى نافذة الرفض */}
                            {modalState.action === 'reject' && (
                                <div>
                                    <p style={{ fontSize: 12.5, color: 'var(--text)', margin: '0 0 8px 0' }}>
                                        يرجى كتابة <strong>سبب الرفض والتوجيهات الإدارية</strong> التي ستُسجل وتصل للمستشار/الموظف لإعادة المعالجة:
                                    </p>
                                    <textarea
                                        className="input"
                                        rows={4}
                                        value={rejectReason}
                                        onChange={(e) => setRejectReason(e.target.value)}
                                        placeholder="مثال: يرجى إعادة دراسة الوقائع وإرفاق مستند العقد الأصلي قبل اعتماد المسار..."
                                        style={{
                                            width: '100%',
                                            padding: '8px 10px',
                                            fontSize: 12.5,
                                            borderRadius: 6,
                                            border: '1px solid var(--border)',
                                            boxSizing: 'border-box',
                                            boxShadow: 'none',
                                        }}
                                    />
                                </div>
                            )}

                            {/*
                              * **زرّ التنفيذ في نافذتَي الموافقة والرفض.** كانت النافذتان تحملان العنوان والشرح وحقل
                              * السبب بلا زرٍّ ينادي handleApprove/handleReject — فأزرار ✓ و✗ في كلّ التبويبات تفتح
                              * نافذةً لا تُنفّذ شيئاً، ولا يبقى للإدارة إلّا الإغلاق (وُجد 2026-09-20).
                              */}
                            {(modalState.action === 'approve' || modalState.action === 'reject') && (
                                <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 16, flexWrap: 'wrap' }}>
                                    <button type="button" className="btn sm soft" onClick={closeModal} disabled={isProcessing}>
                                        إلغاء
                                    </button>
                                    {modalState.action === 'approve' ? (
                                        <button type="button" className="btn sm" onClick={handleApprove} disabled={isProcessing || !approveReady}>
                                            <Icon name="check" /> {isProcessing ? 'جارٍ الاعتماد…' : 'تأكيد الاعتماد'}
                                        </button>
                                    ) : (
                                        <button
                                            type="button"
                                            className="btn sm"
                                            style={{ background: '#dc2626', boxShadow: 'none' }}
                                            onClick={handleReject}
                                            disabled={isProcessing || !rejectReason.trim()}
                                        >
                                            <Icon name="close" /> {isProcessing ? 'جارٍ الإرسال…' : 'تأكيد الرفض والإعادة'}
                                        </button>
                                    )}
                                </div>
                            )}

                            {/* 3. محتوى نافذة العرض التفصيلي */}
                            {modalState.action === 'view' && (
                                <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                                    {modalState.item?.proposedTrackReason && (
                                        <div>
                                            <span style={{ fontSize: 12, fontWeight: 700, color: '#d97706', display: 'block', marginBottom: 2 }}>
                                                ⚖️ التسبيب الحقيقي للمسار:
                                            </span>
                                            <div style={{ fontSize: 12, background: 'var(--paper-2)', padding: '8px 10px', borderRadius: 6, lineHeight: 1.4 }}>
                                                {modalState.item.proposedTrackReason}
                                            </div>
                                        </div>
                                    )}

                                    {modalState.item?.aiSuggestedReason && (
                                        <div>
                                            <span style={{ fontSize: 12, fontWeight: 700, color: '#4338ca', display: 'block', marginBottom: 2 }}>
                                                ✨ تحليل الذكاء الاصطناعي للمستندات:
                                            </span>
                                            <div style={{ fontSize: 12, background: 'rgba(99, 102, 241, 0.06)', padding: '8px 10px', borderRadius: 6, lineHeight: 1.4 }}>
                                                {modalState.item.aiSuggestedReason}
                                            </div>
                                        </div>
                                    )}

                                    {(modalState.item?.facts || modalState.item?.caseSummary) && (
                                        <div>
                                            <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text)', display: 'block', marginBottom: 2 }}>
                                                📄 ملخص الوقائع وموضوع الملف:
                                            </span>
                                            <div style={{ fontSize: 12, background: 'var(--paper-2)', padding: '8px 10px', borderRadius: 6, lineHeight: 1.4 }}>
                                                {modalState.item.facts || modalState.item.caseSummary}
                                            </div>
                                        </div>
                                    )}

                                    {modalState.item?.summary && (
                                        <div>
                                            <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text)', display: 'block', marginBottom: 2 }}>
                                                📝 محضر الجلسة / النتيجة:
                                            </span>
                                            <div style={{ fontSize: 12, background: 'var(--paper-2)', padding: '8px 10px', borderRadius: 6, lineHeight: 1.4 }}>
                                                {modalState.item.summary}
                                            </div>
                                        </div>
                                    )}

                                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 160px), 1fr))', gap: 8, marginTop: 4 }}>
                                        <div style={{ fontSize: 11.5, color: 'var(--muted)' }}>
                                            المسؤول: <strong style={{ color: 'var(--text)' }}>{modalState.item?.lawyer || modalState.item?.proposedBy || '—'}</strong>
                                        </div>
                                        <div style={{ fontSize: 11.5, color: 'var(--muted)' }}>
                                            التوقيت: <strong style={{ color: 'var(--text)' }}>{modalState.item?.since || modalState.item?.createdAtFormatted || 'مؤخراً'}</strong>
                                        </div>
                                    </div>
                                </div>
                            )}

                            {modalState.action === 'view' && (
                                <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
                                    {(modalState.category === 'summary' || modalState.category === 'session') && (
                                        <Link
                                            href={`/admin/editor/create?importType=${modalState.category === 'summary' ? 'ticket_summary' : 'session_summary'}&id=${modalState.item.summaryId || modalState.item.id}`}
                                            className="btn sm soft"
                                            style={{
                                                fontWeight: 700,
                                                boxShadow: 'none',
                                                transition: 'none',
                                                transform: 'none',
                                                gap: 6,
                                                background: 'rgba(14, 92, 156, 0.08)',
                                                borderColor: 'rgba(14, 92, 156, 0.3)',
                                                color: '#0e5c9c',
                                            }}
                                        >
                                            <Icon name="edit" /> تنسيق في المحرر ⚖️
                                        </Link>
                                    )}
                                    <Link
                                        href={getFullLink(modalState.item, modalState.category)}
                                        className="btn sm primary"
                                        style={{ fontWeight: 700, boxShadow: 'none', transition: 'none', transform: 'none' }}
                                    >
                                        <Icon name="link" /> فتح صفحة الملف الكاملة
                                    </Link>
                                </div>
                            )}
                        </div>
                    </div>
                </div>,
                document.body
            )}
        </div>
    );
};

export default AdminApprovals;
