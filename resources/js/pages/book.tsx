import React from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// يطابق viewBook + bookConsult في index (82).html

const TYPES: [string, string, string][] = [
  ['office', 'حضورية', 'زيارة المكتب والاجتماع مع المستشار'],
  ['video', 'مرئية', 'اجتماع إلكتروني عبر الفيديو'],
  ['phone', 'هاتفية', 'مكالمة هاتفية مباشرة'],
];

const Book: React.FC = () => {
  const toast = useToast();
  return (
    <div className="card">
      <div className="card-h"><h3>اختر نوع الاستشارة</h3></div>
      <div className="card-b" style={{ padding: 18 }}>
        <div className="consults">
          {TYPES.map(([ico, title, sub]) => (
            <div key={title} className="consult">
              <div className="ci"><Icon name={ico} /></div>
              <b>{title}</b>
              <span>{sub}</span>
              <button
                className="btn block"
                type="button"
                onClick={() => toast(`تم بدء حجز استشارة ${title}`)}
              >
                احجز الآن
              </button>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export default Book;
