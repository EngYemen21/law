import React, { useState } from 'react';
import { Bars, BarChart } from '@/components/babylon/admin-charts';
import type { BarDatum } from '@/lib/admin-data';
import Icon from '@/lib/icons';

interface Props {
  stats: {
    totalTickets: number;
    /** الاستشارات عدا الملغاة — رقمٌ مستقلّ عن التذاكر */
    totalConsults: number;
    closureRate: number;
    convertedToCase?: number;
    conversionRate?: number;
    closedTickets?: number;
    finishedTickets?: number;
    activeTickets?: number;
    meetingsHeld: number;
    totalCases?: number;
    activeCases: number;
    ruledCases?: number;
    caseRulingRate?: number;
    appealedCases?: number;
    totalExecutions?: number;
    activeExecutions?: number;
    completedExecutions?: number;
    totalDebtEnforced?: number;
    totalCollectedDebts?: number;
    collectionSuccessRate?: number;
    totalTransitions?: number;
  };
  byDept: BarDatum[];
  byClosureReason?: BarDatum[];
  casesByDept?: BarDatum[];
  executionsByStage?: BarDatum[];
  byExecClosureReason?: BarDatum[];
  recentTransitions?: {
    id: number;
    ref: string;
    type: string;
    transition: string;
    from: string | null;
    to: string;
    actor: string;
    time: string;
  }[];
}

const fmt = (n?: number) => (n ?? 0).toLocaleString('en-US');

const AdminReports: React.FC<Props> = ({
  stats,
  byDept,
  byClosureReason = [],
  casesByDept = [],
  executionsByStage = [],
  byExecClosureReason = [],
  recentTransitions = [],
}) => {
  const [activeTab, setActiveTab] = useState<'overview' | 'triage' | 'cases' | 'executions' | 'audit'>('overview');

  const convertedCount = stats.convertedToCase ?? 0;
  const conversionPct = stats.conversionRate ?? 0;
  const ruledCount = stats.ruledCases ?? 0;
  const rulingPct = stats.caseRulingRate ?? 0;
  const totalCasesCount = stats.totalCases ?? 0;
  const totalExecCount = stats.totalExecutions ?? 0;
  const collectedDebt = stats.totalCollectedDebts ?? 0;
  const enforcedDebt = stats.totalDebtEnforced ?? 0;
  const collectionPct = stats.collectionSuccessRate ?? 0;
  const transitionsCount = stats.totalTransitions ?? 0;

  // البطاقات التنفيذية الكبرى
  const mainCards: [string, string, string, string, string][] = [
    ['t-blue', 'ticket', `${conversionPct}%`, 'معدل تحويل التذاكر لقضايا', `${convertedCount} قضية من ${stats.totalTickets} تذكرة`],
    ['t-green', 'scale', `${rulingPct}%`, 'نسبة حسم القضايا بالأحكام', `${ruledCount} حكم من ${totalCasesCount} قضية`],
    ['t-amber', 'exec', `${collectionPct}%`, 'نسبة نجاح تحصيل ديون التنفيذ', `${fmt(collectedDebt)} ر.س من ${fmt(enforcedDebt)} ر.س`],
    ['t-cyan', 'check', String(transitionsCount), 'سجل حركات النظام المعتمدة (FSM)', 'حركة انتقال موثقة ومحمية'],
  ];

  return (
    <>
      {/* الرأس التنفيذي مع زر التصدير */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 10 }}>
        <div>
          <h2 style={{ fontSize: 20, fontWeight: 800, margin: 0, color: 'var(--ink, #0a2a55)' }}>
            تقارير الأداء ومؤشرات الإدارة الذكية (BI & KPIs)
          </h2>
          <span style={{ fontSize: 13, color: 'var(--muted, #7a8aa3)' }}>
            لوحة قياس وتحليل متكاملة لدورات التذاكر، القضايا، والتنفيذ القضائي
          </span>
        </div>
        <a className="btn soft sm" href="/admin/reports.pdf" download style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
          <Icon name="download" /> تصدير التقرير التنفيذي PDF
        </a>
      </div>

      {/* شريط التبويبات للتنقل بين قطاعات الـ BI */}
      <div className="dashboard-tabs-nav" style={{ marginBottom: 16, borderBottom: '1px solid var(--line-soft, #e7eff6)', paddingBottom: 8 }}>
        <button
          type="button"
          className={`btn sm ${activeTab === 'overview' ? 'primary' : 'soft'}`}
          onClick={() => setActiveTab('overview')}
        >
          <Icon name="compass" /> النظرة العامة الشاملة
        </button>
        <button
          type="button"
          className={`btn sm ${activeTab === 'triage' ? 'primary' : 'soft'}`}
          onClick={() => setActiveTab('triage')}
        >
          <Icon name="ticket" /> التذاكر ({stats.totalTickets}) والاستشارات ({stats.totalConsults})
        </button>
        <button
          type="button"
          className={`btn sm ${activeTab === 'cases' ? 'primary' : 'soft'}`}
          onClick={() => setActiveTab('cases')}
        >
          <Icon name="scale" /> القضايا القضائية ({totalCasesCount})
        </button>
        <button
          type="button"
          className={`btn sm ${activeTab === 'executions' ? 'primary' : 'soft'}`}
          onClick={() => setActiveTab('executions')}
        >
          <Icon name="exec" /> التنفيذ القضائي ({totalExecCount})
        </button>
        <button
          type="button"
          className={`btn sm ${activeTab === 'audit' ? 'primary' : 'soft'}`}
          onClick={() => setActiveTab('audit')}
        >
          <Icon name="lock" /> سجل الرقابة والحركات ({transitionsCount})
        </button>
      </div>

      {/* ── لوحة المؤشرات التنفيذية الرئيسية ── */}
      <div className="stats" style={{ marginBottom: 18 }}>
        {mainCards.map((s, i) => (
          <div key={i} className={`stat ${s[0]}`} style={{ position: 'relative' }}>
            <div className="si"><Icon name={s[1]} /></div>
            <div className="num" style={{ fontSize: 24, fontWeight: 800 }}>{s[2]}</div>
            <div className="lbl" style={{ fontWeight: 700 }}>{s[3]}</div>
            <div style={{ fontSize: 11, opacity: 0.8, marginTop: 4 }}>{s[4]}</div>
          </div>
        ))}
      </div>

      {/* ── تبويب النظرة العامة (Overview) ── */}
      {activeTab === 'overview' && (
        <>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))', gap: 16, marginBottom: 16 }}>
            {/* التذاكر حسب القسم */}
            <div className="card">
              <div className="card-h">
                <h3>التذاكر حسب القسم</h3>
                <span className="sub">{stats.totalTickets} تذكرة</span>
              </div>
              <div className="card-b" style={{ padding: 18 }}>
                {byDept.length ? <Bars data={byDept} /> : <div className="empty"><Icon name="folder" /><b>لا بيانات بعد</b></div>}
              </div>
            </div>

            {/* القضايا حسب القسم */}
            <div className="card">
              <div className="card-h">
                <h3>القضايا القضائية حسب القسم</h3>
                <span className="sub">{totalCasesCount} قضية</span>
              </div>
              <div className="card-b" style={{ padding: 18 }}>
                {casesByDept.length ? <Bars data={casesByDept} /> : <div className="empty"><Icon name="scale" /><b>لا قضايا مسجلة بعد</b></div>}
              </div>
            </div>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))', gap: 16, marginBottom: 16 }}>
            {/* مراحل التنفيذ القضائي */}
            <div className="card">
              <div className="card-h">
                <h3>توزيع ملفات التنفيذ القضائي (المراحل الكبرى)</h3>
                <span className="sub">{totalExecCount} ملف تنفيذ</span>
              </div>
              <div className="card-b" style={{ padding: 18 }}>
                {executionsByStage.length ? <Bars data={executionsByStage} /> : <div className="empty"><Icon name="exec" /><b>لا طلبات تنفيذ بعد</b></div>}
              </div>
            </div>

            {/* بطاقة ملخص الأداء الشامل */}
            <div className="card">
              <div className="card-h">
                <h3>ملخص مؤشرات الإنجاز العامة</h3>
                <span className="sub">المعدلات الحيوية</span>
              </div>
              <div className="card-b" style={{ padding: 16 }}>
                <div className="kpi-row">
                  {/* الحساب في الخادم (`PerformanceSnapshot`): التذاكر المحسومة = `TicketStatus::finals()` */}
                  <span className="t">نسبة حسم التذاكر (المكتملة والمغلقة والمحوّلة)</span>
                  <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{stats.closureRate}%</span>
                </div>
                <div className="kpi-row">
                  <span className="t">جلسات واجتماعات مرئية منتهية</span>
                  <span className="v">{stats.meetingsHeld} اجتماع</span>
                </div>
                <div className="kpi-row">
                  <span className="t">قضايا منظورة نشطة</span>
                  <span className="v">{stats.activeCases} قضية</span>
                </div>
                <div className="kpi-row">
                  <span className="t">طلبات تنفيذ نشطة في محكمة التنفيذ</span>
                  <span className="v">{stats.activeExecutions ?? 0} طلب</span>
                </div>
                <div className="kpi-row">
                  <span className="t">إجمالي مبالغ الديون المنفذ بها</span>
                  <span className="v">{fmt(enforcedDebt)} ر.س</span>
                </div>
                <div className="kpi-row">
                  <span className="t">المبالغ المستردة فعلياً للعملاء</span>
                  <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{fmt(collectedDebt)} ر.س</span>
                </div>
              </div>
            </div>
          </div>
        </>
      )}

      {/* ── تبويب التذاكر والاستشارات (Triage & Consultations BI) ── */}
      {activeTab === 'triage' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))', gap: 16, marginBottom: 16 }}>
          <div className="card">
            <div className="card-h">
              <h3>التذاكر حسب الأقسام القانونية</h3>
              <span className="sub">حجم الطلبات الواردة</span>
            </div>
            <div className="card-b" style={{ padding: 18 }}>
              {byDept.length ? <Bars data={byDept} /> : <div className="empty"><Icon name="folder" /><b>لا تذاكر بعد</b></div>}
            </div>
          </div>

          <div className="card">
            <div className="card-h">
              <h3>تحليل أسباب إغلاق التذاكر دون قضية (مُسبّب)</h3>
              <span className="sub">أسباب حفظ الملفات بعد الاستشارة</span>
            </div>
            <div className="card-b" style={{ padding: 18 }}>
              {byClosureReason.length > 0 ? (
                <BarChart title="" data={byClosureReason.map((d) => [d.m, d.v])} />
              ) : (
                <div className="empty" style={{ padding: 24 }}>
                  <Icon name="info" />
                  <b>لا توجد تذاكر مغلقة بتسبيب نظامي بعد</b>
                  <span style={{ fontSize: 12, color: 'var(--muted)' }}>تظهر هنا أسباب الإغلاق النظامية (عدم جدوى، صلح، عدم اختصاص، إلخ) فور تسجيلها.</span>
                </div>
              )}
            </div>
          </div>

          <div className="card" style={{ gridColumn: '1 / -1' }}>
            <div className="card-h">
              <h3>مؤشرات تفصيلية لمسار التذاكر</h3>
            </div>
            <div className="card-b" style={{ padding: 16, display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: 12 }}>
              <div className="stat t-blue">
                <div className="num">{stats.totalTickets}</div>
                <div className="lbl">إجمالي التذاكر</div>
              </div>
              <div className="stat t-cyan">
                <div className="num">{stats.totalConsults}</div>
                <div className="lbl">إجمالي الاستشارات (عدا الملغاة)</div>
              </div>
              <div className="stat t-green">
                <div className="num">{convertedCount}</div>
                <div className="lbl">حُوّلت إلى قضايا ({conversionPct}%)</div>
              </div>
              <div className="stat t-amber">
                <div className="num">{stats.activeTickets ?? 0}</div>
                <div className="lbl">قيد الإجراء والدراسة</div>
              </div>
              <div className="stat t-grey">
                <div className="num">{stats.closedTickets ?? 0}</div>
                {/* المحوّلة لها عدّادها — والنسبة المئويّة للحسم كلّه، فلا تُلصق بعددٍ لا يضمّها */}
                <div className="lbl">مغلقة ومكتملة (بلا المحوّلة)</div>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ── تبويب القضايا القضائية والاستئناف (Cases & Litigation BI) ── */}
      {activeTab === 'cases' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))', gap: 16, marginBottom: 16 }}>
          <div className="card">
            <div className="card-h">
              <h3>القضايا حسب القسم القضائي</h3>
              <span className="sub">توزيع الملفات النشطة</span>
            </div>
            <div className="card-b" style={{ padding: 18 }}>
              {casesByDept.length ? <Bars data={casesByDept} /> : <div className="empty"><Icon name="scale" /><b>لا قضايا مسجلة</b></div>}
            </div>
          </div>

          <div className="card">
            <div className="card-h">
              <h3>مؤشرات مسار التقاضي والاستئناف</h3>
              <span className="sub">حسم الدعاوى والمواعيد</span>
            </div>
            <div className="card-b" style={{ padding: 16 }}>
              <div className="kpi-row">
                <span className="t">إجمالي القضايا المقيدة</span>
                <span className="v">{totalCasesCount} قضية</span>
              </div>
              <div className="kpi-row">
                <span className="t">قضايا منظورة أمام المحاكم</span>
                <span className="v" style={{ color: 'var(--c-blue, #2563eb)' }}>{stats.activeCases} قضية</span>
              </div>
              <div className="kpi-row">
                <span className="t">قضايا صدر فيها حكم مكتمل</span>
                <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{ruledCount} حكم ({rulingPct}%)</span>
              </div>
              <div className="kpi-row">
                <span className="t">قضايا مقيد فيها مسار استئناف (مهلة 30 يوماً)</span>
                <span className="v" style={{ color: 'var(--c-amber, #f59e0b)' }}>{stats.appealedCases ?? 0} قضية</span>
              </div>
            </div>
          </div>
        </div>
      )}

      {/* ── تبويب التنفيذ القضائي (ExecFlow BI) ── */}
      {activeTab === 'executions' && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))', gap: 16, marginBottom: 16 }}>
          <div className="card">
            <div className="card-h">
              <h3>ملفات التنفيذ القضائي حسب المرحلة</h3>
              <span className="sub">المحطات الإجرائية</span>
            </div>
            <div className="card-b" style={{ padding: 18 }}>
              {executionsByStage.length ? <Bars data={executionsByStage} /> : <div className="empty"><Icon name="exec" /><b>لا طلبات تنفيذ</b></div>}
            </div>
          </div>

          <div className="card">
            <div className="card-h">
              <h3>مؤشرات التحصيل واسترداد الحقوق</h3>
              <span className="sub">كفاءة محاكم التنفيذ</span>
            </div>
            <div className="card-b" style={{ padding: 16 }}>
              <div className="kpi-row">
                <span className="t">إجمالي مطالبات السندات التنفيذية</span>
                <span className="v">{fmt(enforcedDebt)} ر.س</span>
              </div>
              <div className="kpi-row">
                <span className="t">المبالغ المستردة والمحصلة فعلياً</span>
                <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{fmt(collectedDebt)} ر.س</span>
              </div>
              <div className="kpi-row">
                <span className="t">نسبة نجاح استرداد الحقوق</span>
                <span className="v" style={{ color: 'var(--c-green, #10b981)', fontWeight: 800 }}>{collectionPct}%</span>
              </div>
              <div className="kpi-row">
                <span className="t">ملفات تنفيذ نشطة قيد الإجراءات</span>
                <span className="v">{stats.activeExecutions ?? 0} ملف</span>
              </div>
              <div className="kpi-row">
                <span className="t">ملفات تنفيذ مكتملة ومغلقة</span>
                <span className="v">{stats.completedExecutions ?? 0} ملف</span>
              </div>
            </div>
          </div>

          {byExecClosureReason.length > 0 && (
            <div className="card" style={{ gridColumn: '1 / -1' }}>
              <div className="card-h">
                <h3>أسباب إنهاء وإغلاق ملفات التنفيذ القضائي</h3>
              </div>
              <div className="card-b" style={{ padding: 18 }}>
                <BarChart title="" data={byExecClosureReason.map((d) => [d.m, d.v])} />
              </div>
            </div>
          )}
        </div>
      )}

      {/* ── تبويب سجل الرقابة والانتقالات (FSM Audit Trail) ── */}
      {activeTab === 'audit' && (
        <div className="card" style={{ marginBottom: 16 }}>
          <div className="card-h">
            <h3>سجل الحركات والانتقالات المعتمدة مركزياً (Live FSM Activity Stream)</h3>
            <span className="sub">آخر العمليات المنفذة والمحمية بحارس الحالة</span>
          </div>
          <div className="card-b" style={{ padding: 16 }}>
            {recentTransitions.length > 0 ? (
              <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
                {recentTransitions.map((t) => (
                  <div
                    key={t.id}
                    className="dashboard-row-item"
                    style={{
                      display: 'flex',
                      justifyContent: 'space-between',
                      alignItems: 'center',
                      padding: '10px 14px',
                      background: 'var(--paper-2, #f8fafc)',
                      border: '1px solid var(--line-soft, #e2e8f0)',
                      borderRadius: 8,
                    }}
                  >
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <span className="chip" style={{ fontSize: 11, fontWeight: 700 }}>
                        {t.type}
                      </span>
                      <b>{t.ref}</b>
                      <span style={{ fontSize: 12, color: 'var(--muted, #64748b)' }}>
                        {t.transition}
                      </span>
                      {t.from && (
                        <span style={{ fontSize: 12, color: 'var(--muted, #64748b)' }}>
                          ({t.from} ← <b>{t.to}</b>)
                        </span>
                      )}
                      {!t.from && (
                        <span style={{ fontSize: 12, color: 'var(--c-blue, #2563eb)' }}>
                          (الحالة: <b>{t.to}</b>)
                        </span>
                      )}
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12, fontSize: 12, color: 'var(--muted, #64748b)' }}>
                      <span>بواسطة: <b>{t.actor}</b></span>
                      <span>{t.time}</span>
                    </div>
                  </div>
                ))}
              </div>
            ) : (
              <div className="empty" style={{ padding: 24 }}>
                <Icon name="check" />
                <b>لا حركات مسجلة في جدول الانتقالات بعد</b>
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
};

export default AdminReports;

