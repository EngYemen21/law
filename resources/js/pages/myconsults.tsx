import React from 'react';
import Icon from '@/lib/icons';

// استشاراتي — قيد الربط الكامل

const MyConsults: React.FC = () => (
  <div className="card">
    <div className="card-h"><h3>استشاراتي</h3></div>
    <div className="card-b">
      <div className="empty">
        <Icon name="folder" />
        <b>لا استشارات حالية</b>
      </div>
    </div>
  </div>
);

export default MyConsults;
