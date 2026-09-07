import React from 'react';
import Icon from '@/lib/icons';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { Bars, BarChart } from '@/components/babylon/admin-charts';
import type { BarDatum } from '@/lib/admin-data';
import { type FullMeetingCard } from '@/lib/meeting-ui';

// «—» لا صفر: القياس الغائب لا يُعرض رقماً يدّعي أنّه وقع
const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${v}%`);

// تحليلات وتغطية Zoom وقاعدة البيانات لصفحة تقارير الاجتماعات
interface Analytics {
  monthlyTrend: (BarDatum & { hours?: number })[];
  statusCounts?: Record<string, number>;
  attendanceByLawyer: [string, number][];
  // null = لم يُقَس (لا مدد فعلية / لا مهامّ) — يُعرض «—»
  avgActualMinutes: number | null;
  decisionRate: number | null;
  tasksFromDecisions: number;
  doneTasksCount?: number;
  totalZoomHours?: number;
  aiSummaryCount?: number;
  aiCoverageRate?: number | null;
  recordingCount?: number;
  recordingCoverageRate?: number | null;
  audioCount?: number;
}

const AdminMeetReports: React.FC<{ meetings: FullMeetingCard[]; analytics: Analytics }> = ({ meetings, analytics }) => {
  const byType: Record<string, number> = {};
  meetings.forEach((m) => { byType[m.type] = (byType[m.type] || 0) + 1; });
  const typeData: [string, number][] = Object.keys(byType).length
    ? Object.keys(byType).map((k) => [k, byType[k]])
    : [['—', 0]];

  // توزيع الاجتماعات حسب العميل/الجهة
  const byClient: Record<string, number> = {};
  meetings.forEach((m) => { byClient[m.client] = (byClient[m.client] || 0) + 1; });
  const clientData: [string, number][] = Object.keys(byClient).length
    ? Object.keys(byClient).map((k) => [k, byClient[k]])
    : [['—', 0]];

  // توزيع الاجتماعات حسب المحامي المسؤول
  const byLawyer: Record<string, number> = {};
  meetings.forEach((m) => { if (m.lawyer && m.lawyer !== '—') byLawyer[m.lawyer] = (byLawyer[m.lawyer] || 0) + 1; });
  const lawyerData: [string, number][] = Object.keys(byLawyer).length
    ? Object.keys(byLawyer).map((k) => [k, byLawyer[k]])
    : [['—', 0]];

  const done = meetings.filter((m) => m.status === 'منتهٍ');
  // المتوسّط على المقيس وحده — طيُّ غير المقيس صفراً كان يجرّ النسبة للأسفل ببياناتٍ ليست بيانات
  const measured = done.filter((m) => m.presenceRate !== null);
  const att = measured.length
    ? Math.round(measured.reduce((a, m) => a + (m.presenceRate ?? 0), 0) / measured.length)
    : null;
  const cancelled = meetings.filter((m) => m.status === 'ملغى').length;
  const missed = meetings.filter((m) => m.status === 'لم ينعقد').length;
  const postponed = meetings.filter((m) => m.status === 'مؤجل').length;
  const up = meetings.filter((m) => m.status === 'قادم').length;
  // «بانتظار التأكيد» حالة يتيمة منذ إلغاء تأكيد العميل — لا يكتبها أي مسار حيّ فكانت البطاقة صفراً أبداً
  // const pendingConfirm = meetings.filter((m) => m.status === 'بانتظار التأكيد').length;
  const approved = meetings.filter((m) => m.approve === 'معتمد').length;

  const stats: StatItem[] = [
    ['t-blue', 'video', meetings.length, 'إجمالي الاجتماعات'],
    ['t-cyan', 'video', up, 'قادمة'],
    // ['t-amber', 'clock', pendingConfirm, 'بانتظار التأكيد'],
    ['t-green', 'user', att !== null ? `${att}%` : '—', 'متوسّط البقاء'],
    ['t-green', 'check', approved, 'معتمدة'],
    ['t-grey', 'clock', done.length, 'منتهية'],
    ['t-blue', 'doc', done.filter((m) => m.minutes).length, 'بمحضر موثّق'],
    ['t-red', 'out', cancelled + missed, 'ملغاة / لم تنعقد'],
    ['t-amber', 'clock', postponed, 'مؤجلة'],
  ];

  return (
    <>
      <div className="greet">
        <h2>تقارير وإحصاءات الاجتماعات</h2>
        <p>مؤشرات أداء تفاعلية شاملة: بيانات Zoom الفعلية، تغطية الذكاء الاصطناعي، تسجيلات الصوت والفيديو، ومعدلات تنفيذ القرارات.</p>
      </div>

      <StatRow items={stats} />

      {/* 🤖 لوحة تغطية الذكاء الاصطناعي وتسجيلات Zoom */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>تغطية Zoom و الذكاء الاصطناعي (AI Companion)</h3>
          <span className="sub">مؤشرات الأتمتة المشفوعة بالسجلات الحقيقية</span>
        </div>
        <div className="card-b" style={{ padding: 18 }}>
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: 16 }}>
            <div style={{ background: 'var(--surface-soft, #f8fafc)', padding: 14, borderRadius: 10, border: '1px solid var(--line-soft, #e2e8f0)' }}>
              <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 4 }}><Icon name="doc" /> تغطية ملخصات AI Companion</div>
              <div style={{ fontSize: 22, fontWeight: 800, color: 'var(--primary)' }}>{pct(analytics.aiCoverageRate)}</div>
              <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>
                تم استخراج {analytics.aiSummaryCount ?? 0} ملخص ذكي تلقائيًا
              </div>
            </div>

            <div style={{ background: 'var(--surface-soft, #f8fafc)', padding: 14, borderRadius: 10, border: '1px solid var(--line-soft, #e2e8f0)' }}>
              <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 4 }}><Icon name="video" /> أرشيف الفيديو والمرئيات</div>
              <div style={{ fontSize: 22, fontWeight: 800, color: '#10B981' }}>{pct(analytics.recordingCoverageRate)}</div>
              <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>
                {analytics.recordingCount ?? 0} اجتماع بدعم تسجيل سحابي
              </div>
            </div>

            <div style={{ background: 'var(--surface-soft, #f8fafc)', padding: 14, borderRadius: 10, border: '1px solid var(--line-soft, #e2e8f0)' }}>
              <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 4 }}><Icon name="clock" /> إجمالي ساعات الجلسات المرئية</div>
              <div style={{ fontSize: 22, fontWeight: 800, color: '#8B5CF6' }}>{analytics.totalZoomHours ?? 0} ساعة</div>
              <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>
                من وقت الحضور الفعلي في Zoom
              </div>
            </div>

            <div style={{ background: 'var(--surface-soft, #f8fafc)', padding: 14, borderRadius: 10, border: '1px solid var(--line-soft, #e2e8f0)' }}>
              <div style={{ fontSize: 13, color: 'var(--muted)', marginBottom: 4 }}><Icon name="check" /> معدل إنجاز المهام المشتقة</div>
              <div style={{ fontSize: 22, fontWeight: 800, color: '#F59E0B' }}>{pct(analytics.decisionRate)}</div>
              <div style={{ fontSize: 11, color: 'var(--muted)', marginTop: 4 }}>
                {analytics.doneTasksCount ?? 0} من {analytics.tasksFromDecisions} مهام مُنجزة
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* 📊 مقاييس أداء الجلسات والتنفيذ */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>مقاييس أداء الجلسات والتسجيلات</h3>
          <span className="sub">محسوبة مباشرة من بيانات Zoom الحقيقية وقاعدة البيانات</span>
        </div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="kpi-row">
            <span className="t">متوسط مدة الحضور الفعلية للجلسة (من Zoom)</span>
            <span className="v">{analytics.avgActualMinutes !== null ? `${analytics.avgActualMinutes} دقيقة` : '—'}</span>
          </div>
          <div className="kpi-row">
            <span className="t">إجمالي التسجيلات الصوتية (M4A Audio Archive)</span>
            <span className="v">{analytics.audioCount ?? 0} ملف صوتي</span>
          </div>
          <div className="kpi-row">
            <span className="t">معدل تحويل قرارات الجلسات إلى مهام عملية</span>
            <span className="v">{pct(analytics.decisionRate)}</span>
          </div>
          <div className="kpi-row">
            <span className="t">عدد المهام المشتقة تلقائيًا من قرارات الجلسات</span>
            <span className="v">{analytics.tasksFromDecisions} مهام</span>
          </div>
        </div>
      </div>

      {/* 📈 اتجاه حجم الاجتماعات شهريًا */}
      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h">
          <h3>اتجاه حجم الاجتماعات (آخر 6 أشهر)</h3>
          <span className="sub">عدد الجلسات المرئية المنعقدة شهريًا</span>
        </div>
        <div className="card-b" style={{ padding: 18 }}>
          {analytics.monthlyTrend.some((d) => d.v > 0)
            ? <Bars data={analytics.monthlyTrend} />
            : <div className="empty"><Icon name="cal" /><b>لا اجتماعات في آخر 6 أشهر</b></div>}
        </div>
      </div>

      {/* 📊 الرسوم البيانية والتوزيعات التفصيلية */}
      <BarChart title="الاجتماعات حسب النوع والخدمة" data={typeData} />
      <BarChart title="الاجتماعات حسب العميل/الجهة" data={clientData} />
      <BarChart title="الاجتماعات حسب المحامي المسؤول" data={lawyerData} />
      <BarChart title="متوسط مدة الحضور الفعلية حسب المحامي (دقائق)" data={analytics.attendanceByLawyer.length ? analytics.attendanceByLawyer : [['—', 0]]} />
    </>
  );
};

export default AdminMeetReports;
