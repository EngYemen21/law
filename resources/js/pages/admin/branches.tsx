import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { type Branch, BRANCHES } from '@/lib/admin-data';

// يطابق adBranches + addBranch في index (82).html

const AdminBranches: React.FC = () => {
  const toast = useToast();
  const [list, setList] = useState<Branch[]>(() => BRANCHES.map((b) => ({ ...b })));
  const [name, setName] = useState('');
  const [city, setCity] = useState('');
  const [phone, setPhone] = useState('');

  const add = () => {
    if (!name.trim()) { toast('أدخل اسم الفرع'); return; }
    setList((p) => [...p, { name: name.trim(), city: city || '—', phone: phone || '—' }]);
    setName(''); setCity(''); setPhone('');
    toast('تمت إضافة الفرع');
  };

  return (
    <>
      <div className="greet">
        <h2>الفروع</h2>
        <p>إضافة فروع المكتب وإدارتها.</p>
      </div>
      <div className="card">
        <div className="card-h"><h3>إضافة فرع</h3></div>
        <div className="card-b" style={{ padding: 18 }}>
          <div className="picker-grid">
            <div className="field">
              <label>اسم الفرع</label>
              <input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="مثال: فرع مكة" />
            </div>
            <div className="field">
              <label>المدينة</label>
              <input className="input" value={city} onChange={(e) => setCity(e.target.value)} placeholder="المدينة" />
            </div>
          </div>
          <div className="field">
            <label>الهاتف</label>
            <input className="input" value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="01x xxx xxxx" />
          </div>
          <button className="btn" onClick={add} type="button"><Icon name="office" /> إضافة الفرع</button>
        </div>
      </div>
      <div className="card">
        <div className="card-h"><h3>الفروع</h3><span className="sub">{list.length}</span></div>
        <div className="card-b">
          {list.map((b) => (
            <div key={b.name} className="item">
              <div className="iico"><Icon name="office" /></div>
              <div className="imeta"><b>{b.name}</b><span>{b.city} · {b.phone}</span></div>
            </div>
          ))}
        </div>
      </div>
    </>
  );
};

export default AdminBranches;
