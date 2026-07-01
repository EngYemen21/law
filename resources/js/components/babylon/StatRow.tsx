import React from 'react';
import Icon from '@/lib/icons';

// يطابق statHTML(items) — items: [tone, icon, num, label]
export type StatItem = [string, string, React.ReactNode, string];

const StatRow: React.FC<{ items: StatItem[] }> = ({ items }) => (
  <div className="stats">
    {items.map(([tone, icon, num, lbl], i) => (
      <div key={i} className={`stat ${tone}`}>
        <div className="si"><Icon name={icon} /></div>
        <div className="num">{num}</div>
        <div className="lbl">{lbl}</div>
      </div>
    ))}
  </div>
);

export default StatRow;
