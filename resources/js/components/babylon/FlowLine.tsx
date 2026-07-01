import React from 'react';

// يطابق flowHTML(steps,cur) في index (82).html
const FlowLine: React.FC<{ steps: string[]; cur: number }> = ({ steps, cur }) => (
  <div className="flowline">
    {steps.map((s, i) => (
      <React.Fragment key={s}>
        <span className={`fstep ${i < cur ? 'done' : i === cur ? 'cur' : ''}`}>
          <span className="fdot" />
          {s}
        </span>
        {i < steps.length - 1 && <span className="farr">‹</span>}
      </React.Fragment>
    ))}
  </div>
);

export default FlowLine;
