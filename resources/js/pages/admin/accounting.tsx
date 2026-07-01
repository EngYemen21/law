import React, { useState } from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import Modal from '@/components/babylon/Modal';
import { useToast } from '@/components/babylon/Toast';
import { Qr } from '@/components/babylon/admin-charts';
import {
  type Invoice, type InvItem, INVOICES, INV_CLIENTS, VAT_NO, INV_IBAN,
  invSub, invDiscV, invNet, invVatV, invTotal, invPaidAmt, invDueAmt, invTone, fmtSAR,
} from '@/lib/admin-data';

// يطابق acctView + invDocHTML + openInvoice + markInvoicePaid + newInvoice في index (82).html

const TABS: [string, string][] = [
  ['all', 'الكل'], ['مدفوعة', 'مدفوعة'], ['غير مدفوعة', 'غير مدفوعة'], ['جزئية', 'جزئية'], ['متأخرة', 'متأخرة'],
];

// وثيقة الفاتورة — يطابق invDocHTML
const InvoiceDoc: React.FC<{ v: Invoice }> = ({ v }) => {
  const tax = (v.vat || 0) > 0;
  const paid = invPaidAmt(v);
  const duev = invDueAmt(v);
  return (
    <div className="inv-doc">
      <div className="inv-hd">
        <div className="top">
          <div className="brand">
            <img src="/images/mono.jpg" alt="" />
            <div><b>سلاسل بابل لتقنية المعلومات</b><span>SALASEL BABEL · المحاماة والاستشارات القانونية</span></div>
          </div>
          <div className="tag">
            <div className="t1">{tax ? 'فاتورة ضريبية' : 'فاتورة'}</div>
            <div className="t2">{tax ? 'TAX INVOICE' : 'INVOICE'}</div>
            <div className="no">{v.no}</div>
          </div>
        </div>
      </div>
      <div className="inv-parties">
        <div className="inv-party">
          <div className="pl">المُورّد</div>
          <div className="pv">سلاسل بابل لتقنية المعلومات<br />
            {tax ? <><span className="sm">الرقم الضريبي: {VAT_NO}</span><br /></> : <><span className="sm">غير خاضعة لضريبة القيمة المضافة</span><br /></>}
            <span className="sm">الرياض — حي العليا</span>
          </div>
        </div>
        <div className="inv-party">
          <div className="pl">العميل</div>
          <div className="pv">{v.client}<br /><span className="sm">رقم العميل: {v.code}</span></div>
        </div>
        <div className="inv-party">
          <div className="pl">التواريخ</div>
          <div className="pv"><span className="sm">الإصدار:</span> {v.date}<br /><span className="sm">الاستحقاق:</span> {v.due}<br /><Badge text={v.status} tone={invTone(v.status)} /></div>
        </div>
      </div>
      <div className="inv-tblw">
        <table className="inv-tbl">
          <thead><tr><th className="n">#</th><th>الوصف</th><th className="n">الكمية</th><th className="n">السعر</th><th className="n">الإجمالي</th></tr></thead>
          <tbody>
            {v.items.map((it, i) => (
              <tr key={i}><td className="n">{i + 1}</td><td>{it.d}</td><td className="n">{it.q}</td><td className="n">{fmtSAR(it.p)}</td><td className="n">{fmtSAR(it.q * it.p)}</td></tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="inv-tot">
        <div className="box">
          <div className="r"><span>المجموع الفرعي</span><b>{fmtSAR(invSub(v))}</b></div>
          {invDiscV(v) > 0 && <div className="r"><span>الخصم</span><b>- {fmtSAR(invDiscV(v))}</b></div>}
          {tax && (
            <>
              <div className="r"><span>الوعاء الخاضع للضريبة</span><b>{fmtSAR(invNet(v))}</b></div>
              <div className="r"><span>ضريبة القيمة المضافة (15%)</span><b>{fmtSAR(invVatV(v))}</b></div>
            </>
          )}
          <div className="r grand"><span>{tax ? 'الإجمالي شامل الضريبة' : 'الإجمالي'}</span><b>{fmtSAR(invTotal(v))}</b></div>
          {paid > 0 && <div className="r"><span>المدفوع{v.method ? ` (${v.method})` : ''}</span><b style={{ color: 'var(--success)' }}>{fmtSAR(paid)}</b></div>}
          {duev > 0 && <div className="r"><span>المتبقّي المستحق</span><b style={{ color: 'var(--amber)' }}>{fmtSAR(duev)}</b></div>}
        </div>
      </div>
      <div className="inv-ft">
        <div className="qr"><Qr seed={v.no + v.date} /></div>
        <div className="terms">
          <b>الدفع إلى:</b> {INV_IBAN} (مصرف الراجحي)<br />
          تُسدَّد الفاتورة خلال 14 يوماً من تاريخ الإصدار. {tax ? 'تشمل القيمة ضريبة القيمة المضافة 15%.' : 'هذه الفاتورة غير خاضعة لضريبة القيمة المضافة.'}<br />
          www.sb-legal.sa · 011 462 2277
        </div>
      </div>
    </div>
  );
};

const AdminAccounting: React.FC = () => {
  const toast = useToast();
  const [invoices, setInvoices] = useState<Invoice[]>(() => INVOICES.map((v) => ({ ...v, items: v.items.map((it) => ({ ...it })) })));
  const [filter, setFilter] = useState('all');
  const [openInv, setOpenInv] = useState<Invoice | null>(null);

  // مودال فاتورة جديدة
  const [newOpen, setNewOpen] = useState(false);
  const [niCi, setNiCi] = useState(0);
  const [niDate, setNiDate] = useState('');
  const [niDue, setNiDue] = useState('');
  const [niItems, setNiItems] = useState<InvItem[]>([{ d: '', q: 1, p: 0 }]);
  const [niDisc, setNiDisc] = useState(0);
  const [niVat, setNiVat] = useState(15);
  const [niTaxable, setNiTaxable] = useState(true);

  const issued = invoices.reduce((a, v) => a + invTotal(v), 0);
  const collected = invoices.reduce((a, v) => a + invPaidAmt(v), 0);
  const due = issued - collected;
  const vatC = invoices.filter((v) => invPaidAmt(v) > 0).reduce((a, v) => a + invVatV(v), 0);
  const overdue = invoices.filter((v) => v.status === 'متأخرة').length;

  const list = invoices.filter((v) => filter === 'all' || v.status === filter);

  const markPaid = (no: string) => {
    setInvoices((prev) => prev.map((v) => v.no === no ? { ...v, status: 'مدفوعة', method: v.method || 'تحويل بنكي', paid: 'اليوم', part: undefined } : v));
    setOpenInv((cur) => cur && cur.no === no ? { ...cur, status: 'مدفوعة', method: cur.method || 'تحويل بنكي', paid: 'اليوم', part: undefined } : cur);
    toast('تم تسجيل تحصيل الفاتورة ' + no);
  };

  // فاتورة جديدة
  const niSub = niItems.reduce((a, it) => a + it.q * it.p, 0);
  const niNet = niSub - niDisc;
  const niVatV = niTaxable ? Math.round(niNet * (niVat / 100)) : 0;
  const niTot = niNet + niVatV;

  const setItem = (i: number, key: keyof InvItem, val: string) =>
    setNiItems((prev) => prev.map((it, idx) => idx === i ? { ...it, [key]: key === 'd' ? val : parseFloat(val) || 0 } : it));

  const issue = () => {
    const items = niItems.filter((it) => (it.d || '').trim() && it.p > 0);
    if (!items.length) { toast('أضف بنداً واحداً على الأقل بوصف وسعر'); return; }
    const seq = invoices.length + 1;
    const no = 'INV-2026-' + ('000' + seq).slice(-4);
    const c = INV_CLIENTS[niCi] || INV_CLIENTS[0];
    const inv: Invoice = {
      no, client: c[0], code: c[1], date: niDate || 'اليوم', due: niDue || 'بعد 14 يوماً',
      items, disc: niDisc, vat: niTaxable ? niVat / 100 : 0, status: 'غير مدفوعة', method: '', paid: '',
    };
    setInvoices((p) => [inv, ...p]);
    setNewOpen(false);
    setNiItems([{ d: '', q: 1, p: 0 }]); setNiDisc(0); setNiDate(''); setNiDue('');
    toast('تم إصدار الفاتورة ' + no);
    setOpenInv(inv);
  };

  return (
    <>
      <div className="greet">
        <h2>الفواتير والمحاسبة</h2>
        <p>إصدار الفواتير الضريبية، حساب الإجماليات وضريبة القيمة المضافة، ومتابعة التحصيل والذمم — نظام محاسبي مبسّط خاص بالإدارة العليا.</p>
      </div>

      <div className="stats">
        <div className="stat t-blue"><div className="si"><Icon name="card" /></div><div className="num">{fmtSAR(issued)}</div><div className="lbl">إجمالي المُصدَر</div></div>
        <div className="stat t-green"><div className="si"><Icon name="check" /></div><div className="num">{fmtSAR(collected)}</div><div className="lbl">المحصّل</div></div>
        <div className="stat t-amber"><div className="si"><Icon name="folder" /></div><div className="num">{fmtSAR(due)}</div><div className="lbl">المستحق (الذمم)</div></div>
        <div className="stat t-cyan"><div className="si"><Icon name="scale" /></div><div className="num">{fmtSAR(vatC)}</div><div className="lbl">ض.ق.م المحصّلة</div></div>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', margin: '14px 0' }}>
        <button className="btn" onClick={() => setNewOpen(true)} type="button"><Icon name="card" /> إصدار فاتورة جديدة</button>
      </div>

      <div className="card" style={{ marginBottom: 16 }}>
        <div className="card-h"><h3>الفواتير الصادرة</h3><span className="sub">{invoices.length}</span></div>
        <div className="card-b" style={{ padding: '12px 14px' }}>
          <div className="mtabs">
            {TABS.map((t) => (
              <button key={t[0]} className={`mtab ${filter === t[0] ? 'on' : ''}`} onClick={() => setFilter(t[0])} type="button">{t[1]}</button>
            ))}
          </div>
          {list.length ? (
            <table className="inv-tbl" style={{ marginTop: 6 }}>
              <thead>
                <tr><th>رقم الفاتورة</th><th>العميل</th><th className="n">التاريخ</th><th className="n">الإجمالي</th><th className="n">المستحق</th><th>الحالة</th><th>إجراءات</th></tr>
              </thead>
              <tbody>
                {list.map((v) => (
                  <tr key={v.no}>
                    <td style={{ fontWeight: 800, direction: 'ltr', textAlign: 'right' }}>{v.no}</td>
                    <td>{v.client}</td>
                    <td className="n">{v.date}</td>
                    <td className="n">{fmtSAR(invTotal(v))}</td>
                    <td className="n" style={{ color: invDueAmt(v) > 0 ? 'var(--amber)' : 'var(--success)', fontWeight: 700 }}>{fmtSAR(invDueAmt(v))}</td>
                    <td><Badge text={v.status} tone={invTone(v.status)} /></td>
                    <td>
                      <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
                        <button className="btn soft sm" onClick={() => setOpenInv(v)} type="button"><Icon name="doc" /> عرض</button>
                        {invDueAmt(v) > 0 && <button className="btn sm" onClick={() => markPaid(v.no)} type="button"><Icon name="check" /> تحصيل</button>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          ) : (
            <div className="empty"><Icon name="card" /><b>لا فواتير في هذا التصنيف</b></div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h"><h3>كشف الحساب المبسّط — الذمم المدينة</h3></div>
        <div className="card-b" style={{ padding: '16px 18px' }}>
          <div className="kpi-row"><span className="t">إجمالي المُصدَر (شامل الضريبة)</span><span className="v">{fmtSAR(issued)}</span></div>
          <div className="kpi-row"><span className="t">إجمالي المُحصّل</span><span className="v" style={{ color: 'var(--success)' }}>{fmtSAR(collected)}</span></div>
          <div className="kpi-row"><span className="t">صافي المستحق على العملاء</span><span className="v" style={{ color: 'var(--amber)' }}>{fmtSAR(due)}</span></div>
          <div className="kpi-row"><span className="t">فواتير متأخرة السداد</span><span className="v" style={{ color: 'var(--red)' }}>{overdue} فاتورة</span></div>
          <div className="action-hint" style={{ marginTop: 10 }}>كشف محاسبي مبسّط للذمم والتحصيل — لا يشمل الرواتب أو المصروفات الداخلية.</div>
        </div>
      </div>

      {/* مودال عرض الفاتورة */}
      <Modal title={openInv ? `الفاتورة ${openInv.no}` : ''} open={!!openInv} onClose={() => setOpenInv(null)}>
        {openInv && (
          <>
            <InvoiceDoc v={openInv} />
            <div style={{ display: 'flex', gap: 9, marginTop: 16, flexWrap: 'wrap' }}>
              <button className="btn soft" onClick={() => toast('جارٍ تجهيز ملف PDF')} type="button"><Icon name="download" /> طباعة / PDF</button>
              <button className="btn soft" onClick={() => toast('تم نسخ رقم الفاتورة')} type="button"><Icon name="link" /> نسخ رقم الفاتورة</button>
              {invDueAmt(openInv) > 0 && <button className="btn" onClick={() => markPaid(openInv.no)} type="button"><Icon name="check" /> تسجيل التحصيل</button>}
            </div>
          </>
        )}
      </Modal>

      {/* مودال فاتورة جديدة */}
      <Modal title="إصدار فاتورة جديدة" open={newOpen} onClose={() => setNewOpen(false)}>
        <div className="field">
          <label>العميل</label>
          <select className="input" value={niCi} onChange={(e) => setNiCi(parseInt(e.target.value, 10))}>
            {INV_CLIENTS.map((c, i) => <option key={i} value={i}>{c[0]} · {c[1]}</option>)}
          </select>
        </div>
        <div className="picker-grid">
          <div className="field"><label>تاريخ الإصدار</label><input className="input" type="date" value={niDate} onChange={(e) => setNiDate(e.target.value)} /></div>
          <div className="field"><label>تاريخ الاستحقاق</label><input className="input" type="date" value={niDue} onChange={(e) => setNiDue(e.target.value)} /></div>
        </div>
        <div className="field">
          <label>بنود الفاتورة (الوصف · الكمية · السعر)</label>
          {niItems.map((it, i) => (
            <div key={i} className="niv-row">
              <input className="input" placeholder="وصف البند" value={it.d} onChange={(e) => setItem(i, 'd', e.target.value)} />
              <input className="input" type="number" min={1} value={it.q} onChange={(e) => setItem(i, 'q', e.target.value)} />
              <input className="input" type="number" min={0} placeholder="السعر" value={it.p} onChange={(e) => setItem(i, 'p', e.target.value)} />
              <span className="x" onClick={() => setNiItems((p) => { const n = p.filter((_, idx) => idx !== i); return n.length ? n : [{ d: '', q: 1, p: 0 }]; })}>×</span>
            </div>
          ))}
          <button className="btn soft sm" onClick={() => setNiItems((p) => [...p, { d: '', q: 1, p: 0 }])} type="button"><Icon name="check" /> إضافة بند</button>
        </div>
        <div className="field">
          <label>نوع الفاتورة</label>
          <div className="mtabs">
            <button className={`mtab ${niTaxable ? 'on' : ''}`} onClick={() => setNiTaxable(true)} type="button"><Icon name="scale" /> فاتورة ضريبية (15%)</button>
            <button className={`mtab ${!niTaxable ? 'on' : ''}`} onClick={() => setNiTaxable(false)} type="button">فاتورة بدون ضريبة</button>
          </div>
        </div>
        <div className="picker-grid">
          <div className="field"><label>الخصم (ر.س)</label><input className="input" type="number" min={0} value={niDisc} onChange={(e) => setNiDisc(parseFloat(e.target.value) || 0)} /></div>
          {niTaxable ? (
            <div className="field"><label>ض.ق.م (%)</label><input className="input" type="number" min={0} value={niVat} onChange={(e) => setNiVat(parseFloat(e.target.value) || 0)} /></div>
          ) : (
            <div className="field"><label>الضريبة</label><input className="input" value="غير خاضعة (0%)" disabled /></div>
          )}
        </div>
        <div className="inv-tot" style={{ padding: 0, marginTop: 6 }}>
          <div className="box">
            <div className="r"><span>المجموع الفرعي</span><b>{fmtSAR(niSub)}</b></div>
            <div className="r"><span>الخصم</span><b>- {fmtSAR(niDisc)}</b></div>
            {niTaxable ? <div className="r"><span>ض.ق.م ({niVat}%)</span><b>{fmtSAR(niVatV)}</b></div> : <div className="r"><span>الضريبة</span><b>غير خاضعة</b></div>}
            <div className="r grand"><span>الإجمالي</span><b>{fmtSAR(niTot)}</b></div>
          </div>
        </div>
        <button className="btn block" style={{ marginTop: 14 }} onClick={issue} type="button"><Icon name="card" /> إصدار الفاتورة</button>
      </Modal>
    </>
  );
};

export default AdminAccounting;
