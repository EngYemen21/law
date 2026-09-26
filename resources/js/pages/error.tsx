import { Head, Link } from '@inertiajs/react';
import React from 'react';
import AppLayout from '@/components/layouts/AppLayout';

/**
 * **صفحة الخطأ داخل التطبيق** — ما لا يوجد (٤٠٤) أو ما لا يُفتح بالرابط (٤٠٥) أو عطل الخادم.
 *
 * يبنيها `App\Support\ErrorResponse::page()` بالرمز الصحيح، والعنوان والسبب من خريطته الواحدة —
 * الصفحة لا تكتب نصّاً عن رمزٍ بنفسها فتتباعد عن الخادم. والرفضُ لسببٍ (٤٠٣/٤٢٢) لا يصل هنا:
 * يعود صاحبه إلى حيث جاء بإشعار.
 */
interface ErrorPageProps {
  status: number;
  title: string;
  message: string;
  auth?: { user?: { home?: string } | null } | null;
}

const ErrorPage: React.FC<ErrorPageProps> & { layout?: (page: React.ReactElement<ErrorPageProps>) => React.ReactNode } = ({
  status,
  title,
  message,
  auth,
}) => {
  const home = auth?.user?.home ?? '/';

  return (
    <>
      <Head title={title} />
      <div style={{ display: 'flex', justifyContent: 'center', padding: '48px 16px' }}>
        <div className="card" style={{ maxWidth: 460, width: '100%', textAlign: 'center' }}>
          <div className="card-b" style={{ padding: '36px 28px' }}>
            <div style={{ fontSize: 44, fontWeight: 800, color: 'var(--brand, #0e5c9c)', lineHeight: 1 }}>{status}</div>
            <h1 style={{ fontSize: 20, margin: '14px 0 10px' }}>{title}</h1>
            <p style={{ color: 'var(--muted)', lineHeight: 1.9, margin: '0 0 24px' }}>{message}</p>
            <div style={{ display: 'flex', gap: 10, justifyContent: 'center', flexWrap: 'wrap' }}>
              <Link href={home} className="btn primary">
                {auth?.user ? 'العودة إلى لوحتي' : 'العودة إلى الصفحة الرئيسيّة'}
              </Link>
              <button type="button" className="btn soft" onClick={() => window.history.back()}>
                الصفحة السابقة
              </button>
            </div>
          </div>
        </div>
      </div>
    </>
  );
};

// داخل تخطيط اللوحة لمن سجّل دخوله (الشريط الجانبيّ يبقى فلا يضيع صاحبه)، ومستقلّةً للزائر:
// التخطيط يقرأ خصائص المستخدم المشتركة، والزائر بلا مستخدمٍ يُبنى له شريط.
ErrorPage.layout = (page) => (page.props.auth?.user ? <AppLayout>{page}</AppLayout> : page);

export default ErrorPage;
