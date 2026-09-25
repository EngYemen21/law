import React from 'react';
import Icon from '@/lib/icons';
import { type Message, cleanTime, todayDate } from '@/lib/chat';

// شريط بيانات الرسالة: 📅 تاريخ الرسالة · 🕐 وقتها · عنوان IP المُرسِل (للطاقم)
// موحّد بين محادثات العميل والموظف والمحامي والإدارة.
//
// عنوان IP حقيقيٌّ الآن (طلب المالك 2026-09-25) — كان هنا سطرٌ ثابت وهميّ فحُذف. ولا شرط دورٍ
// في الواجهة: الخادم لا يرسل `ip` إلا للطاقم (`RecordsSenderIp::senderIpField`)، فحمولة العميل
// خالية منه أصلاً. ويغيب كذلك لرسالة النظام والمساعد، وللرسالة اللحظيّة حتى أوّل تحميل.

const MsgMeta: React.FC<{ m: Message }> = ({ m }) => (
  <div className="msg-meta">
    <span><Icon name="cal" />{m.date || todayDate()}</span>
    <span>
      <Icon name="clock" />{cleanTime(m.time)}
      {m.ip && <> · <bdi title="عنوان IP للمُرسِل">{m.ip}</bdi></>}
    </span>
  </div>
);

export default MsgMeta;
