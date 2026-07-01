import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import { type Appt } from '@/lib/data';
import { maskLawyer } from '@/lib/utils';

// يطابق viewAppts في index (82).html

const ApptItem: React.FC<{ a: Appt }> = ({ a }) => {
  const toast = useToast();
  return (
    <div className="item">
      <div className="iico"><Icon name={a.ico} /></div>
      <div className="imeta">
        <b>استشارة {a.type}</b>
        <span>{a.day} · {a.time} · {maskLawyer(a.lawyer)} · {a.branch}</span>
      </div>
      <div className="iact">
        <Badge text={a.status} tone={a.tone} />
        {a.when === 'up' && (
          <button className="btn soft sm" type="button" onClick={() => toast('تم فتح بطاقة الموعد')}>
            <Icon name="ticket" /> بطاقة الموعد
          </button>
        )}
      </div>
    </div>
  );
};

const Appointments: React.FC<{ appointments: Appt[] }> = ({ appointments }) => {
  const up = appointments.filter((a) => a.when === 'up');
  const past = appointments.filter((a) => a.when === 'past');

  return (
    <>
      <div className="card">
        <div className="card-h">
          <h3>المواعيد القادمة</h3>
          <span className="sub">{up.length} مواعيد</span>
        </div>
        <div className="card-b">
          {up.length ? (
            up.map((a) => <ApptItem key={a.id} a={a} />)
          ) : (
            <div className="empty"><Icon name="cal" /><b>لا مواعيد قادمة</b></div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>المواعيد السابقة</h3></div>
        <div className="card-b">
          {past.map((a) => <ApptItem key={a.id} a={a} />)}
        </div>
      </div>
    </>
  );
};

export default Appointments;
