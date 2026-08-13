import React from 'react';
import ZoomEmbedRoom from '@/lib/zoom-room';

// غرفة الاجتماع المضمّنة للعميل — تضمين Zoom داخل المنصّة (Meeting::toCard)
interface Props {
  meeting: { ref: string; title: string; when: string; link: string };
}

const MeetingRoom: React.FC<Props> = ({ meeting }) => (
  <ZoomEmbedRoom
    cref={meeting.ref}
    kind="meeting"
    label={`${meeting.ref} · ${meeting.title}`}
    back="/meetings"
    fallbackUrl={meeting.link}
    viewer="client"
    details={{
      title: meeting.title,
      rows: [
        { k: 'الموعد', v: meeting.when },
        { k: 'المرجع', v: meeting.ref },
      ],
    }}
  />
);

export default MeetingRoom;
