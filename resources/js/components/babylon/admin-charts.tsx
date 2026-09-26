import React from 'react';
import type { BarDatum } from '@/lib/admin-data';

// يطابق barsHTML(data,unit) في index (82).html
export const Bars: React.FC<{ data: BarDatum[]; unit?: string }> = ({ data, unit = '' }) => {
  const max = Math.max(...data.map((d) => d.v)) || 1;

  return (
    <div className="bars">
      {data.map((d) => (
        <div key={d.m} className="bar-col">
          <div className="bar" style={{ height: Math.round((d.v / max) * 100) + '%' }}>
            <span className="val">{d.v}{unit}</span>
          </div>
          <div className="cap">{d.m}</div>
        </div>
      ))}
    </div>
  );
};

// يطابق barChart(title,data) في index (82).html — data: [label, value][]
export const BarChart: React.FC<{ title: string; data: [string, number][] }> = ({ title, data }) => {
  const max = Math.max(...data.map((d) => d[1])) || 1;

  return (
    <div className="card">
      <div className="card-h"><h3>{title}</h3></div>
      <div className="card-b" style={{ padding: 18 }}>
        <div className="bars2">
          {data.map((d, i) => {
            const pct = Math.round((d[1] / max) * 100);

            return (
              <div key={i} className="bar2-row">
                <span className="bl">{d[0]}</span>
                <div className="bt"><div className="bf" style={{ width: pct + '%' }} /></div>
                <span className="bv">{d[1]}</span>
              </div>
            );
          })}
        </div>
      </div>
    </div>
  );
};

export default Bars;
