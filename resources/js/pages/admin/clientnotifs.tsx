import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { type ClientNotif, CLIENT_DIR, DEMO_CLIENT_NOTIFS } from '@/lib/admin-data';

// يطابق adClientNotifsView + adToggleUnread + adMarkClientRead + clientNotifsList في index (82).html

const AdminClientNotifs: React.FC = () => {
  const toast = useToast();
  const [store, setStore] = useState<Record<string, ClientNotif[]>>(() => {
    const s: Record<string, ClientNotif[]> = {};
    Object.keys(DEMO_CLIENT_NOTIFS).forEach((k) => { s[k] = DEMO_CLIENT_NOTIFS[k].map((n) => ({ ...n })); });
    return s;
  });
  const [client, setClient] = useState(CLIENT_DIR[0].name);
  const [unreadOnly, setUnreadOnly] = useState(false);

  const count = (name: string) => (store[name] || []).length;

  const markRead = () => {
    setStore((prev) => ({ ...prev, [client]: (prev[client] || []).map((n) => ({ ...n, unread: false })) }));
    toast('تم تعليم إشعارات العميل كمقروءة');
  };

  let arr = store[client] || [];
  if (unreadOnly) arr = arr.filter((n) => n.unread);

  return (
    <>
      <div className="ai-banner">
        <div className="ab"><img src="/images/mono.jpg" alt="" /></div>
        <p>تتيح الإدارة العليا متابعة إشعارات أي عميل مسجّل (الدعوات والتأكيدات والروابط). يصل كل إشعار إلى صاحبه فقط.</p>
      </div>
      <div className="card">
        <div className="card-h">
          <h3>إشعارات العميل</h3>
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <select value={client} onChange={(e) => setClient(e.target.value)}>
              {CLIENT_DIR.map((c) => <option key={c.name} value={c.name}>{c.name} ({count(c.name)})</option>)}
            </select>
            <button className="btn soft sm" onClick={() => setUnreadOnly((v) => !v)} type="button">
              {unreadOnly ? 'عرض الكل' : 'غير المقروء فقط'}
            </button>
            <button className="btn soft sm" onClick={markRead} type="button"><Icon name="check" /> تعليم كمقروء</button>
          </div>
        </div>
        <div className="card-b">
          {arr.length ? arr.map((n, i) => (
            <div key={i} className={`notif ${n.unread ? 'unread' : ''}`}>
              <div className={`nico stat ${n.tone}`} style={{ padding: 0 }}><Icon name={n.ic} /></div>
              <div className="nbody">
                <p>{n.text}</p>
                {n.link && (
                  <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap', margin: '6px 0 2px' }}>
                    <span style={{ direction: 'ltr', color: 'var(--primary)', fontWeight: 700, fontSize: '11.5px' }}>🔗 {n.link}</span>
                    <button className="btn soft sm" onClick={() => toast('تم نسخ رابط الاجتماع')} type="button"><Icon name="link" /> نسخ الرابط</button>
                  </div>
                )}
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
