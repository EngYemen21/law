import { router } from '@inertiajs/react';
import React from 'react';
import Badge from '@/components/babylon/Badge';
import Icon from '@/lib/icons';
import { TILES, VIEW_ROUTE } from '@/lib/data';
import type { Appt, Invoice } from '@/lib/data';

// لوحة العميل — عدّادات ورحلة آخر تذكرة حقيقية من الخادم

const JOURNEY = [
  'استلام الطلب', 'التحليل', 'الإحالة للقسم', 'الرأي القانوني',
  'حجز الاستشارة', 'الجلسة', 'النتيجة',
];

interface Props {
  name: string;
  counts: { openTickets: number; upAppts: number; upMeet: number; dueInv: number; overdueInv?: number; myExec: number };
  upcomingAppts: Appt[];
  dueInvoices: Invoice[];
  lastTicket: { no: string; step: number } | null;
}

const go = (view: string) => {
  const route = VIEW_ROUTE[view];
  if (route) router.visit(route);
};

const Dashboard: React.FC<Props> = ({ name, counts, upcomingAppts, dueInvoices, lastTicket }) => {
  const stats = [
    { tone: 't-blue', icon: 'folder', num: counts.openTickets, lbl: 'التذاكر المفتوحة', view: 'tickets' },
    { tone: 't-cyan', icon: 'cal', num: counts.upAppts, lbl: 'المواعيد القادمة', view: 'appts' },
    { tone: 't-green', icon: 'video', num: counts.upMeet, lbl: 'الاجتماعات القادمة', view: 'meetings' },
    // المتأخرة تصبغ العدّاد أحمر وتُذكر صراحةً — كانت مدموجة في «المستحقة» فلا يميّز العميل العاجل
    { tone: counts.overdueInv ? 't-red' : 't-amber', icon: 'card', num: counts.dueInv, lbl: counts.overdueInv ? `الفواتير المستحقة (${counts.overdueInv} متأخرة)` : 'الفواتير المستحقة', view: 'invoices' },
    { tone: 't-grey', icon: 'exec', num: counts.myExec, lbl: 'ملفات التنفيذ', view: 'execs' },
  ];
  const cur = lastTicket ? Math.min(lastTicket.step, JOURNEY.length - 1) : -1;

  return (
    <>
      <div className="hero">
        <h2>أهلاً {name} 👋</h2>
        <p>هذه نظرة سريعة على طلباتك ومواعيدك وفواتيرك لدى المكتب.</p>
        <div className="hero-cta">
          <button className="hero-b" onClick={() => go('newticket')} type="button">
            <Icon name="plus" /> فتح تذكرة
          </button>
          <button className="hero-b ghost" onClick={() => go('book')} type="button">
            <Icon name="calplus" /> حجز استشارة
          </button>
          <button className="hero-b ghost" onClick={() => go('execs')} type="button">
            <Icon name="exec" /> طلب تنفيذ
          </button>
        </div>
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

      <div className="nx-grid">
        <div className="card">
          <div className="card-h">
            <h3>مواعيدك القادمة</h3>
            <span className="sub" style={{ cursor: 'pointer' }} onClick={() => go('appts')}>عرض الكل</span>
          </div>
          <div className="card-b">
            {upcomingAppts.length === 0 ? (
              <div className="nx-empty">
                لا مواعيد قادمة — <a onClick={() => go('book')}>احجز استشارة</a>
              </div>
            ) : (
              upcomingAppts.map((a) => (
                <div key={a.id} className="nx-item" onClick={() => go('appts')}>
                  <div className="nx-ic"><Icon name={a.ico || 'cal'} /></div>
                  <div className="nx-main">
                    <div className="nx-t">استشارة {a.type}</div>
                    <div className="nx-s">{a.day} · {a.time} · {a.branch}</div>
                  </div>
                  <Badge text={a.status} tone={a.tone} />
                </div>
              ))
            )}
          </div>
        </div>

        <div className="card">
          <div className="card-h">
            <h3>فواتير بانتظار السداد</h3>
            <span className="sub" style={{ cursor: 'pointer' }} onClick={() => go('invoices')}>عرض الكل</span>
          </div>
          <div className="card-b">
            {dueInvoices.length === 0 ? (
              <div className="nx-empty">لا فواتير مستحقة ✔</div>
            ) : (
              dueInvoices.map((v) => (
                <div key={v.no} className="nx-item" onClick={() => go('invoices')}>
                  <div className="nx-ic amber"><Icon name="card" /></div>
                  <div className="nx-main">
                    <div className="nx-t">{v.no} — {v.amount.toLocaleString('en-US')} ريال</div>
                    <div className="nx-s">{v.desc} · {v.due}</div>
                  </div>
                  <Badge text={v.status} tone={v.tone} />
                </div>
              ))
            )}
          </div>
        </div>
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
