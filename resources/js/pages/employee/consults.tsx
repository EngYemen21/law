import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import StatRow, { type StatItem } from '@/components/babylon/StatRow';
import FlowLine from '@/components/babylon/FlowLine';
import {
  CONSULTS,
  CONSULT_FLOW,
  cStage,
  cTone,
  maskClient,
} from '@/lib/employee-data';

// يطابق emConsultsView + cKPIs في index (82).html

const by = (s: string) => CONSULTS.filter((c) => c.status === s).length;
const done = CONSULTS.filter((c) =>
  c.status === 'منتهية' || c.status === 'محولة إلى قضية' || c.status === 'محالة للمحامي'
).length;
const avg = Math.round(CONSULTS.reduce((a, c) => a + (c.mins || 0), 0) / CONSULTS.length);

const openConsult = (ref: string) =>
  router.visit(`/employee/consult?ref=${encodeURIComponent(ref)}`);

const EmployeeConsults: React.FC = () => {
  const stats: StatItem[] = [
    ['t-blue', 'folder', by('جديدة'), 'جديدة'],
    ['t-cyan', 'user', by('قيد مراجعة الموظف'), 'قيد المراجعة'],
    ['t-amber', 'clock', by('بانتظار استكمال البيانات'), 'بانتظار البيانات'],
    ['t-cyan', 'info', by('قيد معالجة الفريق القانوني'), 'قيد الفريق القانوني'],
    ['t-amber', 'check', by('بانتظار اعتماد الموظف'), 'بانتظار الاعتماد'],
    ['t-green', 'scale', by('جاهزة للمحامي'), 'جاهزة للمحامي'],
    ['t-blue', 'exec', done, 'منجزة'],
    ['t-grey', 'clock', `${avg} د`, 'متوسط الزمن'],
  ];

  return (
    <>
      <div className="greet">
        <h2>إدارة الاستشارات</h2>
        <p>لوحة استقبال ومعالجة الاستشارات: المراجعة، الفريق القانوني، الاعتماد، والإحالة للمحامي.</p>
      </div>

      <StatRow items={stats} />

      <div className="card">
        <div className="card-h">
          <h3>الاستشارات</h3>
          <span className="sub">{CONSULTS.length} استشارة</span>
        </div>
        <div className="card-b">
          {CONSULTS.map((c) => (
            <div key={c.ref} className="item">
              <div className="iico"><Icon name="folder" /></div>
              <div className="imeta">
                <b>{c.ref} — {maskClient(c.client)}</b>
                <span style={{ display: 'block', margin: '3px 0' }}>
                  {c.subject} · {c.type} · {c.received}
                </span>
                <span><FlowLine steps={CONSULT_FLOW} cur={cStage(c.status)} /></span>
              </div>
              <div className="iact">
                <span className={`mq-priority ${c.priority}`}>{c.priority}</span>
                <Badge text={c.status} tone={cTone(c.status)} />
                <button className="btn soft sm" onClick={() => openConsult(c.ref)} type="button">
                  <Icon name="out" /> فتح
                </button>
              </div>
            </div>
          ))}
        </div>
      </div>
    </>
  );
};

export default EmployeeConsults;
