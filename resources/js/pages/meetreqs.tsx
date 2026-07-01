import React from 'react';
import Icon from '@/lib/icons';

// دعوات الاجتماعات — قيد الربط الكامل

const MeetReqs: React.FC = () => (
  <div className="card">
    <div className="card-h"><h3>دعوات الاجتماعات</h3></div>
    <div className="card-b">
      <div className="empty">
        <Icon name="video" />
        <b>لا دعوات اجتماعات جديدة</b>
      </div>
    </div>
  </div>
);

export default MeetReqs;
