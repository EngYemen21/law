import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import {
  TILES,
  VIEW_ROUTE,
  openTickets,
  upAppts,
  upMeet,
  dueInv,
} from '@/lib/data';

// يطابق viewHome في index (82).html

const JOURNEY = [
  'فتح تذكرة', 'مراجعة الطلب', 'طلب المرفقات', 'الإحالة للقسم',
  'دراسة مبدئية', 'حجز استشارة', 'دفع الفاتورة', 'اختيار الموعد',
  'بطاقة الموعد', 'حضور الاستشارة', 'استلام الملخص', 'متابعة الطلب',
];
const CUR = 4;

const STATS = [
  { tone: 't-blue', icon: 'folder', num: openTickets, lbl: 'التذاكر المفتوحة', view: 'tickets' },
  { tone: 't-cyan', icon: 'cal', num: upAppts, lbl: 'المواعيد القادمة', view: 'appts' },
  { tone: 't-green', icon: 'video', num: upMeet, lbl: 'الاجتماعات القادمة', view: 'meetings' },
  { tone: 't-amber', icon: 'card', num: dueInv, lbl: 'الفواتير المستحقة', view: 'invoices' },
];

const go = (view: string) => {
  const route = VIEW_ROUTE[view];
  if (route) router.visit(route);
};

const Dashboard: React.FC = () => (
  <>
    <div className="greet">
      <h2>أهلاً عبدالله 👋</h2>
      <p>هذه نظرة سريعة على طلباتك ومواعيدك لدى المكتب.</p>
    </div>

    <div className="stats">
      {STATS.map((s) => (
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
        <button
          key={t.view}
          className={`tile ${t.feat ? 'feat' : ''}`}
          onClick={() => go(t.view)}
          type="button"
        >
          <div className="ti"><Icon name={t.icon} /></div>
          <b>{t.title}</b>
          <span>{t.sub}</span>
        </button>
      ))}
    </div>

    <div className="sec-head">
      <h3>رحلة طلبك داخل النظام</h3>
      <span className="crumb">التذكرة SB-2026-1042 · {JOURNEY[CUR]}</span>
    </div>
    <div className="journey">
      <div className="journey-track">
        {JOURNEY.map((x, i) => (
          <div
            key={x}
            className={`jstep ${i < CUR ? 'done' : i === CUR ? 'cur' : ''}`}
          >
            <div className="jline" />
            <div className="jdot">
              {i < CUR ? <Icon name="check" /> : i + 1}
            </div>
            <div className="jt">{x}</div>
          </div>
        ))}
      </div>
    </div>
  </>
);

export default Dashboard;
