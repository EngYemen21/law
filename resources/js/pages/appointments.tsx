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

// طباعة بطاقة الموعد (تحاكي نمط printOffer → window.open + print) — ببيانات حقيقيّة
function printAppointment(a: Appt) {
  const p = apptPlace(a);
  const paid = a.pay === 'مدفوع';
  const payLabel = a.pay || 'بانتظار السداد';
  const rows: [string, string][] = [
    ['رقم الموعد', a.id],
    ['رقم الاستشارة', a.consultRef || '—'],
    ['نوع الاستشارة', a.type],
    ['التاريخ', a.day],
    ['الوقت', a.time],
    ['المكان', p.remote ? 'عن بُعد' : a.branch],
    ['العميل', a.client || '—'],
    ['المحامي المكلّف', maskLawyer(a.lawyer)],
    ['العنوان', p.addr],
    ['حالة السداد', payLabel],
  ];
  const html = `<html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>${a.id}</title>
    <style>body{font-family:Tahoma,Arial,sans-serif;padding:26px;color:#16245C}h2{color:#0A2A55;margin:0 0 4px}
    .sub{color:#5B6B85;font-size:13px;margin-bottom:14px}
    .atbl{width:100%;border-collapse:collapse;font-size:13px;margin-top:6px}
    .atbl th{background:#16245C;color:#fff;padding:8px 10px;text-align:right}
    .atbl td{padding:7px 10px;border-bottom:1px solid #E2E8EE;text-align:right}
    .atbl tr:nth-child(even) td{background:#F8FAFC}
    .pay{display:inline-block;padding:3px 12px;border-radius:99px;font-weight:800;font-size:12px;background:${paid ? '#E6F6EF' : '#FBF1E3'};color:${paid ? '#1E9D6B' : '#C0832B'}}
    .foot{margin-top:14px;padding:10px 14px;background:#16245C;color:#fff;border-radius:8px;font-size:12px;text-align:center}</style></head>
    <body><h2>سلاسل بابل لتقنية المعلومات — بطاقة موعد استشارة قانونية</h2>
    <div class="sub">${a.type} · ${a.day} · ${a.time}</div>
    <table class="atbl"><thead><tr><th>البند</th><th>التفاصيل</th></tr></thead><tbody>
    ${rows.map((r) => `<tr><td>${r[0]}</td><td>${r[0] === 'حالة السداد' ? `<span class="pay">${r[1]}</span>` : r[1]}</td></tr>`).join('')}
    </tbody></table>
    <div class="foot">يُرجى الحضور قبل الموعد بـ15 دقيقة وإحضار المستندات المطلوبة · www.sb-legal.sa · 011 462 2277</div></body></html>`;
  const w = window.open('', '_blank', 'width=800,height=900');
  if (!w) return;
  w.document.write(html);
  w.document.close();
  w.focus();
  w.print();
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
        <button className="btn soft" type="button" onClick={() => printAppointment(a)}>
          <Icon name="download" /> طباعة / PDF
        </button>
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
