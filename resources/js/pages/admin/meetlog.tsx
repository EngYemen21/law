import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { attendanceLabel, fmtActualDuration, type FullMeetingCard } from '@/lib/meeting-ui';

// يطابق meetLogView في index (82).html — الأرشيف حقيقي من الخادم (بيانات Zoom/الويبهوك فقط)

const AdminMeetLog: React.FC<{ meetings: FullMeetingCard[] }> = ({ meetings }) => {
  const ended = meetings.filter((m) => m.status === 'منتهٍ');

  // بحث وفلترة (client-side — بيانات الإدارة محمّلة كاملةً)
  const [q, setQ] = useState('');
  const [lawyerF, setLawyerF] = useState('');
  const [fromD, setFromD] = useState('');
  const [toD, setToD] = useState('');
  const lawyerOpts = [...new Set(ended.map((m) => m.lawyer).filter((l) => l && l !== '—'))];

  const list = ended.filter((m) => {
    if (lawyerF && m.lawyer !== lawyerF) return false;
    if (q.trim()) {
      const hay = `${m.title} ${m.client} ${m.lawyer} ${m.caseRef || ''}`.toLowerCase();
      if (!hay.includes(q.trim().toLowerCase())) return false;
    }
    const day = m.startsAt ? m.startsAt.slice(0, 10) : '';
    if (fromD && (!day || day < fromD)) return false;
    if (toD && (!day || day > toD)) return false;
    return true;
  });

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>أرشيف الاجتماعات المنتهية: التسجيل المرئي والنص الكامل (من Zoom)، المحضر والقرارات، ومدة الحضور الفعلية — كلها من بيانات الجلسة الحقيقية.</p>
      </div>

      {/* بحث وفلترة — الإدارة العليا */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-b" style={{ padding: 12 }}>
          <div className="field" style={{ marginBottom: 8 }}>
            <input className="input" value={q} onChange={(e) => setQ(e.target.value)} placeholder="بحث بالعنوان أو العميل أو المحامي أو القضية…" />
          </div>
          <div className="picker-grid">
            <div className="field"><label>المحامي المسؤول</label>
              <select value={lawyerF} onChange={(e) => setLawyerF(e.target.value)}>
                <option value="">— الكل —</option>
                {lawyerOpts.map((l) => <option key={l} value={l}>{l}</option>)}
              </select>
            </div>
            <div className="field"><label>من تاريخ</label><input className="input" type="date" value={fromD} onChange={(e) => setFromD(e.target.value)} /></div>
            <div className="field"><label>إلى تاريخ</label><input className="input" type="date" value={toD} onChange={(e) => setToD(e.target.value)} /></div>
          </div>
          {(q || lawyerF || fromD || toD) && (
            <button className="btn soft sm" style={{ marginTop: 8 }} type="button" onClick={() => { setQ(''); setLawyerF(''); setFromD(''); setToD(''); }}>
              <Icon name="reply" /> مسح الفلاتر
            </button>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>أرشيف الاجتماعات</h3><span className="sub">{list.length} من {ended.length} اجتماع منتهٍ</span></div>
        <div className="card-b">
          {list.length ? list.map((m) => {
            const actual = fmtActualDuration(m.durationSec);
            return (
              <div key={m.id} className="item">
                <div className="iico"><Icon name="folder" /></div>
                <div className="imeta">
                  <b>{m.title}</b>
                  <span style={{ display: 'block', margin: '3px 0' }}>{m.type} · {m.when} · {m.client} · {m.lawyer !== '—' ? m.lawyer : 'بلا محامٍ'}</span>
                  <div className="prot-list" style={{ marginTop: 6 }}>
                    {m.recording
                      ? <a className="chip" href={m.recording} target="_blank" rel="noopener noreferrer"><Icon name="video" /> مشاهدة</a>
                      : <span className="chip" style={{ opacity: 0.5 }}>لا تسجيل</span>}
                    {/* تنزيلات خادمية مضغوطة — روابط Zoom السحابية صفحات مشاهدة لا ملفات */}
                    {m.recording && <a className="chip" href={`/admin/meetings/${m.dbId}/recording.zip`}><Icon name="download" /> الفيديو ZIP</a>}
                    {m.zoomAudioUrl && <a className="chip" href={`/admin/meetings/${m.dbId}/audio.zip`}><Icon name="download" /> الصوت ZIP</a>}
                    {(m.transcript || m.recording)
                      ? <a className="chip" href={`/admin/meetings/${m.dbId}/transcript`}><Icon name="doc" /> النص الكامل</a>
                      : <span className="chip" style={{ opacity: 0.5 }}>لا نصّ</span>}
                    {m.minutes
                      ? <a className="chip" onClick={() => router.visit(`/admin/meeting?id=${encodeURIComponent(m.id)}`)} style={{ cursor: 'pointer' }}><Icon name="doc" /> المحضر</a>
                      : <span className="chip" style={{ opacity: 0.5 }}>بلا محضر</span>}
                    {m.decisions.length
                      ? <a className="chip" onClick={() => router.visit(`/admin/meeting?id=${encodeURIComponent(m.id)}`)} style={{ cursor: 'pointer' }}><Icon name="check" /> القرارات ({m.decisions.length})</a>
                      : <span className="chip" style={{ opacity: 0.5 }}>بلا قرارات</span>}
                    {(attendanceLabel(m) || actual) && (
                      <span className="chip"><Icon name="user" /> {[attendanceLabel(m), actual].filter(Boolean).join(' · ')}</span>
                    )}
                    {/* شارة الاعتماد — يعرف المدقّق أيّ السجلات لم تُعتمد محاضرها بعد */}
                    <span className="chip" style={m.approve === 'معتمد' ? undefined : { color: 'var(--amber, #b45309)' }}>
                      <Icon name="check" /> {m.approve === 'معتمد' ? 'معتمد' : 'بانتظار الاعتماد'}
                    </span>
                  </div>
                </div>
                <div className="iact">
                  <button className="btn soft sm" onClick={() => router.visit(`/admin/meeting?id=${encodeURIComponent(m.id)}`)} type="button">
                    <Icon name="out" /> فتح السجل
                  </button>
                </div>
              </div>
            );
          }) : (
            <div className="empty"><Icon name="folder" /><b>لا اجتماعات منتهية مطابِقة</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminMeetLog;
