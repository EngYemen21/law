import React from 'react';
import ZoomEmbedRoom from '@/lib/zoom-room';

// غرفة الجلسة المرئية للعميل — تضمين Zoom داخل المنصّة (Consult::toClientCard)
interface Props {
  consult: { ref: string; slink: string };
}

const VideoRoom: React.FC<Props> = ({ consult }) => (
  <ZoomEmbedRoom
    cref={consult.ref}
    label={`${consult.ref} · استشارة مرئية`}
    back="/myconsults"
    fallbackUrl={consult.slink}
    viewer="client"
  />
);

export default VideoRoom;
