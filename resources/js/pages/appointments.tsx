import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { Qr } from '@/components/babylon/admin-charts';
import { useToast } from '@/components/babylon/Toast';
import { type Appt } from '@/lib/data';
import { maskLawyer } from '@/lib/utils';

// يطابق viewAppts + openAppt (بطاقة .apptx) في index (21).html — ببيانات حقيقيّة

// اشتقاق المكان من بيانات الموعد الحقيقيّة (الفرع/القناة)
function apptPlace(a: Appt) {
  const remote =
    a.type.includes('مرئية') ||
    a.type.includes('هاتفية') ||
    a.branch.includes('إلكتروني') ||
    a.branch.includes('هاتفية') ||
    a.branch.includes('بُعد');
  const addr = remote ? 'جلسة عن بُعد — يُرسل الرابط قبل الموعد' : a.branch;
  const chip = remote ? 'عن بُعد' : a.branch;
  return { remote, addr, chip };
}

// بطاقة الموعد الغنيّة (.apptx) — تطابق openAppt، ببيانات حقيقيّة
const ApptCard: React.FC<{ a: Appt }> = ({ a }) => {
  const toast = useToast();
  const p = apptPlace(a);
  const paid = a.pay === 'مدفوع';
  const calHref =
    'https://calendar.google.com/calendar/render?action=TEMPLATE&text=' +
    encodeURIComponent(a.type);

  const copyLink = () => {
    const link = 'https://meet.salasel.sa/APT-' + a.id;
    try {
      navigator.clipboard?.writeText(link).then(
        () => toast('تم نسخ الرابط'),
        () => toast('تعذّر نسخ الرابط'),
      );
    } catch {
      toast('تعذّر نسخ الرابط');
    }
  };

  return (
    <>
      <div className="apptx">
        <div className="apptx-head">
          <div className="apptx-brand">
            <div className="apptx-logo">SB</div>
            <div>
              <b>سلاسل بابل لتقنية المعلومات</b>
              <span className="bs">SALASEL BABEL · المواعيد القانونية</span>
            </div>
          </div>
          <div className="apptx-title"><Icon name="cal" /> بطاقة موعد {a.type}</div>
          <div className="apptx-no">{a.id}</div>
          <div className="apptx-chips">
            <span className="apptx-chip"><Icon name="cal" /> {a.day}</span>
            <span className="apptx-chip"><Icon name="clock" /> {a.time}</span>
            <span className="apptx-chip"><Icon name="pin" /> {p.chip}</span>
          </div>
        </div>
        <div className="apptx-body">
          <div className="apptx-qr">
            <div className="qrbox"><Qr seed={a.id} /></div>
            <p>امسح لتأكيد الحضور<br />وبدء الجلسة</p>
          </div>
          <div className="apptx-rows">
            <div className="apptx-row">
              <div className="ri"><Icon name="user" /></div>
              <div className="rc">
                <div className="rl">العميل</div>
                <div className="rv">{a.client || '—'}</div>
              </div>
            </div>
            <div className="apptx-row">
              <div className="ri"><Icon name="scale" /></div>
              <div className="rc">
                <div className="rl">المحامي المكلّف</div>
                <div className="rv">{maskLawyer(a.lawyer)}</div>
              </div>
            </div>
            <div className="apptx-row">
              <div className="ri"><Icon name="doc" /></div>
              <div className="rc">
                <div className="rl">رقم الاستشارة</div>
                <div className="rv" style={{ direction: 'ltr', textAlign: 'right' }}>{a.consultRef || '—'}</div>
              </div>
            </div>
            <div className="apptx-row">
              <div className="ri"><Icon name="office" /></div>
              <div className="rc">
                <div className="rl">العنوان</div>
                <div className="rv">{p.addr}</div>
              </div>
            </div>
            <div className="apptx-row">
              <div className="ri"><Icon name="card" /></div>
              <div className="rc">
                <div className="rl">حالة السداد</div>
                <div className="rv">
                  <span className={'apptx-pay ' + (paid ? 'paid' : 'wait')}>
                    <Icon name="check" /> {a.pay || 'بانتظار السداد'}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div className="apptx-foot">
          <span>www.sb-legal.sa · 011 462 2277</span>
          <span>يُرجى الحضور قبل الموعد بـ15 دقيقة وإحضار المستندات المطلوبة</span>
        </div>
      </div>
      <div style={{ display: 'flex', gap: 9, marginTop: 16, flexWrap: 'wrap' }}>
        <a className="btn" target="_blank" rel="noopener" href={calHref}>
          <Icon name="calplus" /> أضف إلى Google Calendar
        </a>
        <button className="btn soft" type="button" onClick={copyLink}>
          <Icon name="link" /> نسخ الرابط
        </button>
        <a className="btn soft" href={`/appointments/${a.id}/card.pdf`}>
          <Icon name="download" /> تحميل PDF
        </a>
      </div>
    </>
  );
};

const ApptItem: React.FC<{ a: Appt; onOpen: (a: Appt) => void }> = ({ a, onOpen }) => (
  <div className="item">
    <div className="iico"><Icon name={a.ico} /></div>
    <div className="imeta">
      <b>{a.type}</b>
      <span>{a.day} · {a.time} · {maskLawyer(a.lawyer)} · {a.branch}</span>
    </div>
    <div className="iact">
      <Badge text={a.status} tone={a.tone} />
      {a.when === 'up' && (
        <button className="btn soft sm" type="button" onClick={() => onOpen(a)}>
          <Icon name="ticket" /> بطاقة الموعد
        </button>
      )}
    </div>
  </div>
);

const Appointments: React.FC<{ appointments: Appt[] }> = ({ appointments }) => {
  const [sel, setSel] = useState<Appt | null>(null);
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
            up.map((a) => <ApptItem key={a.id} a={a} onOpen={setSel} />)
          ) : (
            <div className="empty"><Icon name="cal" /><b>لا مواعيد قادمة</b></div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>المواعيد السابقة</h3></div>
        <div className="card-b">
          {past.map((a) => <ApptItem key={a.id} a={a} onOpen={setSel} />)}
        </div>
      </div>

      <Modal title="بطاقة الموعد" open={!!sel} onClose={() => setSel(null)}>
        {sel && <ApptCard a={sel} />}
      </Modal>
    </>
  );
};

export default Appointments;
