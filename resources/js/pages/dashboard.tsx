import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { TILES, VIEW_ROUTE } from '@/lib/data';

// لوحة العميل — عدّادات ورحلة آخر تذكرة حقيقية من الخادم

const JOURNEY = [
  'استلام الطلب', 'التحليل', 'الإحالة للقسم', 'الرأي القانوني',
  'حجز الاستشارة', 'الجلسة', 'النتيجة',
];

interface Props {
  name: string;
  counts: { openTickets: number; upAppts: number; upMeet: number; dueInv: number };
  lastTicket: { no: string; step: number } | null;
}

const go = (view: string) => {
  const route = VIEW_ROUTE[view];
  if (route) router.visit(route);
};

const Dashboard: React.FC<Props> = ({ name, counts, lastTicket }) => {
  const stats = [
    { tone: 't-blue', icon: 'folder', num: counts.openTickets, lbl: 'التذاكر المفتوحة', view: 'tickets' },
    { tone: 't-cyan', icon: 'cal', num: counts.upAppts, lbl: 'المواعيد القادمة', view: 'appts' },
    { tone: 't-green', icon: 'video', num: counts.upMeet, lbl: 'الاجتماعات القادمة', view: 'meetings' },
    { tone: 't-amber', icon: 'card', num: counts.dueInv, lbl: 'الفواتير المستحقة', view: 'invoices' },
  ];
  const cur = lastTicket ? Math.min(lastTicket.step, JOURNEY.length - 1) : -1;

  return (
    <>
      <div className="greet">
        <h2>أهلاً {name} 👋</h2>
        <p>هذه نظرة سريعة على طلباتك ومواعيدك لدى المكتب.</p>
      </div>

      <div className="stats">
        {stats.map((s) => (
          <div key={s.view} className={`stat ${s.tone}`} onClick={() => go(s.view)}>
            <div className="go"><Icon name="reply" /></div>
            <div className="si"><Icon name={s.icon} /></div>
            <div className="num">{s.num}</div>
            <div className="lbl">{s.lbl}</div>
          </div>
        ))}
      </div>

      <div className="sec-head"><h3>الخدمات الرئيسية</h3></div>
      <div className="tiles">
        {TILES.map((t) => (
          <button key={t.view} className={`tile ${t.feat ? 'feat' : ''}`} onClick={() => go(t.view)} type="button">
            <div className="ti"><Icon name={t.icon} /></div>
            <b>{t.title}</b>
            <span>{t.sub}</span>
          </button>
        ))}
      </div>

      {lastTicket && (
        <>
          <div className="sec-head">
            <h3>رحلة طلبك داخل النظام</h3>
            <span className="crumb">التذكرة {lastTicket.no} · {JOURNEY[cur]}</span>
          </div>
          <div className="journey">
            <div className="journey-track">
              {JOURNEY.map((x, i) => (
                <div key={x} className={`jstep ${i < cur ? 'done' : i === cur ? 'cur' : ''}`}>
                  <div className="jline" />
                  <div className="jdot">{i < cur ? <Icon name="check" /> : i + 1}</div>
                  <div className="jt">{x}</div>
                </div>
              ))}
            </div>
          </div>
        </>
      )}
    </>
  );
};

export default Dashboard;
