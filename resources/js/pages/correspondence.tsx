import { router } from '@inertiajs/react';
import React, { useEffect, useState } from 'react';
import Badge from '@/components/babylon/Badge';
import FlowLine from '@/components/babylon/FlowLine';
import { useToast } from '@/components/babylon/Toast';
import { echo } from '@/lib/echo';
import { CORR_FLOW, corrTone, type CorrCard } from '@/lib/corr-ui';
import Icon from '@/lib/icons';

interface Props { role: string; base: string; corr: CorrCard }

const Row: React.FC<{ t: string; v: React.ReactNode }> = ({ t, v }) => (
  <div className="kpi-row"><span className="t">{t}</span><span className="v">{v}</span></div>
);

const Correspondence: React.FC<Props> = ({ role, base, corr }) => {
  const toast = useToast();
  const [c, setC] = useState<CorrCard>(corr);
  const [briefing, setBriefing] = useState(false);
  const [note, setNote] = useState('');
  const url = `${base}/${encodeURIComponent(c.id)}`;

  // بثّ لحظيّ لحالة المخاطبة
  useEffect(() => {
    echo.private(c.channelName).listen('.status', (e: { status: string; tone: string }) => {
      setC((prev) => ({ ...prev, status: e.status, tone: e.tone }));
    });
    return () => { echo.leave(c.channelName); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [c.channelName]);

  const act = (path: string, payload: Record<string, string> = {}) => {
    router.post(`${url}/${path}`, payload, { preserveScroll: true, onError: (er) => toast(Object.values(er)[0] ?? 'تعذّر تنفيذ الإجراء') });
  };
  const submitBrief = () => { if (note.trim()) act('brief', { note: note.trim() }); };

  const isAdmin = role === 'admin';

  const printLetter = () => {
    const html = `<html dir="rtl" lang="ar"><head><meta charset="utf-8"><title>${c.id}</title>
      <style>body{font-family:Tahoma,Arial,sans-serif;padding:30px;color:#16245C}h2{color:#0A2A55}
      .hd{border-bottom:2px solid #16245C;padding-bottom:10px;margin-bottom:16px}
      .bd{line-height:1.9;font-size:14px;white-space:pre-wrap}
      .sig{margin-top:24px;padding:10px 14px;background:#16245C;color:#fff;border-radius:8px;display:flex;justify-content:space-between;font-size:12px}</style></head>
      <body><div class="hd"><h2>النظام الإداري لمكاتب المحاماة</h2><div>مخاطبة رسميّة · ${c.id} · ${c.date}</div></div>
      <div>إلى: <b>${c.entity}</b></div><div>الموضوع: <b>${c.subject}</b></div>
      <div class="bd">${c.body}</div>
      ${c.reply ? `<div style="margin-top:16px"><b>ردّ الجهة:</b><div class="bd">${c.reply}</div></div>` : ''}
      <div class="sig"><span>المحامي: ${c.lawyer}</span><span>${c.extRef ? 'مرجع خارجيّ: ' + c.extRef : ''} · معتمد من الإدارة العليا</span></div></body></html>`;
    const w = window.open('', '_blank', 'width=800,height=900');
    if (!w) return;
    w.document.write(html); w.document.close(); w.focus(); w.print();
  };

  return (
    <>
      <div style={{ marginBottom: 14 }}>
        <button className="btn soft sm" type="button" onClick={() => router.visit(base)}><Icon name="reply" /> رجوع للمخاطبات</button>
      </div>

      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>{c.id} — {c.subject}</h3><Badge text={c.status} tone={c.tone} /></div>
        <div className="card-b" style={{ padding: 16 }}><FlowLine steps={CORR_FLOW} cur={c.stage} /></div>
      </div>

      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>بيانات المخاطبة</h3></div>
        <div className="card-b" style={{ padding: 16 }}>
          <Row t="الاتّجاه" v={c.dir} />
          <Row t="الجهة" v={c.entity} />
          {c.ref !== '—' && <Row t="مرتبطة بـ" v={c.ref} />}
          <Row t="العميل" v={c.client} />
          <Row t="المحامي" v={c.lawyer} />
          <Row t="التاريخ" v={c.date || '—'} />
          {c.due && <Row t="الاستحقاق" v={c.due} />}
        </div>
      </div>

      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>التكامل مع النظام الخارجيّ</h3>{c.extRef ? <Badge text="مُرسَلة" tone="b-blue" /> : <Badge text="غير مُرسَلة" tone="b-grey" />}</div>
        <div className="card-b" style={{ padding: 16 }}>
          <Row t="القناة" v={c.channel || '—'} />
          <Row t="الرقم المرجعيّ الخارجيّ" v={c.extRef || '—'} />
          <Row t="حالة النظام الخارجيّ" v={c.extStatus || '—'} />
          <Row t="آخر مزامنة" v={c.extSync || '—'} />
          {c.extRef && c.stage < 6 && (
            <button className="btn soft sm" style={{ marginTop: 10 }} type="button" onClick={() => act('sync')}><Icon name="reply" /> مزامنة الحالة</button>
          )}
        </div>
      </div>

      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>نصّ المخاطبة</h3></div>
        <div className="card-b" style={{ padding: 16, whiteSpace: 'pre-wrap', lineHeight: 1.9 }}>{c.body || '—'}</div>
      </div>

      {c.reply && (
        <div className="card" style={{ marginBottom: 12, borderInlineStart: '3px solid var(--success)' }}>
          <div className="card-h"><h3>ردّ الجهة</h3></div>
          <div className="card-b" style={{ padding: 16, whiteSpace: 'pre-wrap', lineHeight: 1.9 }}>{c.reply}</div>
        </div>
      )}

      {c.briefed && (
        <div className="card" style={{ marginBottom: 12, borderInlineStart: '3px solid var(--primary)' }}>
          <div className="card-h"><h3>إفادة العميل بالنتيجة</h3><Badge text="صدرت" tone="b-green" /></div>
          <div className="card-b" style={{ padding: 16, whiteSpace: 'pre-wrap', lineHeight: 1.9 }}>{c.briefNote}</div>
        </div>
      )}

      {/* بطاقة الإجراء حسب المرحلة */}
      <div className="card" style={{ marginBottom: 12 }}>
        <div className="card-h"><h3>الإجراء</h3></div>
        <div className="card-b" style={{ padding: 16 }}>
          {c.briefReq && <div className="mtg-pend" style={{ marginBottom: 10 }}><Icon name="info" /> طلب العميل إفادة رسميّة — أصدرها أدناه.</div>}

          {c.stage < 2 && (
            <button className="btn" type="button" onClick={() => act('advance')}><Icon name="check" /> اعتماد ونقل للمرحلة التالية</button>
          )}
          {c.stage === 2 && (isAdmin
            ? <button className="btn" type="button" onClick={() => act('advance')}><Icon name="send" /> اعتماد وإرسال للجهة عبر النظام الخارجيّ</button>
            : <div className="mtg-pend"><Icon name="info" /> بانتظار اعتماد الإدارة وإرسالها للجهة.</div>)}
          {(c.stage === 3 || c.stage === 4) && (
            <button className="btn" type="button" onClick={() => act('receive')}><Icon name="reply" /> استقبال الردّ من النظام الخارجيّ</button>
          )}
          {c.stage === 5 && !c.briefed && (briefing ? (
            <div>
              <div className="field"><label>نصّ الإفادة للعميل</label><textarea className="input" value={note} onChange={(e) => setNote(e.target.value)} rows={3} placeholder="نُفيدكم بخصوص الموضوع…" /></div>
              <div style={{ display: 'flex', gap: 8 }}>
                <button className="btn" type="button" onClick={submitBrief}><Icon name="check" /> إصدار الإفادة</button>
                <button className="btn soft" type="button" onClick={() => setBriefing(false)}><Icon name="out" /> إلغاء</button>
              </div>
            </div>
          ) : (
            <button className="btn" type="button" onClick={() => setBriefing(true)}><Icon name="doc" /> إفادة العميل بالنتيجة</button>
          ))}
          {c.stage === 5 && c.briefed && isAdmin && (
            <button className="btn" type="button" onClick={() => act('close')}><Icon name="check" /> الإغلاق والأرشفة</button>
          )}
          {c.stage >= 6 && <div className="mtg-pend"><Icon name="check" /> اكتملت رحلة المخاطبة وأُرشفت.</div>}

          <div style={{ marginTop: 12 }}>
            <button className="btn soft sm" type="button" onClick={printLetter}><Icon name="download" /> طباعة المخاطبة (PDF)</button>
          </div>
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>سجلّ رحلة المخاطبة</h3><span className="sub">{c.audit.length}</span></div>
        <div className="card-b">
          {c.audit.map((a, i) => (
            <div className="item" key={i}><div className="iico"><Icon name="check" /></div><div className="imeta"><b>{a.a}</b><span>{a.by} · {a.t}</span></div></div>
          ))}
        </div>
      </div>
    </>
  );
};

export default Correspondence;
