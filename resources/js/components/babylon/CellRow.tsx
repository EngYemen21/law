import React from 'react';

/** صفّ بيانات بخلايا «عنوان ← قيمة» (`.lwf-cells`) — لبطاقات ملفّ التنفيذ. */
const CellRow: React.FC<{ cells: [string, string][] }> = ({ cells }) => (
  <div className="lwf-cells">
    {cells.map((c, i) => (
      <div className="cell" key={i}>
        <div className="cl">{c[0]}</div>
        <div className="cv">{c[1]}</div>
      </div>
    ))}
  </div>
);

export default CellRow;
