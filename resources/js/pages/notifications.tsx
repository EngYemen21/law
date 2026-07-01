import { router } from '@inertiajs/react';
import React from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// يطابق viewNotifications + notifHTML + markRead — البيانات من قاعدة البيانات

interface NotifItem { ic: string; tone: string; text: string; time: string; unread: boolean; }

const Notifications: React.FC<{ notifications: NotifItem[] }> = ({ notifications }) => {
  const toast = useToast();

  const markAll = () => {
    router.post('/notifications/read-all', {}, {
      preserveScroll: true,
      onSuccess: () => toast('تم تعليم الكل كمقروء'),
    });
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
          <div key={i} className={`notif ${n.unread ? 'unread' : ''}`}>
            <div className={`nico stat ${n.tone}`} style={{ padding: 0 }}>
              <Icon name={n.ic} />
            </div>
            <div className="nbody">
              <p dangerouslySetInnerHTML={{ __html: n.text }} />
              <time>{n.time}</time>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

export default Notifications;
