import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import { BarChart } from '@/components/babylon/admin-charts';
import { CONSULTS, cTone } from '@/lib/employee-data';
import { maskClient } from '@/lib/admin-data';

// يطابق adConsultsView + cKPIs في index (82).html

const by = (s: string) => CONSULTS.filter((c) => c.status === s).length;

const openConsult = (ref: string) =>
  router.visit(`/admin/consult?ref=${encodeURIComponent(ref)}`);

const AdminConsults: React.FC = () => {
  const late = CONSULTS.filter((c) =>
    (c.mins || 0) > 100 && c.status.indexOf('جاهز') < 0 && c.status.indexOf('محال') < 0 && c.status.indexOf('محول') < 0
  ).length;
  const toCase = CONSULTS.filter((c) => c.status === 'محولة إلى قضية').length;
  const conv = Math.round((toCase / CONSULTS.length) * 100);

  const stats: StatItem[] = [
    ['t-blue', 'folder', CONSULTS.length, 'إجمالي الاستشارات'],
    ['t-cyan', 'info', by('جديدة'), 'جديدة'],
    ['t-red', 'clock', late, 'متأخرة'],
    ['t-amber', 'user', by('قيد مراجعة الموظف'), 'قيد مراجعة الموظفين'],
    ['t-cyan', 'info', by('قيد معالجة الفريق القانوني'), 'قيد الفريق القانوني'],
    ['t-green', 'scale', by('جاهزة للمحامي'), 'جاهزة للمحامين'],
    ['t-blue', 'exec', toCase, 'محولة إلى قضايا'],
    ['t-green', 'check', `${conv}%`, 'نسبة التحويل'],
  ];

  // أداء الموظفين
  const emp: Record<string, number> = {};
  CONSULTS.forEach((c) => { if (c.employee && c.employee !== '—') emp[c.employee] = (emp[c.employee] || 0) + 1; });
  const empData: [string, number][] = Object.keys(emp).map((k) => [k, emp[k]]);

  // توزيع المحامين
  const law: Record<string, number> = {};
  CONSULTS.forEach((c) => { if (c.lawyer && c.lawyer !== '—') law[c.lawyer] = (law[c.lawyer] || 0) + 1; });
  const lawData: [string, number][] = Object.keys(law).length ? Object.keys(law).map((k) => [k, law[k]]) : [['—', 0]];

  // سجل التدقيق
  const audit = CONSULTS.flatMap((c) => c.audit.map((a) => ({ ref: c.ref, ...a }))).slice(0, 8);

  return (
    <>
      <div className="greet">
        <h2>إدارة الاستشارات — الإدارة العليا</h2>
        <p>متابعة لحظية لجميع الاستشارات وأداء الموظفين والمحامين ونسبة التحويل وسجل التدقيق.</p>
      </div>

      <StatRow items={stats} />

      <BarChart title="أداء الموظفين (عدد الاستشارات)" data={empData.length ? empData : [['—', 0]]} />
      <BarChart title="توزيع الاستشارات على المحامين" data={lawData} />

      <div className="card">
        <div className="card-h"><h3>سجل التدقيق (Audit Log)</h3><span className="sub">أحدث التعديلات</span></div>
        <div className="card-b">
          {audit.length ? audit.map((a, i) => (
            <div key={i} className="item">
              <div className="iico"><Icon name="info" /></div>
              <div className="imeta">
                <b>{a.ref} · {a.field}</b>
                <span style={{ display: 'block', marginTop: 2 }}>{a.user} · {a.before} ← {a.after}</span>
                <span style={{ color: 'var(--muted)', fontSize: 11 }}>{a.time}</span>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="info" /><b>لا تعديلات بعد</b></div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>جميع الاستشارات</h3><span className="sub">{CONSULTS.length}</span></div>
        <div className="card-b">
          {CONSULTS.map((c) => (
            <div key={c.ref} className="item">
              <div className="iico"><Icon name="folder" /></div>
              <div className="imeta">
                <b>{c.ref} — {maskClient(c.client)}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>
                  {c.subject} · موظف: {c.employee} · محامٍ: {c.lawyer}
                </span>
              </div>
              <div className="iact">
                <span className={`mq-priority ${c.priority}`}>{c.priority}</span>
                <Badge text={c.status} tone={cTone(c.status)} />
                <button className="btn soft sm" onClick={() => openConsult(c.ref)} type="button">
                  <Icon name="out" /> فتح وتدخّل
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </>
  );
};

export default AdminConsults;
