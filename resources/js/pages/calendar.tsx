import React from 'react';
import Icon from '@/lib/icons';

// يطابق viewCalendar في index (82).html — يونيو 2026

const DOW = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
const EVENTS: Record<number, string[]> = {
  24: ['today'],
  29: ['ev-appt', 'ev-meet'],
  30: ['ev-inv'],
};

const UPCOMING: [string, string, string][] = [
  ['cal', 'استشارة مرئية مع أ. سارة القحطاني', 'الاثنين 29 يونيو · 11:30 ص'],
  ['video', 'اجتماع الاستشارة المرئية', 'الاثنين 29 يونيو · 11:30 ص'],
  ['office', 'استشارة حضورية — الرياض', 'الأربعاء 01 يوليو · 01:00 م'],
];

const Calendar: React.FC = () => {
  const y = 2026, m = 5; // يونيو
  const first = new Date(y, m, 1).getDay();
  const days = new Date(y, m + 1, 0).getDate();

  const cells: React.ReactNode[] = [];
  for (let i = 0; i < first; i++) {
    cells.push(<div key={`b${i}`} className="day blank" />);
  }
  for (let d = 1; d <= days; d++) {
    const e = EVENTS[d] || [];
    const today = e.includes('today');
    const dots = e.filter((x) => x.indexOf('ev') === 0);
    cells.push(
      <div key={d} className={`day ${today ? 'today' : ''}`}>
        {d}
        {dots.length > 0 && (
          <div className="evt">
            {dots.map((c) => <i key={c} className={c} />)}
          </div>
        )}
      </div>
    );
  }

  return (
    <div className="grid-2">
      <div className="card">
        <div className="card-h"><h3>يونيو 2026</h3></div>
        <div className="card-b" style={{ padding: '14px 18px' }}>
          <div className="cal">
            {DOW.map((d) => <div key={d} className="dow">{d}</div>)}
            {cells}
          </div>
          <div style={{ display: 'flex', gap: 16, marginTop: 14, fontSize: 12, color: 'var(--muted)', flexWrap: 'wrap' }}>
            <span style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              <i className="ev-appt" style={{ width: 8, height: 8, borderRadius: '50%', display: 'inline-block' }} /> موعد
            </span>
            <span style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              <i className="ev-meet" style={{ width: 8, height: 8, borderRadius: '50%', display: 'inline-block' }} /> اجتماع
            </span>
            <span style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              <i className="ev-inv" style={{ width: 8, height: 8, borderRadius: '50%', display: 'inline-block' }} /> استحقاق فاتورة
            </span>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>القادم</h3>
          <span className="sub">مواعيد · اجتماعات · استشارات</span>
        </div>
        <div className="card-b">
          {UPCOMING.map(([ico, title, when]) => (
            <div key={title} className="item">
              <div className="iico"><Icon name={ico} /></div>
              <div className="imeta">
                <b>{title}</b>
                <span>{when}</span>
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export default Calendar;
