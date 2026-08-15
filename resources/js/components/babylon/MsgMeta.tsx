import React from 'react';
import Icon from '@/lib/icons';
import { type Message, cleanTime, todayDate } from '@/lib/chat';

// شريط بيانات الرسالة: 📅 تاريخ الرسالة · 🕐 وقتها
// موحّد بين محادثات العميل والموظف والمحامي والإدارة.
// (حُذف سطر «عنوان IP» — كان ثابتاً وهمياً لا يعكس أي بيانات حقيقية)

const MsgMeta: React.FC<{ m: Message }> = ({ m }) => (
  <div className="msg-meta">
    <span><Icon name="cal" />{m.date || todayDate()}</span>
    <span><Icon name="clock" />{cleanTime(m.time)}</span>
  </div>
);

export default MsgMeta;
