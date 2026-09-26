import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import type {Appt} from '@/lib/data';
import Icon from '@/lib/icons';
import { useSettings } from '@/lib/settings';

// يطابق viewAppts + openAppt (بطاقة .apptx) في index (21).html — ببيانات حقيقيّة

// اشتقاق المكان من بيانات الموعد الحقيقيّة (المكان/القناة)
function apptPlace(a: Appt) {
  const remote =
    a.type.includes('مرئية') ||
    a.type.includes('هاتفية') ||
    a.place.includes('إلكتروني') ||
    a.place.includes('هاتفية') ||
    a.place.includes('بُعد');
  const addr = remote ? 'جلسة عن بُعد — يُرسل الرابط قبل الموعد' : a.place;
  const chip = remote ? 'عن بُعد' : a.place;

  return { remote, addr, chip };
}

// بطاقة الموعد الغنيّة (.apptx) — تطابق openAppt، ببيانات حقيقيّة
const ApptCard: React.FC<{ a: Appt }> = ({ a }) => {
  const toast = useToast();
  // هويّة المكتب من الإعدادات — كانت نسخةً منقوشة من بطاقة PDF (`AppointmentCardPdf`) تتخلّف عنها
  const { office_name, office_url, office_phone } = useSettings();
  const p = apptPlace(a);
  const paid = a.pay === 'مدفوع';
  // رابط الجلسة المرئية الحقيقي بالمنصّة — كان يُنسخ رابط مختلق (salaselbabel.net/APT-…) لا مسار له
  const copyLink = () => {
    if (!a.joinLink) {
return;
}

    try {
      navigator.clipboard?.writeText(a.joinLink).then(
        () => toast('تم نسخ رابط الجلسة'),
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
          {/* شعار المكتب وحده — نظير نسخة PDF (`AppointmentCardPdf`) */}
          <div className="apptx-brand">
            <span className="apptx-logo"><img src="/images/021.png" alt={office_name} /></span>
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
            {/* رمزٌ حقيقيّ من الخادم (المولّد الوحيد `Support\Qr`) يحمل رابط التحقّق الموقَّع — كان نقشاً
                زخرفيّاً لا يُقرأ تحته أمرٌ بمسحه لتأكيد الحضور، والمسح لا يسجّل حضوراً بل يُثبت البطاقة */}
            <div className="qrbox">
              <img
                src={`/appointments/${encodeURIComponent(a.id)}/qr.svg`}
                alt="رمز التحقّق من بطاقة الموعد"
                width={108}
                height={108}
              />
            </div>
            <p>امسح للتحقّق من البطاقة<br />مرجع الموعد {a.id}</p>
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
                <div className="rv">{a.lawyer || '—'}</div>
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
          <span>{office_url} · {office_phone}</span>
          <span>يُرجى الحضور قبل الموعد بـ15 دقيقة وإحضار المستندات المطلوبة</span>
        </div>
      </div>
      <div className="apptx-actions">
        {a.joinLink && (
          <button className="btn soft" type="button" onClick={copyLink}>
            <Icon name="link" /> نسخ رابط الجلسة
          </button>
        )}
        <a
          className="btn soft"
          href={`/appointments/${encodeURIComponent(a.id)}/card.pdf`}
          download={`appointment-${a.id}.pdf`}
          target="_blank"
          rel="noopener noreferrer"
        >
          <Icon name="download" /> تحميل PDF
        </a>
      </div>
    </>
  );
};

const ApptItem: React.FC<{ a: Appt; onOpen: (a: Appt) => void }> = ({ a, onOpen }) => (
  <div className="item">
    <div className="item-top">
      <div className="iico"><Icon name={a.ico} /></div>
      <div className="imeta">
        <b>{a.type}</b>
        <div style={{ display: 'flex', alignItems: 'center', gap: 6, flexWrap: 'wrap', marginTop: 4 }}>
          <span style={{ color: 'var(--ink)', fontWeight: 600, fontSize: 12 }}>{a.day} · {a.time}</span>
          <span style={{ color: 'var(--muted)', fontSize: 12 }}>· {a.lawyer || '—'}</span>
          <span style={{ color: 'var(--muted)', fontSize: 12 }}>· {a.place}</span>
        </div>
      </div>
    </div>
    <div className="iact">
      <Badge text={a.status} tone={a.tone} />
      {/* الخادم يخدم البطاقة بلا شرط زمني — الماضية كانت بلا أي زرّ */}
      <button className="btn soft sm" type="button" onClick={() => onOpen(a)}>
        <Icon name="ticket" /> بطاقة الموعد
      </button>
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
        <div className="card-h">
          <h3>المواعيد السابقة</h3>
          <span className="sub">{past.length} مواعيد</span>
        </div>
        <div className="card-b">
          {past.length ? (
            past.map((a) => <ApptItem key={a.id} a={a} onOpen={setSel} />)
          ) : (
            <div className="empty"><Icon name="clock" /><b>لا مواعيد سابقة</b></div>
          )}
        </div>
      </div>

      <Modal title="بطاقة الموعد" open={!!sel} onClose={() => setSel(null)}>
        {sel && <ApptCard a={sel} />}
      </Modal>
    </>
  );
};

export default Appointments;
