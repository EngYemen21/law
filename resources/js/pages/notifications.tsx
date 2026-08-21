import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// يطابق viewNotifications + notifHTML + openNotif — البيانات من قاعدة البيانات
// كل إشعار قابل للنقر: ينتقل للشاشة المرتبطة (link) المشتقّة خادميّاً

interface NotifItem { ic: string; tone: string; text: string; time: string; unread: boolean; link?: string | null }

const Notifications: React.FC<{ notifications: NotifItem[] }> = ({ notifications }) => {
  const toast = useToast();

  const markAll = () => {
    router.post('/notifications/read-all', {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم تعليم الكل كمقروء'),
    });
  };

  const open = (link?: string | null) => {
    if (link) router.visit(link);
  };

  return (
    <div className="card">
      <div className="card-h">
        <h3>الإشعارات</h3>
        <button className="btn soft sm" type="button" onClick={markAll}>
          <Icon name="check" /> تعليم الكل كمقروء
        </button>
      </div>
      <div className="card-b" id="notifList">
        {notifications.map((n, i) => (
          <div
            key={i}
            className={`notif ${n.unread ? 'unread' : ''}`}
            style={n.link ? { cursor: 'pointer' } : undefined}
            onClick={() => open(n.link)}
          >
            <div className={`nico stat ${n.tone}`} style={{ padding: 0 }}>
              <Icon name={n.ic} />
            </div>
            <div className="nbody">
              <p>{n.text}</p>
              <time>{n.time}</time>
            </div>
            {n.link && (
              <div className="iact">
                <button
                  className="btn sm"
                  type="button"
                  onClick={(e) => { e.stopPropagation(); open(n.link); }}
                >
                  <Icon name="out" /> فتح
                </button>
              </div>
            )}
          </div>
        ))}
      </div>
    </div>
  );
};

export default Notifications;
