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

// يطابق qrSVG(seed) في index (82).html — رمز QR زخرفي حتمي
export const Qr: React.FC<{ seed: string }> = ({ seed }) => {
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0;
  const n = 21, cell = 4;
  const rects: React.ReactNode[] = [];
  const rnd = () => { h = (h * 1103515245 + 12345) & 0x7fffffff; return h / 0x7fffffff; };
  const fin = (x: number, y: number) => {
    const f = (a: number, b: number) => x >= a && x < a + 7 && y >= b && y < b + 7;
    return f(0, 0) || f(n - 7, 0) || f(0, n - 7);
  };
  for (let y = 0; y < n; y++) {
    for (let x = 0; x < n; x++) {
      let on: boolean;
      if (fin(x, y)) {
        const ix = x < 7 ? x : x - (n - 7);
        const iy = y < 7 ? y : y - (n - 7);
        on = ix === 0 || ix === 6 || iy === 0 || iy === 6 || (ix >= 2 && ix <= 4 && iy >= 2 && iy <= 4);
      } else on = rnd() > 0.55;
      if (on) rects.push(<rect key={`${x}-${y}`} x={x * cell} y={y * cell} width={cell} height={cell} />);
    }
  }
  return (
    <svg className="qr" viewBox={`0 0 ${n * cell} ${n * cell}`}>
      <g fill="#0A2A55">{rects}</g>
    </svg>
  );
};

export default Bars;
