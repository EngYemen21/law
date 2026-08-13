import { router } from '@inertiajs/react';
import React, { useRef, useState } from 'react';
import Icon from '@/lib/icons';
import { useToast } from '@/components/babylon/Toast';
import { type DocItem } from '@/lib/data';

// يطابق viewDocs في index (82).html — رفع/تنزيل حقيقيّان

const DocRow: React.FC<{ d: DocItem; outbound: boolean }> = ({ d, outbound }) => (
  <div className="item">
    <div className={`iico ${outbound ? '' : 'file-ico'}`}>
      <Icon name={outbound ? 'out' : 'file'} />
    </div>
    <div className="imeta">
      <b>{d.name}</b>
      <span>{d.meta}</span>
    </div>
    <div className="iact">
      {d.canDownload ? (
        <a className="btn soft sm" href={d.downloadUrl || `/documents/${d.id}/download`} target="_blank" rel="noopener noreferrer">
          <Icon name="download" /> تحميل
        </a>
      ) : null}
    </div>
  </div>
);

const Documents: React.FC<{ docsOut: DocItem[]; docsUp: DocItem[] }> = ({ docsOut, docsUp }) => {
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);

  const onPick = (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
      toast('حجم الملف يتجاوز الحدّ المسموح (2 ميجابايت)');
      if (fileRef.current) fileRef.current.value = '';
      return;
    }
    setBusy(true);
    router.post('/documents', { file }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => toast('تم رفع المستند'),
      onError: (err) => toast((Object.values(err)[0] as string) || 'تعذّر رفع المستند'),
      onFinish: () => { setBusy(false); if (fileRef.current) fileRef.current.value = ''; },
    });
  };

  return (
    <>
      <div className="card">
        <div className="card-h">
          <h3>المستندات الصادرة إليك</h3>
          <span className="sub">معتمدة من المكتب</span>
        </div>
        <div className="card-b">
          {docsOut.length ? (
            docsOut.map((d) => <DocRow key={d.id ?? d.name} d={d} outbound />)
          ) : (
            <div className="empty"><Icon name="out" /><b>لا مستندات صادرة</b></div>
          )}
        </div>
      </div>

      <div className="card">
        <div className="card-h">
          <h3>مستنداتك المرفوعة</h3>
          <button className="btn sm" type="button" onClick={() => fileRef.current?.click()} disabled={busy}>
            <Icon name="upload" /> {busy ? 'جارٍ الرفع…' : 'رفع ملف جديد'}
          </button>
          <input ref={fileRef} type="file" hidden onChange={onPick} />
        </div>
        <div className="card-b">
          {docsUp.length ? (
            docsUp.map((d) => <DocRow key={d.id ?? d.name} d={d} outbound={false} />)
          ) : (
            <div className="empty"><Icon name="file" /><b>لا مستندات مرفوعة بعد</b></div>
          )}
        </div>
      </div>
    </>
  );
};

export default Documents;
