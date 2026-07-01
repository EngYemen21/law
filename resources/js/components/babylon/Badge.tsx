import React from 'react';

// يطابق: const badge=(t,tone)=>'<span class="badge-s '+tone+'"><span class="d"></span>'+t+'</span>';
// tone أمثلة: b-blue / b-amber / b-green / b-grey
interface BadgeProps {
  text: string;
  tone: string;
}

const Badge: React.FC<BadgeProps> = ({ text, tone }) => (
  <span className={`badge-s ${tone}`}>
    <span className="d" />
    {text}
  </span>
);

export default Badge;
