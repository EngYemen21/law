import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// إشعارات العملاء — الإدارة تعرض إشعارات أي عميل وترسل إشعاراً حقيقياً (UserNotification)

interface Notif { id: number; ic: string; tone: string; text: string; time: string; unread: boolean; }
interface Props { clients: { id: number; name: string }[]; selected: number; notifs: Notif[]; }

const AdminClientNotifs: React.FC<Props> = ({ clients, selected, notifs }) => {
  const toast = useToast();
  const [unreadOnly, setUnreadOnly] = useState(false);
  const [body, setBody] = useState('');

  const pick = (id: number) => router.get('/admin/clientnotifs', { client: id }, { preserveState: false });

  const send = () => {
    if (!body.trim()) { toast('اكتب نص الإشعار'); return; }
    router.post('/admin/clientnotifs/send', { client_id: selected, body: body.trim() }, {
      preserveScroll: true,
      onSuccess: () => { setBody(''); toast('تم إرسال الإشعار للعميل'); },
    });
  };

  const markRead = () => router.post('/admin/clientnotifs/read', { client_id: selected }, {
    preserveScroll: true, onSuccess: () => toast('تم تعليم الإشعارات كمقروءة'),
  });

  const list = unreadOnly ? notifs.filter((n) => n.unread) : notifs;

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>تتيح الإدارة العليا متابعة إشعارات أي عميل وإرسال إشعار جديد يصل إلى صاحبه فقط.</p>
      </div>

      <div className="card" style={{ marginBottom: 14 }}>
        <div className="card-h"><h3>إرسال إشعار</h3></div>
        <div className="card-b" style={{ padding: 16 }}>
          <div className="field">
            <label>العميل</label>
            <select value={selected} onChange={(e) => pick(Number(e.target.value))}>
              {clients.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>
          <div className="field"><label>نص الإشعار</label><textarea value={body} onChange={(e) => setBody(e.target.value)} placeholder="اكتب الإشعار الذي يصل العميل…" /></div>
          <button className="btn" onClick={send} type="button"><Icon name="bell" /> إرسال الإشعار</button>
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>إشعارات العميل</h3>
          <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            <button className="btn soft sm" onClick={() => setUnreadOnly((v) => !v)} type="button">{unreadOnly ? 'عرض الكل' : 'غير المقروء فقط'}</button>
            <button className="btn soft sm" onClick={markRead} type="button"><Icon name="check" /> تعليم كمقروء</button>
          </div>
        </div>
        <div className="card-b">
          {list.length ? list.map((n) => (
            <div key={n.id} className={`notif ${n.unread ? 'unread' : ''}`}>
              <div className={`nico stat ${n.tone}`} style={{ padding: 0 }}><Icon name={n.ic} /></div>
              <div className="nbody">
                <p>{n.text}</p>
                <time>{n.time}</time>
              </div>
            </div>
          )) : (
            <div className="empty"><Icon name="bell" /><b>لا إشعارات لهذا العميل بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminClientNotifs;
