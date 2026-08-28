import React from 'react';
import Icon from '@/lib/icons';

// يطابق statHTML(items) — items: [tone, icon, num, label]
// والعنصر الخامس اختياري: مفتاح وجهة يستهلكه onSelect للتنقّل/التصفية (لا يُعرض).
export type StatItem = [string, string, React.ReactNode, string, string?];

interface StatRowProps {
  items: StatItem[];
  /** تصفية بالنقر على المؤشّر — حين يُمرَّر تصير البطاقات أزراراً (وإلا تبقى عرضاً فقط). */
  onSelect?: (index: number) => void;
}

const StatRow: React.FC<StatRowProps> = ({ items, onSelect }) => (
  <div className="stats">
    {items.map(([tone, icon, num, lbl], i) => (
      <div
        key={i}
        className={`stat ${tone}`}
        onClick={onSelect ? () => onSelect(i) : undefined}
        onKeyDown={onSelect ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(i); } } : undefined}
        role={onSelect ? 'button' : undefined}
        tabIndex={onSelect ? 0 : undefined}
        style={onSelect ? { cursor: 'pointer' } : undefined}
      >
        <div className="si"><Icon name={icon} /></div>
        <div className="num">{num}</div>
        <div className="lbl">{lbl}</div>
      </div>
    ))}
  </div>
);

export default StatRow;
