import { router } from '@inertiajs/react';
import axios from 'axios';
import React, { useEffect, useRef, useState } from 'react';
import Icon from '@/lib/icons';

// ============================================================
// غرفة الجلسة المرئية — تضمين Zoom (Meeting SDK / Component View) داخل المنصّة
// يستبدل الفتح الخارجي (window.open) بمكالمة مضمّنة؛ ويتدرّج للفتح الخارجي عند
// غياب المفاتيح أو الاجتماع الحقيقي (لا يكسر بيئات الاختبار/التطوير).
// ============================================================

interface SignaturePayload {
  sdkKey: string;
  signature: string;
  meetingNumber: string;
  password: string;
  userName: string;
  userEmail: string;
  role: number;
  zak: string | null;
}

// تفاصيل الغرفة المخصّصة (اختيارية): تُفعّل إطار الهوية واللوحة الجانبية وشاشات ما قبل/بعد.
// عند غيابها تبقى الغرفة بسيطة (توافق رجعي مع غرف الاستشارات).
export interface RoomDetails {
  title: string; // عنوان الجلسة (شريط الهوية + لوحة التفاصيل)
  status?: string; // حالة الاجتماع (قادم/جارٍ/منتهٍ…)
  rows?: { k: string; v: string }[]; // العميل/المحامي/الموعد/المرجع…
  agenda?: { before?: string[]; during?: string[]; after?: string[] };
  summaryHref?: string; // رابط صفحة الاجتماع/الملخّص (شاشة ما بعد الجلسة)
}

interface Props {
  cref: string; // مرجع الكيان (CN-… للاستشارة، M-… للاجتماع) لطلب التوقيع
  kind?: 'consult' | 'meeting'; // نوع الكيان (افتراضي: استشارة)
  label: string; // «CN-2026-1042 · استشارة مرئية»
  back: string; // مسار الرجوع
  fallbackUrl?: string; // رابط Zoom الخارجي للتدرّج الآمن
  viewer: 'client' | 'staff';
  details?: RoomDetails; // تفاصيل الغرفة المخصّصة (اجتماعات المكتب)
}

// الحد الأدنى من واجهة Zoom Component View المستخدمة هنا (الحزمة تُحمّل كسولاً وقت التشغيل)
interface ZoomClient {
  init(opts: {
    zoomAppRoot: HTMLElement;
    language?: string;
    patchJsMedia?: boolean;
    customize?: { video?: { isResizable?: boolean } };
  }): Promise<unknown>;
  join(opts: {
    signature: string;
    meetingNumber: string;
    password?: string;
    userName: string;
    userEmail?: string;
    zak?: string;
  }): Promise<unknown>;
  leaveMeeting(): Promise<unknown>;
  on?(event: string, callback: (payload: { state?: string }) => void): void;
}
interface ZoomEmbedded {
  createClient(): ZoomClient;
  destroyClient(): void;
}

// يُحمّل Component View من CDN عمداً: يتطلّب Component View وجود React/ReactDOM كمتغيّرات عامّة
// (window.React). نحمّل نسخة Zoom المرفقة (React 18) كـglobals — معزولة تماماً عن React 19 المضيف
// (المضيف يستورد React من الحزمة لا من window)، ويتفادى تعارض الحزمة عبر npm مع React 19.
const ZOOM_SDK_VERSION = '6.2.0';
const ZOOM_BASE = `https://source.zoom.us/${ZOOM_SDK_VERSION}`;
const ZOOM_SCRIPTS = [
  `${ZOOM_BASE}/lib/vendor/react.min.js`,
  `${ZOOM_BASE}/lib/vendor/react-dom.min.js`,
  `${ZOOM_BASE}/zoom-meeting-embedded-${ZOOM_SDK_VERSION}.min.js`,
];

declare global {
  interface Window {
    ZoomMtgEmbedded?: ZoomEmbedded;
    __zoomSdkLoad?: Promise<ZoomEmbedded>;
  }
}

function loadScript(src: string): Promise<void> {
  return new Promise((resolve, reject) => {
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${src}"]`);

    if (existing) {
      resolve();

      return;
    }

    const s = document.createElement('script');
    s.src = src;
    s.async = false; // يحافظ على ترتيب التحميل (React قبل ReactDOM قبل SDK)
    s.onload = () => resolve();
    // نزيل العقدة الفاشلة كي تُعاد إضافتها عند المحاولة التالية (وإلا حُلّ فوراً بسكربت فاشل)
    s.onerror = () => { s.remove(); reject(new Error(`failed to load ${src}`)); };
    document.head.appendChild(s);
  });
}

function loadZoomSdk(): Promise<ZoomEmbedded> {
  if (window.ZoomMtgEmbedded) {
    return Promise.resolve(window.ZoomMtgEmbedded);
  }

  if (window.__zoomSdkLoad) {
    return window.__zoomSdkLoad;
  }

  const load = (async () => {
    for (const src of ZOOM_SCRIPTS) {
      await loadScript(src);
    }

    if (! window.ZoomMtgEmbedded) {
      throw new Error('ZoomMtgEmbedded global missing');
    }

    return window.ZoomMtgEmbedded;
  })();

  // عند الفشل نُفرّغ الوعد المخبّأ كي تُعاد المحاولة عند الدخول التالي
  // (كان خطأ CDN عابر يخزّن وعداً مرفوضاً فتبقى الغرفة في الاحتياط حتى إعادة تحميل كاملة)
  window.__zoomSdkLoad = load.catch((e) => {
    window.__zoomSdkLoad = undefined;
    throw e;
  });

  return window.__zoomSdkLoad;
}

type Phase = 'loading' | 'joining' | 'joined' | 'ended' | 'fallback' | 'error';

export const ZoomEmbedRoom: React.FC<Props> = ({ cref, kind = 'consult', label, back, fallbackUrl, details }) => {
  'use no memo'; // تركيب Zoom تحكّمي (refs/تأثيرات) — خارج مُحسّن React

  const rootRef = useRef<HTMLDivElement>(null);
  const clientRef = useRef<ZoomClient | null>(null);
  const [phase, setPhase] = useState<Phase>('loading');
  const [msg, setMsg] = useState('');
  const [userName, setUserName] = useState('');
  const [elapsed, setElapsed] = useState(0); // ثوانٍ منذ الانضمام (المؤقّت التصاعدي)
  const [retryKey, setRetryKey] = useState(0); // إعادة المحاولة داخل الموقع

  // مؤقّت الجلسة التصاعدي — يعمل أثناء الانضمام فقط
  useEffect(() => {
    if (phase !== 'joined') {
      return;
    }
    const t = setInterval(() => setElapsed((s) => s + 1), 1000);

    return () => clearInterval(t);
  }, [phase]);

  useEffect(() => {
    let cancelled = false;
    let embedded: ZoomEmbedded | null = null;

    // يُفكّك أي عميل/واجهة Zoom رُكِّبت فعلاً (init() يُظهر واجهته الخاصة حتى قبل نجاح join()،
    // مثل «The meeting has not started») — وإلا تبقى ظاهرة خلف بطاقة الخطأ/التدرّج البديل.
    const teardown = () => {
      try {
        void clientRef.current?.leaveMeeting();
        embedded?.destroyClient();
      } catch {
        /* تنظيف صامت */
      }
      clientRef.current = null;
      embedded = null;
    };

    const fail = (m: string) => {
      if (cancelled) {
return;
}

      teardown();
      setMsg(m);
      setPhase(fallbackUrl ? 'fallback' : 'error');
    };

    void (async () => {
      // 1) طلب توقيع الانضمام (التفويض/الدور على الخادم)
      let data: SignaturePayload;

      try {
        const res = await axios.post<SignaturePayload>('/zoom/sdk-signature', { ref: cref, kind });
        data = res.data;
        setUserName(data.userName || '');
      } catch (e) {
        const status = axios.isAxiosError(e) ? e.response?.status : undefined;
        fail(status === 503 ? 'تضمين Zoom غير مُهيّأ بعد.' : 'تعذّر تجهيز غرفة الجلسة.');

        return;
      }

      // 2) تحميل مكتبة Zoom من CDN عند الطلب (تُعزل عن بقية الصفحات وعن React المضيف)
      try {
        embedded = await loadZoomSdk();
      } catch {
        fail('تعذّر تحميل مكتبة Zoom.');

        return;
      }

      if (cancelled || !rootRef.current) {
return;
}

      // 3) التهيئة والانضمام (الاسم/الإيميل تلقائياً؛ ZAK للمضيف)
      try {
        setPhase('joining');
        const client = embedded.createClient();
        clientRef.current = client;
        await client.init({
          zoomAppRoot: rootRef.current,
          language: 'en-US',
          patchJsMedia: true,
          customize: { video: { isResizable: true } },
        });

        // انتهاء الجلسة/المغادرة عبر واجهة Zoom نفسها → شاشة «انتهت الجلسة» المخصّصة (أفضل-جهد)
        try {
          client.on?.('connection-change', (p) => {
            if (! cancelled && (p?.state === 'Closed' || p?.state === 'Fail')) {
              setPhase('ended');
            }
          });
        } catch { /* واجهة الأحداث قد تختلف بين إصدارات SDK */ }

        if (cancelled) {
return;
}

        await client.join({
          signature: data.signature,
          meetingNumber: data.meetingNumber,
          password: data.password,
          userName: data.userName,
          userEmail: data.userEmail,
          zak: data.zak || undefined,
        });

        if (cancelled) {
return;
}

        setPhase('joined');
      } catch (e) {
        const reason = String((e as { reason?: string; errorMessage?: string })?.reason
          ?? (e as { reason?: string; errorMessage?: string })?.errorMessage ?? '').toLowerCase();
        fail(reason.includes('not start') ? 'لم يبدأ المضيف الجلسة بعد — حاول مرة أخرى عند بدء الموعد.' : 'تعذّر الانضمام إلى الجلسة.');
      }
    })();

    return () => {
      cancelled = true;
      teardown();
    };
  }, [cref, kind, fallbackUrl, retryKey]);

  const fmtTimer = (s: number) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`;
  const retry = () => { setMsg(''); setElapsed(0); setPhase('loading'); setRetryKey((k) => k + 1); };
  const leave = () => { void clientRef.current?.leaveMeeting().catch(() => {}); setPhase('ended'); };

  // ── الغرفة البسيطة (استشارات) — سلوك أصلي دون تغيير ──
  if (! details) {
    return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <div style={{ marginBottom: 14, display: 'flex', gap: 9, flexWrap: 'wrap', alignItems: 'center' }}>
        <button className="btn soft sm" onClick={() => router.visit(back)} type="button">
          <Icon name="reply" /> رجوع
        </button>
        <span style={{ opacity: 0.75, fontSize: 12 }}>{label}</span>
      </div>

      {(phase === 'loading' || phase === 'joining') && (
        <div className="card"><div className="card-b">
          <div className="empty">
            <Icon name="video" />
            <b>{phase === 'loading' ? 'يجري تجهيز غرفة الجلسة…' : 'يجري الانضمام إلى Zoom…'}</b>
          </div>
        </div></div>
      )}

      {phase === 'fallback' && (
        <div className="card"><div className="card-b">
          <div className="empty">
            <Icon name="video" />
            <b>تعذّر التضمين داخل المنصّة</b>
            <span>{msg} يمكنك فتح الجلسة في Zoom مباشرة.</span>
            <button className="btn sm" style={{ marginTop: 10 }} onClick={() => {
 if (fallbackUrl) {
window.open(fallbackUrl, '_blank', 'noopener');
} 
}} type="button">
              <Icon name="video" /> فتح Zoom في تبويب
            </button>
          </div>
        </div></div>
      )}

      {phase === 'error' && (
        <div className="card"><div className="card-b">
          <div className="empty"><Icon name="video" /><b>{msg || 'تعذّر بدء الجلسة'}</b></div>
        </div></div>
      )}

      {/* حاوية تضمين Zoom (Component View) — تبقى في DOM ليركّب SDK داخلها عند التهيئة */}
      <div
        ref={rootRef}
        style={{ minHeight: phase === 'joined' || phase === 'joining' ? 520 : 0, direction: 'ltr' }}
      />
    </div>
    );
  }

  // ── الغرفة المخصّصة (اجتماعات المكتب) — إطار هوية + لوحة جانبية + شاشات الحالة ──
  const recording = kind === 'meeting'; // اجتماعات المكتب تُسجَّل سحابيًّا تلقائياً

  const overlay = (() => {
    if (phase === 'loading' || phase === 'joining') {
      return (
        <div className="mroom-overlay"><div>
          <div className="mroom-spin" style={{ margin: '0 auto 12px' }} />
          <b>{phase === 'loading' ? 'يجري تجهيز غرفة الجلسة…' : 'يجري الانضمام إلى Zoom…'}</b>
          <div className="os">{details.title}</div>
        </div></div>
      );
    }
    if (phase === 'ended') {
      return (
        <div className="mroom-overlay"><div>
          <div className="oi"><Icon name="check" /></div>
          <b>انتهت الجلسة</b>
          <div className="os">شكراً لك.{details.summaryHref ? ' يمكنك الاطّلاع على المحضر والملخّص من صفحة الاجتماع.' : ''}</div>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center', marginTop: 16 }}>
            <button className="btn sm" onClick={() => router.visit(details.summaryHref || back)} type="button">
              <Icon name={details.summaryHref ? 'doc' : 'reply'} /> {details.summaryHref ? 'صفحة الاجتماع' : 'رجوع'}
            </button>
            {details.summaryHref && <button className="btn soft sm" onClick={() => router.visit(back)} type="button">رجوع للاجتماعات</button>}
          </div>
        </div></div>
      );
    }
    if (phase === 'fallback' || phase === 'error') {
      return (
        <div className="mroom-overlay"><div>
          <div className="oi"><Icon name="video" /></div>
          <b>{phase === 'fallback' ? 'تعذّر التضمين داخل الموقع' : (msg || 'تعذّر بدء الجلسة')}</b>
          <div className="os">{msg}</div>
          <div style={{ display: 'flex', gap: 8, justifyContent: 'center', marginTop: 16 }}>
            <button className="btn sm" onClick={retry} type="button"><Icon name="video" /> إعادة المحاولة داخل الموقع</button>
          </div>
          {fallbackUrl && phase === 'fallback' && (
            <div style={{ marginTop: 10 }}>
              <button
                onClick={() => window.open(fallbackUrl, '_blank', 'noopener')}
                style={{ background: 'none', border: 'none', color: 'rgba(255,255,255,.55)', fontSize: 11, textDecoration: 'underline', cursor: 'pointer' }}
                type="button"
              >فتح في Zoom كحلٍّ أخير</button>
            </div>
          )}
        </div></div>
      );
    }

    return null;
  })();

  return (
    <div>
      <div style={{ marginBottom: 12, display: 'flex', gap: 9, flexWrap: 'wrap', alignItems: 'center' }}>
        <button className="btn soft sm" onClick={() => router.visit(back)} type="button"><Icon name="reply" /> رجوع</button>
        <span style={{ opacity: 0.7, fontSize: 12 }}>{label}</span>
      </div>

      <div className="mroom-grid">
        <div className="mroom">
          <div className="mroom-head">
            <span className="brand"><Icon name="video" /> سلاسل بابل</span>
            <span className="ttl">{details.title}</span>
            {phase === 'joined' && (
              <>
                <span className="vr-timer">{fmtTimer(elapsed)}</span>
                {recording && <span className="vr-rec"><span className="dot" /> تسجيل</span>}
                <button className="btn sm" style={{ background: '#C0392B', marginInlineStart: 8 }} onClick={leave} type="button">
                  <Icon name="reply" /> مغادرة
                </button>
              </>
            )}
          </div>
          <div className="mroom-video">
            <div ref={rootRef} style={{ minHeight: 520, direction: 'ltr' }} />
            {overlay}
            {phase === 'joined' && userName && <div className="mroom-wm">{userName} · {cref}</div>}
          </div>
        </div>

        <aside className="mroom-side card">
          <div className="card-h"><h3>تفاصيل الجلسة</h3>{details.status && <span className="sub">{details.status}</span>}</div>
          <div className="card-b">
            {details.rows?.map((r, i) => (
              <div key={i} className="mroom-row"><span className="k">{r.k}</span><span className="v">{r.v}</span></div>
            ))}
            {details.agenda && (
              <div className="mroom-agd">
                {!!details.agenda.before?.length && <><h5>قبل الاجتماع</h5><ul>{details.agenda.before.map((x, i) => <li key={i}>{x}</li>)}</ul></>}
                {!!details.agenda.during?.length && <><h5>أثناء الاجتماع</h5><ul>{details.agenda.during.map((x, i) => <li key={i}>{x}</li>)}</ul></>}
                {!!details.agenda.after?.length && <><h5>بعد الاجتماع</h5><ul>{details.agenda.after.map((x, i) => <li key={i}>{x}</li>)}</ul></>}
              </div>
            )}
          </div>
        </aside>
      </div>
    </div>
  );
};

export default ZoomEmbedRoom;
