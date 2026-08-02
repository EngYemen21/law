import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Badge from '@/components/babylon/Badge';
import { useToast } from '@/components/babylon/Toast';
import Icon from '@/lib/icons';
import { CORR_FLOW, corrColor, corrTone, type CorrCard } from '@/lib/corr-ui';

interface ClientOpt { id: number; name: string }
interface Props { role: string; base: string; corrs: CorrCard[]; clients: ClientOpt[] }

type Filter = 'all' | 'صادرة' | 'واردة' | 'draft' | 'sent' | 'closed';

const NewCorr: React.FC<{ base: string; clients: ClientOpt[]; onClose: () => void }> = ({ base, clients, onClose }) => {
  const toast = useToast();
  const [clientId, setClientId] = useState(clients[0]?.id ?? 0);
  const [direction, setDirection] = useState('صادرة');
  const [entity, setEntity] = useState('');
  const [subject, setSubject] = useState('');
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = () => {
    if (!clientId || !entity.trim() || !subject.trim()) return;
    setBusy(true);
    router.post(base, { client_id: clientId, direction, entity: entity.trim(), subject: subject.trim(), body: body.trim() }, {
      onError: (e) => toast(Object.values(e)[0] ?? 'تعذّر إنشاء المخاطبة'), onFinish: () => setBusy(false),
    });
  };

  return (
    <div className="card" style={{ marginBottom: 14 }}>
      <div className="card-h"><h3>مخاطبة جديدة</h3></div>
      <div className="card-b" style={{ padding: 16 }}>
        <div className="picker-grid">
          <div className="field"><label>العميل</label>
            <select className="input" value={clientId} onChange={(e) => setClientId(Number(e.target.value))}>
              {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div className="field"><label>الاتّجاه</label>
            <select className="input" value={direction} onChange={(e) => setDirection(e.target.value)}>
              <option value="صادرة">صادرة</option><option value="واردة">واردة</option>
            </select>
          </div>
        </div>
        <div className="field"><label>الجهة / المحكمة</label><input className="input" value={entity} onChange={(e) => setEntity(e.target.value)} placeholder="مثال: المحكمة التجارية بالرياض" /></div>
        <div className="field"><label>الموضوع</label><input className="input" value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="مثال: مذكرة في النزاع التجاري" /></div>
        <div className="field"><label>نصّ المخاطبة</label><textarea className="input" value={body} onChange={(e) => setBody(e.target.value)} rows={4} /></div>
        <div style={{ display: 'flex', gap: 8 }}>
          <button className="btn" type="button" onClick={submit} disabled={busy}><Icon name="send" /> إنشاء المخاطبة</button>
          <button className="btn soft" type="button" onClick={onClose}><Icon name="out" /> إلغاء</button>
        </div>
      </div>
    </div>
  );
};

const Correspondences: React.FC<Props> = ({ base, corrs, clients }) => {
  const [filter, setFilter] = useState<Filter>('all');
  const [creating, setCreating] = useState(false);

  const draft = corrs.filter((c) => c.stage < 3).length;
  const sent = corrs.filter((c) => c.stage >= 3 && c.stage < 6).length;
  const closed = corrs.filter((c) => c.stage >= 6).length;

  const shown = corrs.filter((c) => {
    if (filter === 'all') return true;
    if (filter === 'صادرة' || filter === 'واردة') return c.dir === filter;
    if (filter === 'draft') return c.stage < 3;
    if (filter === 'sent') return c.stage >= 3 && c.stage < 6;
    return c.stage >= 6;
  });

  const open = (id: string) => router.visit(`${base}/${encodeURIComponent(id)}`);
  const FILTERS: [Filter, string][] = [['all', 'الكل'], ['صادرة', 'صادرة'], ['واردة', 'واردة'], ['draft', 'قيد الإعداد'], ['sent', 'مُرسلة'], ['closed', 'مغلقة']];

  return (
    <>
      <div className="greet">
        <h2>المخاطبات الرسميّة</h2>
        <p>إدارة المخاطبات مع الجهات والمحاكم عبر النظام الخارجيّ — من الإنشاء حتى الإغلاق وإفادة العميل.</p>
      </div>

      <div className="stat-strip">
        <span className="stat-pill"><span className="pd" style={{ background: '#8895a7' }} /><b>{corrs.length}</b> الإجمالي</span>
        <span className="stat-pill"><span className="pd" style={{ background: '#0E5C9C' }} /><b>{draft}</b> قيد الإعداد</span>
        <span className="stat-pill"><span className="pd" style={{ background: '#C0832B' }} /><b>{sent}</b> مُرسلة/بانتظار الرد</span>
        <span className="stat-pill"><span className="pd" style={{ background: '#1E9D6B' }} /><b>{closed}</b> مغلقة</span>
      </div>

      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, flexWrap: 'wrap', margin: '4px 0 12px' }}>
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          {FILTERS.map(([f, lbl]) => (
            <button key={f} className={`btn soft sm ${filter === f ? 'feat' : ''}`} type="button" onClick={() => setFilter(f)}>{lbl}</button>
          ))}
        </div>
        <button className="btn" type="button" onClick={() => setCreating((v) => !v)}><Icon name="plus" /> مخاطبة جديدة</button>
      </div>

      {creating && <NewCorr base={base} clients={clients} onClose={() => setCreating(false)} />}

      <div className="card">
        <div className="card-h"><h3>المخاطبات</h3><span className="sub">{shown.length}</span></div>
        <div className="card-b" style={{ padding: '14px 16px' }}>
          {shown.length ? shown.map((c) => (
            <div key={c.id} className="agd-c" style={{ borderRightColor: corrColor(c.stage), marginBottom: 10, cursor: 'pointer' }} onClick={() => open(c.id)}>
              <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8, alignItems: 'flex-start' }}>
                <div style={{ minWidth: 0 }}>
                  <div className="mtg-t">{c.id} — {c.subject}</div>
                  <div className="mtg-m">
                    <Badge text={c.dir} tone={c.dir === 'صادرة' ? 'b-blue' : 'b-grey'} /> · {c.entity} · {c.client} · {c.date}
                    {c.ref !== '—' ? ` · مرتبطة بـ ${c.ref}` : ''}
                  </div>
                </div>
                <Badge text={CORR_FLOW[c.stage]} tone={corrTone(c.stage)} />
              </div>
            </div>
          )) : <div className="empty"><Icon name="office" /><b>لا مخاطبات</b></div>}
        </div>
      </div>
    </>
  );
};

export default Correspondences;
