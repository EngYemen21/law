import React from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { type DocItem } from '@/lib/data';

// يطابق viewDocs في index (82).html

const DocRow: React.FC<{ d: DocItem; outbound: boolean }> = ({ d, outbound }) => {
  const toast = useToast();
  return (
    <div className="item">
      <div className={`iico ${outbound ? '' : 'file-ico'}`}>
        <Icon name={outbound ? 'out' : 'file'} />
      </div>
      <div className="imeta">
        <b>{d.name}</b>
        <span>{d.meta}</span>
      </div>
      <div className="iact">
        <button className="btn soft sm" type="button" onClick={() => toast(`جارٍ تحميل ${d.name}`)}>
          <Icon name="download" /> تحميل
        </button>
      </div>
    </div>
  );
};

const Documents: React.FC<{ docsOut: DocItem[]; docsUp: DocItem[] }> = ({ docsOut, docsUp }) => {
  const toast = useToast();
  return (
    <>
      <div className="card">
        <div className="card-h">
          <h3>المستندات الصادرة إليك</h3>
          <span className="sub">معتمدة من المكتب</span>
        </div>
        <div className="card-b">
          {docsOut.map((d) => <DocRow key={d.name} d={d} outbound />)}
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>مستنداتك المرفوعة</h3>
          <button className="btn sm" type="button" onClick={() => toast('تم رفع المستند بنجاح')}>
            <Icon name="upload" /> رفع ملف جديد
          </button>
        </div>
        <div className="card-b">
          {docsUp.map((d) => <DocRow key={d.name} d={d} outbound={false} />)}
        </div>
      </div>
    </>
  );
};

export default Documents;
