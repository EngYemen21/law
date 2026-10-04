import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import Icon from '@/lib/icons';
import { TILES, VIEW_ROUTE } from '@/lib/data';
import type { Appt, Invoice } from '@/lib/data';
import { visitHref } from '@/lib/room';

// ============================================================
// لوحة العميل الرقمية والكونسيرج القانوني 360 درجة
// بوابة تفاعلية فاخرة لمتابعة القضايا، المواعيد، التذاكر، والتنبيهات الحية
// ============================================================

const JOURNEY_STEPS = [
  'استلام الطلب',
  'التحليل والفرز',
  'الإحالة للقسم',
  'الرأي القانوني',
  'حجز الاستشارة',
  'انعقاد الجلسة',
  'النتيجة والاعتماد',
];

export interface ClientCaseItem {
  no: string;
  type: string;
  status: string;
  tone: string;
  update?: string;
  court?: string;
  department?: string;
  assignedLawyer?: string;
  nextHearingLabel?: string;
}

export interface ClientTicketItem {
  no: string;
  type: string;
  subject?: string;
  status: string;
  tone: string;
  priority?: string;
  lawyer: string;
  updatedAgo?: string;
}

export interface ClientExecItem {
  number: string;
  subject: string;
  court?: string;
  stage?: number;
  /** اسم المرحلة من الخادم (`Execution::stageLabel`) */
  stageLabel?: string;
  status: string;
  tone: string;
  amount?: number;
  lastAction?: string;
}

export interface ActionAlert {
  id: string;
  type: string;
  title: string;
  desc: string;
  cta: string;
  link: string;
  tone: string;
}

export interface AdvisorInfo {
  name: string;
  title: string;
  jobTitle: string;
  department: string;
  initials: string;
}

export interface RecentDoc {
  id?: number | string;
  name: string;
  meta: string;
  canDownload?: boolean;
}

interface Props {
  name: string;
  counts: {
    openTickets: number;
    activeCases?: number;
    upAppts: number;
    upMeet: number;
    /** مواعيد + اجتماعات قادمة أو جارية — من الخادم */
    upcoming: number;
    dueInv: number;
    overdueInv?: number;
    myExec: number;
  };
  upcomingAppts: Appt[];
  dueInvoices: Invoice[];
  activeCases?: ClientCaseItem[];
  activeTickets?: ClientTicketItem[];
  activeExecutions?: ClientExecItem[];
  lastTicket?: { no: string; step: number; status?: string; type?: string } | null;
  actionAlerts?: ActionAlert[];
  assignedAdvisor?: AdvisorInfo | null;
  recentDocs?: RecentDoc[];
}

const go = (view: string) => {
  const route = VIEW_ROUTE[view];
  if (route) router.visit(route);
};

const Dashboard: React.FC<Props> = ({
  name,
  counts,
  upcomingAppts = [],
  dueInvoices = [],
  activeCases = [],
  activeTickets = [],
  activeExecutions = [],
  lastTicket,
  actionAlerts = [],
  assignedAdvisor,
  recentDocs = [],
}) => {
  const [activeTab, setActiveTab] = useState<'appts' | 'cases' | 'tickets' | 'execs'>('appts');

  // مؤشرات النبض الرئيسية
  const stats: StatItem[] = [
    ['t-blue', 'folder', counts.openTickets, 'تذاكر وطلبات جارية', 'tickets'],
    ['t-cyan', 'scale', counts.activeCases ?? activeCases.length, 'قضايا منظورة بالمحاكم', 'cases'],
    // الوجهة calendar لا appts: مفتاح appts معلَّق في VIEW_ROUTE (طُوي في التقويم الموحّد)
    ['t-green', 'cal', counts.upcoming, 'مواعيد واجتماعات قادمة', 'calendar'],
    [
      counts.overdueInv ? 't-red' : 't-amber',
      'card',
      counts.dueInv,
      counts.overdueInv ? `فواتير مستحقة (${counts.overdueInv} متأخرة)` : 'فواتير بانتظار السداد',
      'invoices',
    ],
  ];

  const curStep = lastTicket ? Math.min(lastTicket.step, JOURNEY_STEPS.length - 1) : -1;

  return (
    <>
      {/* ── الترويسة التفاعلية الفاخرة (Hero Command Banner) ── */}
      <div className="hero">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', flexWrap: 'wrap', gap: 14 }}>
          <div style={{ minWidth: 260, flex: '1 1 auto' }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6, flexWrap: 'wrap' }}>
              <h2 style={{ margin: 0, fontWeight: 800 }}>مرحباً بك، {name} 👋</h2>
              {/* كانت «عميل موثق 🛡️» ثابتةً لكل مستخدم بلا أيّ عمود توثيق —
                  شارةٌ تدّعي تحقّقاً لم يجرِ. والحالة الفعليّة هي العضويّة. */}
              <Badge text="حساب نشط" tone="b-green" />
            </div>
            <p style={{ margin: 0, opacity: 0.9 }}>
              بوابتك القانونية الموحدة لمتابعة القضايا، حجز الجلسات، واستعراض الرأي والمستندات المعتمدة.
            </p>
          </div>

          <div className="hero-cta" style={{ margin: 0 }}>
            <button className="hero-b" onClick={() => go('book')} type="button">
              <Icon name="calplus" /> حجز استشارة فورية
            </button>
            <button className="hero-b ghost" onClick={() => go('newticket')} type="button">
              <Icon name="plus" /> فتح تذكرة جديدة
            </button>
            <button className="hero-b ghost" onClick={() => go('execrequest')} type="button">
              <Icon name="exec" /> طلب تنفيذ قضائي
            </button>
          </div>
        </div>
      </div>

      {/* ── مركز التنبيهات والإجراءات العاجلة (Smart Action Radar - بطاقات مصغرة متكيفة) ── */}
      {actionAlerts.length > 0 && (
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 280px), 1fr))',
            gap: 12,
            marginBottom: 20,
          }}
        >
          {actionAlerts.map((alert) => (
            <div
              key={alert.id}
              className="card"
              style={{
                margin: 0,
                background: alert.tone === 'b-red' ? 'rgba(239, 68, 68, 0.05)' : alert.tone === 'b-amber' ? 'rgba(245, 158, 11, 0.05)' : 'rgba(14, 165, 233, 0.05)',
                border: `1.5px solid ${alert.tone === 'b-red' ? 'rgba(239, 68, 68, 0.25)' : alert.tone === 'b-amber' ? 'rgba(245, 158, 11, 0.25)' : 'rgba(14, 165, 233, 0.25)'}`,
                borderRadius: 12,
                padding: '12px 14px',
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'space-between',
                gap: 10,
                transition: 'transform 0.15s ease, box-shadow 0.15s ease',
              }}
            >
              <div style={{ display: 'flex', alignItems: 'flex-start', gap: 10 }}>
                <div
                  style={{
                    width: 34,
                    height: 34,
                    borderRadius: 8,
                    background: 'var(--paper)',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 15,
                    flexShrink: 0,
                    boxShadow: '0 1px 3px rgba(0,0,0,0.06)',
                  }}
                >
                  <Icon name={alert.type === 'video_ready' ? 'video' : alert.type === 'missing_doc' ? 'alert' : alert.type === 'needs_booking' ? 'cal' : 'card'} />
                </div>
                <div style={{ minWidth: 0, flex: 1 }}>
                  <b style={{ fontSize: 13, color: 'var(--ink)', display: 'block', wordBreak: 'break-word' }}>{alert.title}</b>
                  <div style={{ fontSize: 11.5, color: 'var(--muted)', marginTop: 3, wordBreak: 'break-word', lineHeight: 1.4 }}>{alert.desc}</div>
                </div>
              </div>

              <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 2 }}>
                <button
                  type="button"
                  onClick={() => visitHref(alert.link)}
                  className={`btn sm ${alert.tone === 'b-red' ? '' : 'soft'}`}
                  style={{
                    textDecoration: 'none',
                    fontWeight: 700,
                    fontSize: 12,
                    padding: '5px 12px',
                    width: '100%',
                    justifyContent: 'center',
                  }}
                >
                  {alert.cta}
                </button>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* ── شريط مؤشرات النبض الرقمي ── */}
      <StatRow items={stats} onSelect={(idx) => {
        const targetView = stats[idx]?.[4];
        if (targetView) go(targetView);
      }} />

      {/* ── مساحة العمل والمتابعة 360° + الجانبية الذكية ── */}
      <div className="client-dashboard-grid">
        
        {/* العمود الأيمن: مركز المتابعة متعدد التبويبات */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18, minWidth: 0 }}>
          
          <div className="card">
            {/* رأس التبويبات الذكية المتجاوبة */}
            <div className="card-h">
              <div className="dashboard-tabs-container">
                <div className="dashboard-tabs-nav">
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'appts' ? '' : 'soft'}`}
                    style={{ boxShadow: activeTab === 'appts' ? undefined : 'none' }}
                    onClick={() => setActiveTab('appts')}
                  >
                    <Icon name="cal" /> المواعيد ({counts.upAppts})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'cases' ? '' : 'soft'}`}
                    style={{ boxShadow: activeTab === 'cases' ? undefined : 'none' }}
                    onClick={() => setActiveTab('cases')}
                  >
                    <Icon name="scale" /> قضاياي بالمحاكم ({counts.activeCases ?? activeCases.length})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'tickets' ? '' : 'soft'}`}
                    style={{ boxShadow: activeTab === 'tickets' ? undefined : 'none' }}
                    onClick={() => setActiveTab('tickets')}
                  >
                    <Icon name="folder" /> الطلبات والتذاكر ({counts.openTickets})
                  </button>
                  <button
                    type="button"
                    className={`btn sm ${activeTab === 'execs' ? '' : 'soft'}`}
                    style={{ boxShadow: activeTab === 'execs' ? undefined : 'none' }}
                    onClick={() => setActiveTab('execs')}
                  >
                    <Icon name="exec" /> التنفيذ ({counts.myExec})
                  </button>
                </div>

                <span
                  className="sub"
                  style={{ cursor: 'pointer', fontWeight: 600, whiteSpace: 'nowrap' }}
                  onClick={() => go(activeTab === 'appts' ? 'calendar' : activeTab)}
                >
                  عرض كل القسم ←
                </span>
              </div>
            </div>

            {/* محتوى التبويبات */}
            <div className="card-b" style={{ padding: 14 }}>
              {/* تبويب المواعيد */}
              {activeTab === 'appts' && (
                upcomingAppts.length === 0 ? (
                  <div className="nx-empty" style={{ padding: '36px 16px', textAlign: 'center' }}>
                    <Icon name="cal" />
                    <b style={{ display: 'block', margin: '8px 0 4px', fontSize: 14 }}>لا توجد جلسات أو مواعيد قادمة مجدولة</b>
                    <p style={{ fontSize: 12.5, color: 'var(--muted)', marginBottom: 14 }}>احجز استشارة حضورية أو مرئية مع أحد مستشارينا المعتمدين.</p>
                    <button className="btn sm" onClick={() => go('book')} type="button">
                      <Icon name="calplus" /> حجز استشارة جديدة
                    </button>
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    {upcomingAppts.map((a) => (
                      <div
                        key={a.id}
                        className="dashboard-row-item"
                        style={{ cursor: 'pointer' }}
                        onClick={() => go('calendar')}
                      >
                        <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
                          <div className="nx-ic" style={{ width: 40, height: 40, borderRadius: 8, fontSize: 18, flexShrink: 0 }}>
                            <Icon name={a.ico || 'cal'} />
                          </div>
                          <div style={{ minWidth: 0 }}>
                            <b style={{ fontSize: 13.5, color: 'var(--ink)' }}>استشارة {a.type}</b>
                            <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 2, wordBreak: 'break-word' }}>
                              <span>{a.day}</span> · <span>{a.time}</span> · <span style={{ color: 'var(--ink)' }}>{a.place}</span>
                            </div>
                            <div style={{ fontSize: 11.5, color: 'var(--primary)', marginTop: 2 }}>
                              المستشار: <b>{a.lawyer}</b>
                            </div>
                          </div>
                        </div>

                        <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexShrink: 0 }}>
                          <Badge text={a.status} tone={a.tone} />
                          <button className="btn ghost sm" type="button">
                            التفاصيل
                          </button>
                        </div>
                      </div>
                    ))}
                  </div>
                )
              )}

              {/* تبويب القضايا بالمحاكم */}
              {activeTab === 'cases' && (
                activeCases.length === 0 ? (
                  <div className="nx-empty" style={{ padding: '36px 16px', textAlign: 'center' }}>
                    <Icon name="scale" />
                    <b style={{ display: 'block', margin: '8px 0 4px', fontSize: 14 }}>لا توجد قضايا جارية مسجلة باسمك حالياً</b>
                    <p style={{ fontSize: 12.5, color: 'var(--muted)' }}>تظهر هنا جميع الدعاوى والمرافعات المرفوعة أمام الدوائر القضائية ومحاكم الاستئناف.</p>
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    {activeCases.map((c) => (
                      <div
                        key={c.no}
                        className="dashboard-row-item"
                      >
                        <div style={{ minWidth: 0 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                            <span className="mono" style={{ fontWeight: 800, fontSize: 13.5 }}>{c.no}</span>
                            <Badge text={c.status} tone={c.tone} />
                            {c.department && <span className="muted" style={{ fontSize: 11.5 }}>· {c.department}</span>}
                          </div>
                          {c.court && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 3 }}>🏛️ {c.court}</div>}
                          {c.nextHearingLabel && c.nextHearingLabel !== '—' && (
                            <div style={{ fontSize: 12, color: 'var(--primary)', marginTop: 3, fontWeight: 600 }}>
                              📅 الجلسة القادمة: {c.nextHearingLabel}
                            </div>
                          )}
                        </div>

                        <button className="btn soft sm" onClick={() => router.visit(`/cases/${encodeURIComponent(c.no)}`)} type="button">
                          متابعة الملف
                        </button>
                      </div>
                    ))}
                  </div>
                )
              )}

              {/* تبويب التذاكر والاستشارات المكتوبة */}
              {activeTab === 'tickets' && (
                activeTickets.length === 0 ? (
                  <div className="nx-empty" style={{ padding: '36px 16px', textAlign: 'center' }}>
                    <Icon name="folder" />
                    <b style={{ display: 'block', margin: '8px 0 4px', fontSize: 14 }}>لا توجد تذاكر نشطة مفتوحة</b>
                    <button className="btn sm" onClick={() => go('newticket')} style={{ marginTop: 10 }} type="button">
                      <Icon name="plus" /> فتح تذكرة جديدة
                    </button>
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    {activeTickets.map((t) => (
                      <div
                        key={t.no}
                        className="dashboard-row-item"
                      >
                        <div style={{ minWidth: 0 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                            <span className="mono" style={{ fontWeight: 800, fontSize: 13.5 }}>{t.no}</span>
                            <Badge text={t.status} tone={t.tone} />
                            <span style={{ fontSize: 12.5, fontWeight: 600 }}>{t.type}</span>
                          </div>
                          {t.subject && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 3, wordBreak: 'break-word' }}>{t.subject}</div>}
                          <div style={{ fontSize: 11.5, color: 'var(--faint)', marginTop: 3 }}>
                            المستشار: {t.lawyer} · آخر تحديث: {t.updatedAgo}
                          </div>
                        </div>

                        <button className="btn soft sm" onClick={() => router.visit(`/tickets/${encodeURIComponent(t.no)}`)} type="button">
                          عرض المحادثة
                        </button>
                      </div>
                    ))}
                  </div>
                )
              )}

              {/* تبويب ملفات التنفيذ القضائي */}
              {activeTab === 'execs' && (
                activeExecutions.length === 0 ? (
                  <div className="nx-empty" style={{ padding: '36px 16px', textAlign: 'center' }}>
                    <Icon name="exec" />
                    <b style={{ display: 'block', margin: '8px 0 4px', fontSize: 14 }}>لا توجد ملفات تنفيذ قضائي جارية</b>
                    <button className="btn sm" onClick={() => go('execrequest')} style={{ marginTop: 10 }} type="button">
                      <Icon name="exec" /> تقديم طلب تنفيذ
                    </button>
                  </div>
                ) : (
                  <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                    {activeExecutions.map((e) => (
                      <div
                        key={e.number}
                        className="dashboard-row-item"
                      >
                        <div style={{ minWidth: 0 }}>
                          <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                            <span className="mono" style={{ fontWeight: 800, fontSize: 13.5 }}>{e.number}</span>
                            <Badge text={e.status} tone={e.tone} />
                            {/* المرحلة والمحكمة يرسلهما الخادم وكانت الشاشة تُسقطهما — والمرحلة لا تُعاد إن كانت هي الحالة نفسها */}
                            {e.stageLabel && e.stageLabel !== e.status && (
                              <span className="chip">{e.stageLabel}</span>
                            )}
                            {e.amount && <b style={{ fontSize: 12.5, color: 'var(--ink)' }}>{e.amount.toLocaleString('en-US')} ريال</b>}
                          </div>
                          <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 3, wordBreak: 'break-word' }}>{e.subject}</div>
                          {e.court && <div style={{ fontSize: 12, color: 'var(--muted)', marginTop: 3 }}>🏛️ {e.court}</div>}
                          {e.lastAction && <div style={{ fontSize: 11.5, color: 'var(--primary)', marginTop: 2 }}>{e.lastAction}</div>}
                        </div>

                        <button className="btn soft sm" onClick={() => go('execs')} type="button">
                          تتبع القرار
                        </button>
                      </div>
                    ))}
                  </div>
                )
              )}
            </div>
          </div>

          {/* ── شريط مسار وتتبع الرحلة الحية (Interactive Journey Tracker) ── */}
          {lastTicket && (
            <div className="card">
              <div className="card-h">
                <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                  <Icon name="sparkles" />
                  <h3 style={{ fontSize: 14.5 }}>مسار معاملتك داخل المكتب</h3>
                </div>
                <span className="sub" style={{ fontSize: 11.5 }}>
                  التذكرة <b className="mono">{lastTicket.no}</b> ({lastTicket.type || 'استشارة'})
                </span>
              </div>
              <div className="card-b" style={{ padding: '16px 12px' }}>
                <div className="journey" style={{ padding: 12, margin: 0 }}>
                  <div className="journey-track">
                    {JOURNEY_STEPS.map((x, i) => (
                      <div key={x} className={`jstep ${i < curStep ? 'done' : i === curStep ? 'cur' : ''}`}>
                        <div className="jline" />
                        <div className="jdot">{i < curStep ? <Icon name="check" /> : i + 1}</div>
                        <div className="jt">{x}</div>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
            </div>
          )}
        </div>

        {/* العمود الأيسر: الجانبية الذكية (المستشار + الفواتير + الوثائق) */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 18, minWidth: 0 }}>
          
          {/* 👨‍⚖️ بطاقة المستشار القانوني المخصص */}
          {assignedAdvisor && (
            <div className="card" style={{ background: 'linear-gradient(180deg, var(--card-bg, #fff) 0%, var(--paper-2) 100%)' }}>
              <div className="card-h" style={{ padding: '12px 16px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="user" />
                  <h3 style={{ fontSize: 14 }}>المستشار المخصص</h3>
                </div>
                <Badge text="معتمد" tone="b-green" />
              </div>
              <div className="card-b" style={{ padding: '14px 16px', display: 'flex', flexDirection: 'column', gap: 12 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                  <div
                    style={{
                      width: 46,
                      height: 46,
                      borderRadius: '50%',
                      background: 'var(--primary)',
                      color: '#fff',
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      fontWeight: 800,
                      fontSize: 15,
                      flexShrink: 0,
                    }}
                  >
                    {assignedAdvisor.initials}
                  </div>
                  <div style={{ minWidth: 0 }}>
                    <b style={{ fontSize: 13.5, color: 'var(--ink)', display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {assignedAdvisor.name}
                    </b>
                    <span style={{ fontSize: 12, color: 'var(--muted)' }}>{assignedAdvisor.jobTitle}</span>
                    <div style={{ fontSize: 11.5, color: 'var(--primary)', marginTop: 2 }}>{assignedAdvisor.department}</div>
                  </div>
                </div>

                <div style={{ display: 'flex', gap: 8, marginTop: 4, flexWrap: 'wrap' }}>
                  <button className="btn sm" style={{ flex: '1 1 120px' }} onClick={() => go('book')} type="button">
                    <Icon name="calplus" /> حجز جلسة
                  </button>
                  <button className="btn ghost sm" style={{ flex: '1 1 80px' }} onClick={() => go('newticket')} type="button">
                    استفسار
                  </button>
                </div>
              </div>
            </div>
          )}

          {/* 💳 بطاقة الفواتير بانتظار السداد السريع */}
          <div className="card">
            <div className="card-h" style={{ padding: '12px 16px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                <Icon name="card" />
                <h3 style={{ fontSize: 14 }}>فواتير بانتظار السداد</h3>
              </div>
              <span className="sub" style={{ cursor: 'pointer' }} onClick={() => go('invoices')}>
                {counts.dueInv} فواتير
              </span>
            </div>
            <div className="card-b" style={{ padding: 12 }}>
              {dueInvoices.length === 0 ? (
                <div className="nx-empty" style={{ padding: '16px 8px', textAlign: 'center' }}>
                  <span style={{ fontSize: 13, color: 'var(--green, #10b981)' }}>✔ كافة فواتيرك وأتعابك مسددة بالكامل</span>
                </div>
              ) : (
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {dueInvoices.map((v) => (
                    <div
                      key={v.no}
                      style={{
                        background: 'var(--paper-2)',
                        border: '1px solid var(--line-soft)',
                        borderRadius: 8,
                        padding: '10px 12px',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        gap: 8,
                      }}
                    >
                      <div style={{ minWidth: 0 }}>
                        <div style={{ fontWeight: 700, fontSize: 13, color: 'var(--ink)' }}>
                          {v.amount.toLocaleString('en-US')} ريال
                        </div>
                        <div className="muted" style={{ fontSize: 11 }}>{v.no} · {v.due}</div>
                      </div>
                      <button className="btn soft sm" onClick={() => go('invoices')} type="button">
                        سداد
                      </button>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

          {/* 📄 الوثائق والتقارير الصادرة المعتمدة */}
          {recentDocs.length > 0 && (
            <div className="card">
              <div className="card-h" style={{ padding: '12px 16px' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                  <Icon name="folder" />
                  <h3 style={{ fontSize: 14 }}>أحدث الوثائق الصادرة</h3>
                </div>
                <span className="sub" style={{ cursor: 'pointer' }} onClick={() => go('docs')}>الكل</span>
              </div>
              <div className="card-b" style={{ padding: 12 }}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                  {recentDocs.map((doc, idx) => (
                    <div
                      key={doc.id ?? idx}
                      style={{
                        background: 'var(--paper-2)',
                        border: '1px solid var(--line-soft)',
                        borderRadius: 8,
                        padding: '8px 10px',
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'center',
                        gap: 8,
                      }}
                    >
                      <div style={{ display: 'flex', alignItems: 'center', gap: 8, overflow: 'hidden', minWidth: 0 }}>
                        <Icon name="folder" />
                        <div style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          <span style={{ fontSize: 12.5, fontWeight: 600, display: 'block' }}>{doc.name}</span>
                          <span className="muted" style={{ fontSize: 10.5 }}>{doc.meta}</span>
                        </div>
                      </div>
                      <button className="btn ghost sm" onClick={() => go('docs')} type="button" style={{ flexShrink: 0 }}>
                        عرض
                      </button>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

        </div>
      </div>

      {/* ── دليل الخدمات الإلكترونية السريعة (Interactive Services Grid) ── */}
      <div className="sec-head" style={{ marginTop: 24 }}>
        <h3>دليل الخدمات والطلبات الإلكترونية</h3>
      </div>
      <div className="tiles">
        {TILES.map((t) => (
          <button key={t.view} className={`tile ${t.feat ? 'feat' : ''}`} onClick={() => go(t.view)} type="button">
            <div className="ti"><Icon name={t.icon} /></div>
            <b>{t.title}</b>
            <span>{t.sub}</span>
          </button>
        ))}
      </div>
    </>
  );
};

export default Dashboard;


