import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import Modal from '@/components/babylon/Modal';
import TimeSlotPicker from '@/components/babylon/TimeSlotPicker';
import { useToast } from '@/components/babylon/Toast';
import { MEET_STATUSES, MEET_TYPES_FULL, MEET_TEMPLATES } from '@/lib/admin-data';
import { dateISOAfter, todayISO } from '@/lib/local-date';
import { meetStatusTone, attendanceLabel, fmtActualDuration, useLawyerDaySlots, type ClientDirEntry, type FullMeetingCard } from '@/lib/meeting-ui';
import { inSessionSuffix, PresenceBadge, useInSession } from '@/lib/staff-presence';
import { useServerAction } from '@/lib/use-server-action';

// واجهة إدارة الاجتماعات الحديثة — التصميم الفاخر والمطور 2026
interface Props {
  meetings: FullMeetingCard[];
  clients: ClientDirEntry[];
  lawyers: { id: number; name: string }[];
  staff?: { id: number; name: string; role?: string; label: string }[];
  // null = لم يُقَس — تُعرض «—» لا صفراً
  kpis: { decisionRate: number | null; avgMinutes: number | null };
}

const AdminMeetMgmt: React.FC<Props> = ({ meetings, clients, lawyers, staff = [], kpis }) => {
  const inSession = useInSession();
  const toast = useToast();
  const [filter, setFilter] = useState('all');
  const [open, setOpen] = useState(false);

  // بحث وفلترة تصفية متقدمة
  const [q, setQ] = useState('');
  const [lawyerF, setLawyerF] = useState('');
  const [fromD, setFromD] = useState('');
  const [toD, setToD] = useState('');
  const [showFilters, setShowFilters] = useState(false);
  const activeFiltersCount = [lawyerF, fromD, toD, q].filter(Boolean).length;

  const lawyerOpts = [...new Set(meetings.map((m) => m.lawyer).filter((l) => l && l !== '—'))];

  // حقول نموذج إنشاء اجتماع جديد
  const [title, setTitle] = useState('');
  const [type, setType] = useState(MEET_TYPES_FULL[0]);
  const [prio, setPrio] = useState('عادية');
  const [conf, setConf] = useState('عادي');
  // المشاركون من الكادر بمعرّفاتهم — الخادم يحفظهم حسابات (`meeting_participants`) لا أسماءً تُطابَق نصّاً
  const [participants, setParticipants] = useState<number[]>([]);
  const [day, setDay] = useState('');
  const [time, setTime] = useState('10:00');
  const [clientId, setClientId] = useState<number | ''>('');
  const [lawyerId, setLawyerId] = useState<number | ''>('');
  const [caseRef, setCaseRef] = useState('');
  useEffect(() => { setCaseRef(''); }, [clientId]);
  // قفلٌ موحّد للإرسال — كانت نقرتان سريعتان تُنشئان اجتماعين (وجلستي Zoom)؛ ثبت في المتصفّح 2026-09-28
  const action = useServerAction();
  // شبكة الوقت بانشغال المحامي المختار — المصدر نفسه لنافذة الدعوات (`useLawyerDaySlots`)
  const slots = useLawyerDaySlots('/admin', lawyerId, day);
  // المحامي المسؤول له صفته — لا يُعرض بين المشاركين
  const participantChoices = staff.filter((m) => m.id !== lawyerId);

  // العدّ بمفتاح الحالة من الخادم (`statusKey`) — النصّ العربيّ للعرض وحده
  const up = meetings.filter((m) => m.statusKey === 'upcoming').length;
  const live = meetings.filter((m) => m.statusKey === 'live').length;
  const done = meetings.filter((m) => m.statusKey === 'ended');
  const missed = meetings.filter((m) => m.statusKey === 'missed').length;
  // المتوسّط على المقيس وحده: طيُّ غير المقيس صفراً كان يجرّ نسبة المكتب للأسفل ببياناتٍ ليست بيانات
  const measured = done.filter((m) => m.presenceRate !== null);
  const att = measured.length
    ? Math.round(measured.reduce((a, m) => a + (m.presenceRate ?? 0), 0) / measured.length)
    : null;

  const stats: StatItem[] = [
    ['t-blue', 'video', up, 'اجتماعات قادمة'],
    ['t-amber', 'video', live, 'جارية الآن'],
    ['t-green', 'user', att !== null ? `${att}%` : '—', 'متوسّط البقاء'],
    ['t-green', 'check', kpis.decisionRate !== null ? `${kpis.decisionRate}%` : '—', 'تنفيذ القرارات'],
    ['t-grey', 'clock', kpis.avgMinutes !== null ? `${kpis.avgMinutes} د` : '—', 'متوسط المدة'],
    ['t-red', 'out', missed, 'لم تنعقد'],
    ['t-blue', 'folder', meetings.length, 'إجمالي الاجتماعات'],
  ];

  const list = meetings.filter((m) => {
    if (filter !== 'all' && m.statusKey !== filter) return false;
    if (lawyerF && m.lawyer !== lawyerF) return false;
    if (q.trim()) {
      const hay = `${m.title} ${m.client} ${m.lawyer} ${m.caseRef || ''}`.toLowerCase();
      if (!hay.includes(q.trim().toLowerCase())) return false;
    }
    const day = m.startsAt ? m.startsAt.slice(0, 10) : '';
    if (fromD && (!day || day < fromD)) return false;
    if (toD && (!day || day > toD)) return false;
    return true;
  });

  const caseOptions = clients.find((c) => c.id === clientId)?.items ?? [];

  const applyTpl = (name: string, t: string) => {
    setTitle(name);
    setType(t);
    toast('تم تطبيق القالب: ' + name);
  };

  // النموذج يعود كاملاً إلى بدايته بعد الإنشاء — كان العميل والقضيّة والموعد والأولويّة والسرّيّة تبقى من السابق
  const resetForm = () => {
    setTitle('');
    setType(MEET_TYPES_FULL[0]);
    setPrio('عادية');
    setConf('عادي');
    setParticipants([]);
    setDay('');
    setTime('10:00');
    setClientId('');
    setLawyerId('');
    setCaseRef('');
  };

  const submit = () => {
    if (!title.trim()) { toast('أدخل عنوان الاجتماع'); return; }

    if (!day) {
      toast('اختر تاريخ الاجتماع');

      return;
    }

    // رسالة النجاح من الخادم (`flash`) — تقول «بلا رابط Zoom» إن تعذّر إنشاؤه؛ والرفض يعرضه `useServerAction`
    void action.run('/admin/meetings', {
      data: {
        title, type, priority: prio, conf,
        participant_ids: participants,
        day, time,
        client_id: clientId === '' ? null : clientId,
        lawyer_id: lawyerId === '' ? null : lawyerId,
        case_ref: caseRef,
      },
      fallback: 'تعذّر إنشاء الاجتماع — راجع الحقول',
      onSuccess: () => {
        setOpen(false);
        setFilter('upcoming');
        resetForm();
      },
    });
  };

  return (
    <>
      {/* 👑 ترويسة رئيسية مطورة 2026 */}
      <div className="greet" style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: 14 }}>
        <div>
          <h2>مركز إدارة الاجتماعات والمواعيد</h2>
          <p>لوحة التحكم الشاملة: جدولة الجلسات المرئية عبر Zoom، إدارة الفريق القانوني، التوثيق الآلي، والربط بالتقويم.</p>
        </div>
        <button
          className="btn"
          onClick={() => setOpen(true)}
          type="button"
          style={{ padding: '10px 18px', fontSize: '13.5px', fontWeight: 700, borderRadius: 10, boxShadow: '0 4px 12px rgba(14,92,156,0.2)' }}
        >
          <Icon name="video" /> + إنشاء اجتماع جديد (Zoom)
        </button>
      </div>

      {/* 📊 شريط مؤشرات الأداء الحية */}
      <StatRow items={stats} />

      {/* 🔖 شريط التبويبات الفئوية السريعة */}
      <div className="mtabs" style={{ marginBottom: 14 }}>
        {MEET_STATUSES.map((t) => (
          <button
            key={t[0]}
            className={`mtab${filter === t[0] ? ' on' : ''}`}
            onClick={() => setFilter(t[0])}
            type="button"
          >
            {t[1]}
          </button>
        ))}
      </div>

      {/* 🔍 مركز تصفية وبحث متقدم فاخر 2026 */}
      <div className="card" style={{ marginBottom: 16, borderRadius: 14, overflow: 'hidden', border: '1px solid var(--line-soft, #e2e8f0)', boxShadow: '0 4px 16px rgba(0,0,0,0.03)' }}>
        <div className="card-b" style={{ padding: 16, background: 'var(--surface-soft, #f8fafc)' }}>

          {/* حقل البحث الرئيسي مع زر التوسيع والتصفية */}
          <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
            <div style={{ position: 'relative', flex: 1 }}>
              <input
                className="input"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder="🔍 بحث باسم العميل، المحامي المسؤول، عنوان الجلسة، أو مرجع القضية…"
                style={{
                  fontSize: '13.5px',
                  padding: '11px 16px',
                  borderRadius: 10,
                  border: '1px solid var(--line-soft, #cbd5e1)',
                  background: '#ffffff',
                  boxShadow: 'inset 0 1px 3px rgba(0,0,0,0.02)',
                  width: '100%',
                }}
              />
              {q && (
                <button
                  type="button"
                  onClick={() => setQ('')}
                  style={{ position: 'absolute', left: 12, top: '50%', transform: 'translateY(-50%)', border: 'none', background: 'transparent', cursor: 'pointer', color: 'var(--muted)', fontSize: 14 }}
                >
                  ✕
                </button>
              )}
            </div>

            <button
              className="btn soft sm"
              type="button"
              onClick={() => setShowFilters(!showFilters)}
              style={{ padding: '11px 16px', borderRadius: 10, fontWeight: 700, display: 'flex', alignItems: 'center', gap: 6 }}
            >
              <Icon name="search" />
              <span>فلاتر متقدمة</span>
              {activeFiltersCount > 0 && (
                <span style={{ background: 'var(--primary)', color: '#fff', padding: '2px 7px', borderRadius: 99, fontSize: 11, fontWeight: 800 }}>
                  {activeFiltersCount}
                </span>
              )}
            </button>
          </div>

          {/* شبكة المرشحات المتقدمة */}
          {showFilters && (
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))',
                gap: 12,
                paddingTop: 12,
                borderTop: '1px dashed var(--line-soft, #cbd5e1)',
                marginTop: 12,
              }}
            >
              <div className="field">
                <label style={{ fontSize: '12px', fontWeight: 700, color: 'var(--ink-soft)', marginBottom: 5, display: 'block' }}>
                  👤 المحامي المسؤول
                </label>
                <select className="input" value={lawyerF} onChange={(e) => setLawyerF(e.target.value)} style={{ borderRadius: 8, padding: '8px 12px' }}>
                  <option value="">— جميع المحامين —</option>
                  {lawyerOpts.map((l) => <option key={l} value={l}>{l}</option>)}
                </select>
              </div>

              <div className="field">
                <label style={{ fontSize: '12px', fontWeight: 700, color: 'var(--ink-soft)', marginBottom: 5, display: 'block' }}>
                  📅 من تاريخ
                </label>
                <input className="input" type="date" value={fromD} onChange={(e) => setFromD(e.target.value)} style={{ borderRadius: 8, padding: '8px 12px' }} />
              </div>

              <div className="field">
                <label style={{ fontSize: '12px', fontWeight: 700, color: 'var(--ink-soft)', marginBottom: 5, display: 'block' }}>
                  📅 إلى تاريخ
                </label>
                <input className="input" type="date" value={toD} onChange={(e) => setToD(e.target.value)} style={{ borderRadius: 8, padding: '8px 12px' }} />
              </div>
            </div>
          )}

          {/* شريط الفلاتر النشطة والإلغاء السريع */}
          {activeFiltersCount > 0 && (
            <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap', gap: 10, marginTop: 12, paddingTop: 10, borderTop: '1px solid var(--line-soft, #e2e8f0)' }}>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
                <span style={{ fontSize: 12, color: 'var(--muted)', fontWeight: 600 }}>الفلاتر النشطة:</span>
                {q && <span className="chip" style={{ background: '#e0f2fe', color: '#0369a1', fontWeight: 700 }}>بحث: "{q}" <b onClick={() => setQ('')} style={{ cursor: 'pointer', marginRight: 4 }}>✕</b></span>}
                {lawyerF && <span className="chip" style={{ background: '#fef3c7', color: '#b45309', fontWeight: 700 }}>المحامي: {lawyerF} <b onClick={() => setLawyerF('')} style={{ cursor: 'pointer', marginRight: 4 }}>✕</b></span>}
                {fromD && <span className="chip">من: {fromD} <b onClick={() => setFromD('')} style={{ cursor: 'pointer', marginRight: 4 }}>✕</b></span>}
                {toD && <span className="chip">إلى: {toD} <b onClick={() => setToD('')} style={{ cursor: 'pointer', marginRight: 4 }}>✕</b></span>}
              </div>

              <button
                className="btn soft sm"
                type="button"
                onClick={() => { setQ(''); setLawyerF(''); setFromD(''); setToD(''); }}
                style={{ fontSize: 12, color: '#dc2626' }}
              >
                <Icon name="reply" /> مسح جميع الفلاتر
              </button>
            </div>
          )}

        </div>
      </div>

      {/* 📁 قائمة الاجتماعات المنظمة */}
      <div className="card" style={{ borderRadius: 12 }}>
        <div className="card-h">
          <h3>سجل الاجتماعات والمواعيد</h3>
          <span className="sub">يعرض {list.length} من أصل {meetings.length} اجتماع</span>
        </div>
        <div className="card-b" style={{ padding: 12 }}>
          {list.length ? (
            list.map((m) => (
              <div
                key={m.id}
                className="item"
                style={{
                  padding: '14px 16px',
                  marginBottom: 10,
                  borderRadius: 10,
                  border: '1px solid var(--line-soft, #e2e8f0)',
                  transition: 'all 0.2s ease',
                }}
              >
                <div className="iico" style={{ width: 42, height: 42, borderRadius: 10 }}>
                  <Icon name="video" />
                </div>
                <div className="imeta" style={{ flex: 1 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <b style={{ fontSize: '14.5px', color: 'var(--ink)' }}>{m.title}</b>
                    {m.conf === 'سري' && (
                      <span style={{ fontSize: '11px', background: '#FDEAE7', color: '#C0392B', padding: '2px 8px', borderRadius: 6, fontWeight: 700 }}>
                        <Icon name="lock" /> سري جداً
                      </span>
                    )}
                  </div>

                  <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', margin: '5px 0 3px', fontSize: '12.5px', color: 'var(--muted)' }}>
                    <span>🏷️ {m.type}</span>
                    <span>🕒 {m.when}</span>
                    {m.caseRef && <span>⚖️ {m.caseRef}</span>}
                  </div>

                  <div style={{ fontSize: '12px', color: 'var(--ink-soft, #475569)' }}>
                    <b>العميل:</b> {m.client} · <b>المحامي:</b> {m.lawyer !== '—' ? m.lawyer : 'غير مسند'} <PresenceBadge userId={m.lawyerId} />
                    {m.statusKey === 'ended' && (attendanceLabel(m) || fmtActualDuration(m.durationSec)) && (
                      <span style={{ color: 'var(--primary)', fontWeight: 700, marginRight: 8 }}>
                        · {attendanceLabel(m) ?? ''}
                        {fmtActualDuration(m.durationSec) ? ` (${fmtActualDuration(m.durationSec)} مدة فعلية)` : ''}
                      </span>
                    )}
                  </div>
                </div>

                <div className="iact" style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <span className={`mq-priority ${m.priority}`} style={{ padding: '4px 10px', borderRadius: 8, fontSize: '11px', fontWeight: 700 }}>
                    {m.priority}
                  </span>
                  <Badge text={m.status} tone={meetStatusTone(m.status)} />
                  {/* شارة الاعتماد — كانت حالة الاعتماد غائبة عن القائمة فلا يُعرف ما ينتظر الإدارة */}
                  <Badge text={m.approve} tone={m.approved ? 'b-green' : 'b-amber'} />
                  <button
                    className="btn soft sm"
                    onClick={() => router.visit(`/admin/meeting?id=${encodeURIComponent(m.id)}`)}
                    type="button"
                    style={{ padding: '7px 14px', borderRadius: 8, fontSize: '12px' }}
                  >
                    <Icon name="out" /> فتح الملف والاجتماع
                  </button>
                </div>
              </div>
            ))
          ) : (
            <div className="empty" style={{ padding: '40px 20px' }}>
              <Icon name="video" />
              <b>لا توجد اجتماعات مطابقة في هذه الفئة</b>
            </div>
          )}
        </div>
      </div>

      {/* 📝 مودال إنشاء اجتماع جديد المطور — التصميم الاحترافي الفاخر 2026 */}
      <Modal
        title="جدولة جلسة واجتماع جديد"
        subtitle="إنشاء جلسة مرئية سحابية عبر Zoom وربطها التلقائي بملفات القضايا والتقويم"
        maxWidth={780}
        open={open}
        onClose={() => setOpen(false)}
      >
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
          
          {/* ⚡ القوالب السريعة الذكية */}
          <div>
            <div style={{ fontSize: '12px', fontWeight: 700, color: 'var(--ink-soft)', marginBottom: 8, display: 'flex', alignItems: 'center', gap: 6 }}>
              <Icon name="sparkles" /> قوالب الجلسات السريعة (اختر لملء البيانات فورياً):
            </div>
            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: 8 }}>
              {MEET_TEMPLATES.map((t) => {
                const isSelected = title === t[0];
                return (
                  <button
                    key={t[0]}
                    type="button"
                    onClick={() => applyTpl(t[0], t[1])}
                    style={{
                      padding: '8px 12px',
                      borderRadius: 10,
                      border: isSelected ? '2px solid var(--primary)' : '1px solid var(--line-soft, #e2e8f0)',
                      background: isSelected ? 'rgba(14,92,156,0.08)' : '#ffffff',
                      color: isSelected ? 'var(--primary)' : 'var(--ink)',
                      fontWeight: isSelected ? 800 : 600,
                      fontSize: '12.5px',
                      cursor: 'pointer',
                      textAlign: 'right',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'space-between',
                      transition: 'all 0.15s ease',
                    }}
                  >
                    <span>{t[0]}</span>
                    {isSelected && <Icon name="check" />}
                  </button>
                );
              })}
            </div>
          </div>

          {/* 📋 القسم 1: بيانات وتصنيف الجلسة */}
          <div style={{ background: 'var(--surface-soft, #f8fafc)', border: '1px solid var(--line-soft, #e2e8f0)', borderRadius: 12, padding: 16 }}>
            <div style={{ fontSize: '13px', fontWeight: 800, color: 'var(--deep)', marginBottom: 12, display: 'flex', alignItems: 'center', gap: 6 }}>
              <Icon name="video" /> بيانات الجلسة والموضوع
            </div>

            <div className="field" style={{ marginBottom: 12 }}>
              <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>عنوان الاجتماع / الجلسة *</label>
              <input
                className="input"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="مثال: استشارة مرئية — مناقشة صياغة العقد التجاري"
                style={{ borderRadius: 9, padding: '10px 14px', fontSize: '13.5px', border: '1px solid var(--line-soft, #cbd5e1)', background: '#fff' }}
              />
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12, marginBottom: 12 }}>
              <div className="field">
                <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>نوع الجلسة</label>
                <select
                  className="input"
                  value={type}
                  onChange={(e) => setType(e.target.value)}
                  style={{ borderRadius: 9, padding: '9px 12px', fontSize: '13px', border: '1px solid var(--line-soft, #cbd5e1)', background: '#fff' }}
                >
                  {MEET_TYPES_FULL.map((t) => <option key={t}>{t}</option>)}
                </select>
              </div>

              <div className="field">
                <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>درجة الأولوية</label>
                <div style={{ display: 'flex', gap: 6 }}>
                  {[
                    { label: 'عادية', color: '#0369a1', bg: '#e0f2fe' },
                    { label: 'متوسطة', color: '#b45309', bg: '#fef3c7' },
                    { label: 'عالية', color: '#b91c1c', bg: '#fee2e2' },
                  ].map((p) => {
                    const sel = prio === p.label;
                    return (
                      <button
                        key={p.label}
                        type="button"
                        onClick={() => setPrio(p.label)}
                        style={{
                          flex: 1,
                          padding: '7px 4px',
                          borderRadius: 8,
                          fontSize: '12px',
                          fontWeight: 700,
                          cursor: 'pointer',
                          border: sel ? `2px solid ${p.color}` : '1px solid var(--line-soft, #cbd5e1)',
                          background: sel ? p.bg : '#ffffff',
                          color: sel ? p.color : 'var(--ink-soft)',
                          transition: '0.15s',
                        }}
                      >
                        {p.label}
                      </button>
                    );
                  })}
                </div>
              </div>
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
              <div className="field">
                <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>السرية والخصوصية</label>
                <div style={{ display: 'flex', gap: 8 }}>
                  {[
                    { id: 'عادي', label: 'عادي (دخول مباشر)', icon: 'video' },
                    { id: 'سري', label: 'سري (غرفة انتظار)', icon: 'lock' },
                  ].map((c) => {
                    const sel = conf === c.id;
                    return (
                      <button
                        key={c.id}
                        type="button"
                        onClick={() => setConf(c.id)}
                        style={{
                          flex: 1,
                          padding: '8px 10px',
                          borderRadius: 8,
                          fontSize: '11.5px',
                          fontWeight: 700,
                          cursor: 'pointer',
                          border: sel ? '2px solid var(--primary)' : '1px solid var(--line-soft, #cbd5e1)',
                          background: sel ? 'rgba(14,92,156,0.08)' : '#ffffff',
                          color: sel ? 'var(--primary)' : 'var(--ink-soft)',
                          display: 'flex',
                          alignItems: 'center',
                          justifyContent: 'center',
                          gap: 6,
                          transition: '0.15s',
                        }}
                      >
                        <Icon name={c.icon} />
                        <span>{c.label}</span>
                      </button>
                    );
                  })}
                </div>
                {/* الفرق من إعدادات Zoom نفسها (`ZoomService::settings`): عادي ⇐ دخولٌ قبل المضيف بلا غرفة انتظار،
                    سري ⇐ غرفة انتظار ولا دخول قبل المضيف */}
                <div style={{ fontSize: '11.5px', color: 'var(--muted)', marginTop: 6, lineHeight: 1.6 }}>
                  {conf === 'سري'
                    ? 'سري: ينتظر كلّ مدعوٍّ في غرفة الانتظار ولا يدخل حتى يقبله المضيف.'
                    : 'عادي: يدخل كلّ من معه الرابط مباشرةً، ولو قبل وصول المضيف.'}
                </div>
              </div>

              {/* لا «المدة الزمنية المقدرة» (قرار المالك 2026-09-26): الاجتماع ينتهي حين يُنهيه المضيف،
                  ومدّته الفعليّة تُقاس من Zoom بعد إنهائه وتظهر في البطاقة «مدة فعلية». */}
            </div>
          </div>

          {/* 📅 القسم 2: الموعد والتوقيت */}
          <div style={{ background: 'var(--surface-soft, #f8fafc)', border: '1px solid var(--line-soft, #e2e8f0)', borderRadius: 12, padding: 16 }}>
            <div style={{ fontSize: '13px', fontWeight: 800, color: 'var(--deep)', marginBottom: 12, display: 'flex', alignItems: 'center', gap: 6 }}>
              <Icon name="cal" /> الموعد وتوقيت البدء
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
              <div className="field">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 5 }}>
                  <label style={{ fontSize: '12px', fontWeight: 700 }}>التاريخ المجدول <span style={{ color: 'var(--red)' }}>*</span></label>
                  <div style={{ display: 'flex', gap: 4 }}>
                    <button
                      type="button"
                      onClick={() => setDay(todayISO())}
                      style={{ fontSize: 10.5, padding: '2px 6px', borderRadius: 4, border: '1px solid var(--line-soft)', background: '#fff', cursor: 'pointer' }}
                    >
                      اليوم
                    </button>
                    <button
                      type="button"
                      onClick={() => setDay(dateISOAfter(1))}
                      style={{ fontSize: 10.5, padding: '2px 6px', borderRadius: 4, border: '1px solid var(--line-soft)', background: '#fff', cursor: 'pointer' }}
                    >
                      غداً
                    </button>
                  </div>
                </div>
                <input
                  className="input"
                  type="date"
                  value={day}
                  onChange={(e) => setDay(e.target.value)}
                  style={{ borderRadius: 9, padding: '9px 12px', fontSize: '13px', border: '1px solid var(--line-soft, #cbd5e1)', background: '#fff' }}
                />
              </div>
            </div>

            <TimeSlotPicker
              value={time}
              onChange={setTime}
              date={day}
              slots={slots}
              label="وقت بدء الجلسة / الاجتماع"
              required
              allowCustom
/>
          </div>

          {/* 👥 القسم 3: الربط بالعميل والقضايا والكادر */}
          <div style={{ background: 'var(--surface-soft, #f8fafc)', border: '1px solid var(--line-soft, #e2e8f0)', borderRadius: 12, padding: 16 }}>
            <div style={{ fontSize: '13px', fontWeight: 800, color: 'var(--deep)', marginBottom: 12, display: 'flex', alignItems: 'center', gap: 6 }}>
              <Icon name="link" /> الأطراف، ملف القضية، والكادر المسؤول
            </div>

            <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12, marginBottom: 12 }}>
              <div className="field">
                <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>👤 العميل المستهدف</label>
                <select
                  className="input"
                  value={clientId}
                  onChange={(e) => setClientId(e.target.value === '' ? '' : Number(e.target.value))}
                  style={{ borderRadius: 9, padding: '9px 12px', fontSize: '13px', border: '1px solid var(--line-soft, #cbd5e1)', background: '#fff' }}
                >
                  <option value="">— اجتماع داخلي (بدون عميل) —</option>
                  {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                </select>
              </div>

              <div className="field">
                <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>⚖️ القضية / التذكرة المربوطة</label>
                <select
                  className="input"
                  value={caseRef}
                  onChange={(e) => setCaseRef(e.target.value)}
                  style={{ borderRadius: 9, padding: '9px 12px', fontSize: '13px', border: '1px solid var(--line-soft, #cbd5e1)', background: '#fff' }}
                >
                  <option value="">— اختر ملف القضية / الاستشارة —</option>
                  {caseOptions.map((o) => <option key={o.label} value={o.label}>{o.label}</option>)}
                </select>
              </div>
            </div>

            <div className="field" style={{ marginBottom: 12 }}>
              <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 5, display: 'block' }}>👔 المحامي المسؤول والمشرف على الجلسة</label>
              <select
                className="input"
                value={lawyerId}
                onChange={(e) => {
                  const id = e.target.value === '' ? '' : Number(e.target.value);
                  setLawyerId(id);
                  // المسؤول ليس مشاركاً (الخادم يستبعده) — فلا يبقى محدَّداً في البطاقات
                  setParticipants((prev) => prev.filter((p) => p !== id));
                }}
                style={{ borderRadius: 9, padding: '9px 12px', fontSize: '13px', border: '1px solid var(--line-soft, #cbd5e1)', background: '#fff' }}
              >
                <option value="">— بدون محامٍ محدد —</option>
                {lawyers.map((l) => <option key={l.id} value={l.id}>{l.name}{inSessionSuffix(inSession, l.id)}</option>)}
              </select>
            </div>

            {/* اختيار الكادر والمشاركين بنظام البطاقات التفاعلية */}
            <div className="field">
              <label style={{ fontSize: '12px', fontWeight: 700, marginBottom: 6, display: 'block' }}>
                👥 المشاركون من الكادر القانوني والإداري (انقر للتحديد والإضافة)
              </label>
              <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', maxHeight: 110, overflowY: 'auto', padding: 6, border: '1px solid var(--line-soft, #cbd5e1)', borderRadius: 9, background: '#fff' }}>
                {/* الكادر الحقيقيّ وحده — كان يسقط إلى دليلٍ تجريبيّ ثابت في `admin-data` بأسماءٍ لا حسابات لها فلا يصلها إشعار */}
                {participantChoices.length === 0 && (
                  <span style={{ fontSize: '12px', color: 'var(--muted)', padding: '4px 6px' }}>لا كادر نشطاً لإضافته.</span>
                )}
                {participantChoices.map((member) => {
                  const isChecked = participants.includes(member.id);
                  return (
                    <button
                      key={member.id}
                      type="button"
                      onClick={() => {
                        if (isChecked) {
                          setParticipants(participants.filter((p) => p !== member.id));
                        } else {
                          setParticipants([...participants, member.id]);
                        }
                      }}
                      style={{
                        padding: '4px 10px',
                        borderRadius: 20,
                        fontSize: '11.5px',
                        fontWeight: 600,
                        cursor: 'pointer',
                        border: isChecked ? '1.5px solid var(--primary)' : '1px solid var(--line-soft, #e2e8f0)',
                        background: isChecked ? 'var(--primary)' : '#f8fafc',
                        color: isChecked ? '#ffffff' : 'var(--ink)',
                        display: 'flex',
                        alignItems: 'center',
                        gap: 5,
                        transition: '0.12s ease',
                      }}
                    >
                      <span>{isChecked ? '✓' : '+'}</span>
                      <span>{member.label}</span>
                    </button>
                  );
                })}
              </div>
            </div>
          </div>

          {/* 🚀 أزرار الإجراءات السفلية */}
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, paddingTop: 6 }}>
            <button
              type="button"
              className="btn soft"
              onClick={() => setOpen(false)}
              style={{ padding: '11px 20px', borderRadius: 10, fontWeight: 700 }}
            >
              إلغاء
            </button>
            <button
              type="button"
              className="btn"
              onClick={submit}
              disabled={action.busy}
              style={{
                padding: '11px 26px',
                borderRadius: 10,
                fontSize: '13.5px',
                fontWeight: 800,
                boxShadow: '0 4px 14px rgba(14,92,156,0.25)',
              }}
            >
              <Icon name="check" /> {action.busy ? 'جارٍ الإنشاء…' : 'تأكيد جدولة الاجتماع وإطلاقه'}
            </button>
          </div>

        </div>
      </Modal>
    </>
  );
};

export default AdminMeetMgmt;
