import { Link, usePage } from '@inertiajs/react';
import React, { useEffect } from 'react';
import Icon from '@/lib/icons';

// ============================================================================
// صفحة طباعة وتصدير المستند القانوني (Print / PDF View)
// ============================================================================

interface DocData {
  id: number;
  title: string;
  type: string;
  typeLabel: string;
  status: string;
  statusLabel: string;
  author: string;
  ticketNo: string | null;
  caseNo?: string | null;
  updatedAt: string;
  createdAt: string;
  approved: boolean;
  approvedBy: string | null;
  approvedAt: string | null;
  contentHtml: string;
  headerConfig: {
    showHeader: boolean;
    officeName: string;
    officeNameEn: string;
    logoUrl: string;
    address: string;
    phone: string;
    email: string;
    licenseNo: string;
  };
}

interface Props {
  document: DocData;
}

const EditorPrint: React.FC<Props> = ({ document: doc }) => {
  const { url } = usePage();
  const base = (url as string).startsWith('/admin')
    ? '/admin'
    : (url as string).startsWith('/employee')
      ? '/employee'
      : '/lawyer';
  const header = doc.headerConfig || {
    showHeader: true,
    officeName: 'مكتب المحاماة',
    officeNameEn: 'Law Office',
    logoUrl: '/images/021.png',
    address: '',
    phone: '',
    email: '',
    licenseNo: '',
  };

  useEffect(() => {
    // تركيز الصفحة للطباعة السريعة إن رغب المستخدم
    const handleKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
      }
    };
    window.addEventListener('keydown', handleKey);
    return () => window.removeEventListener('keydown', handleKey);
  }, []);

  return (
    <div className="legal-print-page" style={{ background: '#f0f3f6', minHeight: '100vh', padding: '24px 16px', direction: 'rtl' }}>
      {/* ── شريط التحكم العلوي (يختفي عند الطباعة) ── */}
      <div
        className="print-controls-bar"
        style={{
          maxWidth: 860,
          margin: '0 auto 20px',
          background: '#ffffff',
          borderRadius: 12,
          padding: '12px 20px',
          display: 'flex',
          justifyContent: 'space-between',
          alignItems: 'center',
          boxShadow: '0 2px 10px rgba(10, 42, 85, 0.08)',
          border: '1px solid #e1e8ee',
        }}
      >
        <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
          <Link
            href={`${base}/editor/${doc.id}`}
            className="btn soft sm"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13 }}
          >
            <Icon name="reply" /> العودة للمحرر
          </Link>
          <span style={{ fontSize: 14, fontWeight: 700, color: '#13314f' }}>
            معاينة الطباعة والتصدير: {doc.title}
          </span>
        </div>

        <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
          <a
            href={`${base}/editor/${doc.id}/pdf`}
            className="btn primary sm"
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, height: 36, padding: '0 18px', textDecoration: 'none' }}
          >
            <Icon name="download" /> تحميل ملف PDF
          </a>
          <button
            type="button"
            className="btn soft sm"
            onClick={() => window.print()}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, fontSize: 13, height: 36, padding: '0 14px' }}
          >
            <Icon name="upload" /> طباعة ورقية
          </button>
        </div>
      </div>

      {/* ── ورقة المستند الرسمية (A4) ── */}
      <div
        className="legal-print-container"
        style={{
          maxWidth: 860,
          margin: '0 auto',
          background: '#ffffff',
          padding: '48px 56px',
          borderRadius: 8,
          boxShadow: '0 4px 20px rgba(10, 42, 85, 0.06)',
          border: '1px solid #e1e8ee',
          boxSizing: 'border-box',
          minHeight: '1100px',
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        {/* ── الترويسة الرسمية ── */}
        {header.showHeader && (
          <div style={{ marginBottom: 28, borderBottom: '2.5px solid #0e5c9c', paddingBottom: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 16 }}>
              {/* شعار المنصة / المكتب */}
              <div style={{ flex: '0 0 auto' }}>
                <img
                  src={header.logoUrl || '/images/021.png'}
                  alt="شعار"
                  style={{ maxHeight: 60, maxWidth: 160, objectFit: 'contain' }}
                />
              </div>

              {/* اسم المكتب */}
              <div style={{ textAlign: 'center', flex: 1 }}>
                <div style={{ fontSize: 18, fontWeight: 800, color: '#0a2a55', marginBottom: 2 }}>
                  {header.officeName || 'مكتب المحاماة والاستشارات القانونية'}
                </div>
                {/* {header.officeNameEn && (
                  <div style={{ fontSize: 12, color: '#607689', fontFamily: 'sans-serif', letterSpacing: 0.5 }}>
                    {header.officeNameEn}
                  </div>
                )} */}
                {header.licenseNo && (
                  <div style={{ fontSize: 11.5, color: '#607689', marginTop: 3 }}>
                    ترخيص رقم: {header.licenseNo}
                  </div>
                )}
              </div>

              {/* بيانات الاتصال */}
              <div style={{ textAlign: 'left', fontSize: 11, color: '#607689', lineHeight: 1.6, flex: '0 0 auto' }}>
                {header.phone && <div>هاتف: {header.phone}</div>}
                {header.email && <div>بريد: {header.email}</div>}
                {header.address && <div>{header.address}</div>}
              </div>
            </div>
          </div>
        )}

        {/* ── شريط المراجع والتوثيق ── */}
        {/* <div
          style={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            fontSize: 12,
            color: '#607689',
            background: '#f8fafc',
            border: '1px solid #edf2f6',
            borderRadius: 6,
            padding: '6px 14px',
            marginBottom: 24,
          }}
        >
          <div>
            الرقم المرجعي: <strong style={{ color: '#13314f' }}>DOC-{doc.id.toString().padStart(5, '0')}</strong>
          </div>
          <div>
            التصنيف: <strong style={{ color: '#0e5c9c' }}>{doc.typeLabel}</strong>
          </div>
          {doc.caseNo && (
            <div>
              القضية: <strong style={{ color: '#0e5c9c' }}>{doc.caseNo}</strong>
            </div>
          )}
          {doc.ticketNo && (
            <div>
              التذكرة: <strong style={{ color: '#13314f' }}>{doc.ticketNo}</strong>
            </div>
          )}
          <div>
            التاريخ: <strong style={{ color: '#13314f' }}>{doc.createdAt}</strong>
          </div>
        </div> */}

        {/* ── عنوان المستند الرئيسي ── */}
        {/* <h1
          style={{
            textAlign: 'center',
            fontSize: 22,
            fontWeight: 800,
            color: '#0a2a55',
            margin: '0 0 28px',
            paddingBottom: 8,
            borderBottom: '1px solid #edf2f6',
          }}
        >
          {doc.title}
        </h1> */}

        {/* ── متن المستند (المحتوى المنسق) ── */}
        <div
          className="legal-editor-content"
          style={{ flex: 1, minHeight: 600 }}
          dangerouslySetInnerHTML={{ __html: doc.contentHtml }}
        />

        {/* ── التذييل وخاتمة التوثيق والاعتماد ── */}
        <div style={{ marginTop: 48, paddingTop: 20, borderTop: '1px solid #e1e8ee' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-end', gap: 24 }}>
            <div>
              <div style={{ fontSize: 12, color: '#607689' }}>حرر بواسطة:</div>
              <div style={{ fontSize: 14, fontWeight: 700, color: '#13314f', marginTop: 4 }}>
                {doc.author || 'المحامي المختص'}
              </div>
            </div>

            {/* ختم الاعتماد الرسمي إن كان معتمداً */}
            {doc.approved && (
              <div
                style={{
                  border: '2px solid #1e9d6b',
                  borderRadius: 8,
                  padding: '8px 16px',
                  textAlign: 'center',
                  background: '#e7f6ef',
                  color: '#1e9d6b',
                }}
              >
                <div style={{ fontSize: 13, fontWeight: 800 }}>✓ معتمد رسمياً من الإدارة</div>
                {doc.approvedBy && <div style={{ fontSize: 11, marginTop: 2 }}>المعتمد: {doc.approvedBy}</div>}
                {doc.approvedAt && <div style={{ fontSize: 10, marginTop: 1 }}>بتاريخ: {doc.approvedAt}</div>}
              </div>
            )}

            <div style={{ textAlign: 'left' }}>
              <div style={{ fontSize: 12, color: '#607689' }}>التوقيع والختم</div>
              <div style={{ height: 44, width: 130, borderBottom: '1px dashed #90a2b2', marginTop: 8 }} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

(EditorPrint as any).layout = (page: React.ReactNode) => page;

export default EditorPrint;
