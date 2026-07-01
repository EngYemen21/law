import React from 'react';
import Icon from '@/lib/icons';
import Badge from '@/components/babylon/Badge';
import { TRANSFERS } from '@/lib/employee-data';

// يطابق emTransferView في index (82).html

const EmployeeTransfer: React.FC = () => (
  <div className="card">
    <div className="card-h"><h3>سجل التحويلات</h3></div>
    <div className="card-b">
      {TRANSFERS.map((r) => (
        <div key={r[0]} className="item">
          <div className="iico"><Icon name="reply" /></div>
          <div className="imeta">
            <b>{r[0]} {r[1]}</b>
            <span>{r[2]} · توزيع {r[3]}</span>
          </div>
          <div className="iact"><Badge text="محوّلة" tone="b-blue" /></div>
        </div>
      ))}
    </div>
  </div>
);

export default EmployeeTransfer;
