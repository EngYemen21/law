import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { CLIENTS, LAWYERS, SCHEDULE_TIMES } from '@/lib/employee-data';

// يطابق emScheduleView + emPickSlot في index (82).html

const EmployeeSchedule: React.FC = () => {
  const toast = useToast();
  const [sel, setSel] = useState<number | null>(null);

  return (
    <div className="card">
      <div className="card-h"><h3>جدولة موعد جديد</h3></div>
      <div className="card-b" style={{ padding: 18 }}>
        <div className="picker-grid">
          <div className="field">
            <label>العميل</label>
            <select>{CLIENTS.map((c) => <option key={c.name}>{c.name}</option>)}</select>
          </div>
          <div className="field">
            <label>المستشار</label>
            <select>{LAWYERS.map((l) => <option key={l.name}>{l.name}</option>)}</select>
          </div>
        </div>
        <div className="picker-grid">
          <div className="field">
            <label>نوع الاستشارة</label>
            <select>
              <option>حضورية</option>
              <option>مرئية</option>
              <option>هاتفية</option>
            </select>
          </div>
          <div className="field">
            <label>اليوم</label>
            <select>
              <option>الأحد 28 يونيو</option>
              <option>الاثنين 29 يونيو</option>
            </select>
          </div>
        </div>
        <label style={{ display: 'block', fontSize: 13, fontWeight: 700, margin: '4px 0 8px' }}>الوقت</label>
        <div className="slots">
          {SCHEDULE_TIMES.map((t, i) => (
            <button
              key={t}
              className={`slot${sel === i ? ' sel' : ''}`}
              onClick={() => setSel(i)}
              type="button"
            >
              {t}
            </button>
          ))}
        </div>
        <div className="cal-note">
          <Icon name="check" /> يتحقق النظام من التعارض عبر Google Calendar
        </div>
        <button
          className="btn block"
          style={{ marginTop: 14 }}
          onClick={() => toast('تم إنشاء الموعد ومزامنته مع التقويم')}
          type="button"
        >
          <Icon name="calplus" /> تأكيد الجدولة
        </button>
      </div>
    </div>
  );
};

export default EmployeeSchedule;
