import React from 'react';
import Icon from '@/lib/icons';
import { type Message, cleanTime, msgIP, todayDate } from '@/lib/chat';

// شريط بيانات الرسالة (يطابق metaLine): 📅 التاريخ · 🕐 الوقت · 📍 IP
// موحّد بين محادثات العميل والموظف والمحامي والإدارة

const MsgMeta: React.FC<{ m: Message }> = ({ m }) => (
  <div className="msg-meta">
    <span><Icon name="cal" />{todayDate()}</span>
    <span><Icon name="clock" />{cleanTime(m.time)}</span>
    <span><Icon name="pin" /><bdi>{msgIP(m.who)}</bdi></span>
  </div>
);

export default MsgMeta;
