import { router } from '@inertiajs/react';
import React, { useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';

// يطابق adBranches + addBranch — مربوط بموديل Branch الحقيقي

interface BranchCard { id: number; name: string; city: string; phone: string }

const AdminBranches: React.FC<{ branches: BranchCard[] }> = ({ branches }) => {
  const toast = useToast();
  const [name, setName] = useState('');
  const [city, setCity] = useState('');
  const [phone, setPhone] = useState('');

  const add = () => {
    if (!name.trim()) { toast('أدخل اسم الفرع'); return; }
    router.post('/admin/branches', { name, city, phone }, {
      preserveScroll: true,
      onSuccess: () => { setName(''); setCity(''); setPhone(''); toast('تمت إضافة الفرع'); },
      onError: (e) => toast(Object.values(e)[0] as string || 'تعذّر إضافة الفرع'),
    });
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
        <div className="card-h"><h3>الفروع</h3><span className="sub">{branches.length}</span></div>
        <div className="card-b">
          {branches.length ? branches.map((b) => (
            <div key={b.id} className="item">
              <div className="iico"><Icon name="office" /></div>
              <div className="imeta"><b>{b.name}</b><span>{b.city}{b.phone ? ` · ${b.phone}` : ''}</span></div>
            </div>
          )) : (
            <div className="empty"><Icon name="office" /><b>لا فروع بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default AdminBranches;
