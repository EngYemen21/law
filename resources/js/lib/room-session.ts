import axios from 'axios';
import { useSyncExternalStore } from 'react';
import { echo } from '@/lib/echo';
import { isStaffRoom, ROOM_TEXT, roomKey } from '@/lib/room';
import type { Room, RoomStatePayload } from '@/lib/room';

/**
 * **جلسةُ الغرفة الحيّة — واحدةٌ للتبويب كلّه، تعيش خارج الصفحات.**
 *
 * كانت كلّ صفحة غرفة تركّب عميل Zoom داخل شجرتها، فالتنقّل بـInertia إلى أيّ صفحةٍ أخرى يفكّك
 * المكوّن ويُسقط المكالمة — يفتح المحامي ملفّ القضية ليجيب سؤالاً فيخرج من الجلسة. فصار كلُّ ما يخصّ
 * المكالمة هنا، في وحدةٍ لا تُفكَّك ما دام التبويب مفتوحاً:
 *
 * - **حاويةُ Zoom عقدةٌ واحدة** تُلحَق بـ`document.body` مرّةً ولا تنتقل أبداً. Component View يركّب
 *   شجرته (React 18 خاصّته) داخلها، ونقلُ عقدته بين حاوياتٍ يخاطر بلوحات الفيديو؛ فالموضع يتغيّر
 *   بـCSS وحده: `data-view="full"` تملأ التبويب تحت الترويسة، و`dock` نافذةٌ مصغّرة عائمة.
 * - **العميلُ والتوقيعُ والاشتراكُ اللحظيّ** هنا لا في الصفحة: صفحة الغرفة تفتح الجلسة وتعرضها،
 *   وتفكيكها يصغّرها ولا يُنهيها. والصفحات الأخرى يرسم فوقها `RoomDock` (من `app.tsx`) شريطاً
 *   بزرّي «العودة إلى الجلسة» و«مغادرة».
 * - **المغادرة ليست إنهاءً:** لا نستدعي `endMeeting` أبداً — `leaveMeeting` يُخرجك وحدك وتبقى الجلسة
 *   للآخرين ولو كنتَ المضيف. الإنهاء للجميع فعلُ الخادم وحده عبر `room.endAction`.
 * - **إعادة التحميل الكاملة** تقطع المكالمة حتماً (سياق JavaScript جديد)؛ فيُحذَّر منها بـ`beforeunload`،
 *   وإن وقعت عرض الشريط دعوةً للعودة من `sessionStorage`.
 *
 * والحالةُ مخزنٌ خارجيّ يُقرأ بـ`useSyncExternalStore` — لا سياقَ React يُفقد بتبدّل التخطيط.
 */

// ——————————————————————— مكتبة Zoom ———————————————————————

interface SignaturePayload {
  sdkKey: string;
  signature: string;
  meetingNumber: string;
  password: string;
  userName: string;
  userEmail: string;
  role: number;
  zak: string | null;
  /** مفتاح حساب الطاقم لأحداث Zoom (`customer_key`) — به تُعرف حالة المحامي «في جلسة الآن» */
  customerKey: string | null;
}

interface VideoSize { width: number; height: number }

// الحد الأدنى من واجهة Zoom Component View المستخدمة هنا (الحزمة تُحمّل كسولاً وقت التشغيل)
interface ZoomClient {
  init(opts: {
    zoomAppRoot: HTMLElement;
    language?: string;
    patchJsMedia?: boolean;
    customize?: {
      video?: {
        isResizable?: boolean;
        // `VideoPopperStyle` في تعريفات SDK 6.2.0 يستثني `anchorElement` و`placement` — لا يُقبلان للفيديو
        popper?: { disableDraggable?: boolean };
        viewSizes?: { default?: VideoSize; ribbon?: VideoSize };
        defaultViewType?: 'speaker' | 'gallery' | 'ribbon' | 'minimized' | 'active';
      };
    };
  }): Promise<unknown>;
  join(opts: {
    signature: string;
    meetingNumber: string;
    password?: string;
    userName: string;
    userEmail?: string;
    zak?: string;
    customerKey?: string;
  }): Promise<unknown>;
  leaveMeeting(): Promise<unknown>;
  updateVideoOptions?(opts: { viewSizes?: { default?: VideoSize; ribbon?: VideoSize } }): unknown;
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

/*
 * **لغةُ واجهة Zoom: الإنجليزيّة لأنّ العربيّة غير مدعومة.** قائمة اللغات المقبولة في حزمة 6.2.0
 * نفسها (فُحصت ٢٠٢٦-٠٩-٢٦): en-US · de-DE · es-ES · fr-FR · jp-JP · pt-PT · ru-RU · zh-CN · zh-TW ·
 * ko-KO · vi-VN · it-IT · pl-PL · tr-TR · id-ID · nl-NL · sv-SE — بلا ar-SA. وتمريرُ لغةٍ خارجها
 * يُرفض. فإن أضافتها نسخةٌ لاحقة فهذا موضعها الواحد.
 */
const ZOOM_LANGUAGE = 'en-US';

/** ارتفاعٌ يُترك لشريط أدوات Zoom تحت الفيديو داخل الحاوية. */
const ZOOM_TOOLBAR_PX = 56;

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
    s.onerror = () => {
      s.remove();
      reject(new Error(`failed to load ${src}`));
    };
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

// ——————————————————————— الحالة ———————————————————————

/**
 * - `left`: غادرتَ أنت (أو انقطع اتصالك) والجلسة قائمة — يُعرض «الانضمام من جديد».
 * - `ended`: أنهاها الخادم (بثّ `.room.state` أو `room.ended`) — تُعرض المدّة المقيسة.
 */
export type RoomPhase = 'idle' | 'loading' | 'joining' | 'joined' | 'left' | 'ended' | 'error';
/** `full`: صفحة الغرفة معروضة · `dock`: صفحةٌ أخرى والجلسة مصغّرة. */
export type RoomView = 'full' | 'dock';

export interface RoomResume { key: string; title: string; href: string }

export interface RoomSnapshot {
  room: Room | null;
  /** عنوان صفحة الغرفة — وجهة «العودة إلى الجلسة». */
  href: string | null;
  phase: RoomPhase;
  message: string;
  userName: string;
  /** لحظة الانضمام (للمؤقّت التصاعديّ) — لا مدّة رسميّة؛ تلك `measuredDuration`. */
  joinedAt: number | null;
  participants: number | null;
  view: RoomView;
  /** جلسةٌ قُطعت بإعادة التحميل — تُعرض دعوةً للعودة. */
  resume: RoomResume | null;
}

const IDLE: RoomSnapshot = {
  room: null,
  href: null,
  phase: 'idle',
  message: '',
  userName: '',
  joinedAt: null,
  participants: null,
  view: 'full',
  resume: null,
};

/** الأطوار التي تعني مكالمةً قائمة أو في الطريق. */
export const isActivePhase = (phase: RoomPhase): boolean =>
  phase === 'loading' || phase === 'joining' || phase === 'joined';

const RESUME_KEY = 'room.active';

function readResume(): RoomResume | null {
  try {
    const raw = window.sessionStorage.getItem(RESUME_KEY);

    return raw ? (JSON.parse(raw) as RoomResume) : null;
  } catch {
    return null;
  }
}

let snap: RoomSnapshot = typeof window === 'undefined' ? IDLE : { ...IDLE, resume: readResume() };
const listeners = new Set<() => void>();

function set(patch: Partial<RoomSnapshot>): void {
  snap = { ...snap, ...patch };
  syncHost();
  syncUnload();
  syncResume();
  listeners.forEach((l) => l());
}

function subscribe(l: () => void): () => void {
  listeners.add(l);

  return () => {
    listeners.delete(l);
  };
}

/** قراءة الجلسة الحيّة في أيّ مكوّن — الصفحة والشريط العائم يقرآن المصدر نفسه. */
export function useRoomSession(): RoomSnapshot {
  return useSyncExternalStore(subscribe, () => snap, () => IDLE);
}

// ——————————————————————— حاوية Zoom الثابتة ———————————————————————

let host: HTMLDivElement | null = null;
let zoomRoot: HTMLDivElement | null = null;
/** عميل Zoom الحيّ ومكتبته — واحدٌ للتبويب. */
let client: ZoomClient | null = null;
let embedded: ZoomEmbedded | null = null;

function ensureHost(): HTMLDivElement {
  if (host && zoomRoot) {
    return zoomRoot;
  }

  host = document.createElement('div');
  host.className = 'mroom-zoom';
  host.dataset.view = 'hidden';
  zoomRoot = document.createElement('div');
  zoomRoot.className = 'mroom-zoom-root';
  host.appendChild(zoomRoot);
  document.body.appendChild(host);

  // مقاس الفيديو يتبع الحاوية: ملءُ التبويب، ودورانُ الهاتف، والتصغير إلى الشريط العائم
  if (typeof ResizeObserver !== 'undefined') {
    new ResizeObserver(() => resizeVideo()).observe(host);
  }
  // عودة التبويب من الخلفيّة: قد يعيد Zoom رسم الفيديو بمقاسه الافتراضيّ وهو مخفيّ — يُعاد القياس
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      resizeSoon();
    }
  });

  syncHost();

  return zoomRoot;
}

function syncHost(): void {
  if (!host) {
    return;
  }

  const next = isActivePhase(snap.phase) ? snap.view : 'hidden';
  if (host.dataset.view !== next) {
    host.dataset.view = next;
    // التبديل بين `full` و`dock` يغيّر الحاوية — يُقاس بعد أن يطبّق المتصفّح التخطيط الجديد
    resizeSoon();
  }
}

/** إعادة القياس بعد إطارين: الأوّل يطبّق `data-view`، والثاني يضمن أنّ المقاس المقروء نهائيّ. */
function resizeSoon(): void {
  requestAnimationFrame(() => requestAnimationFrame(() => resizeVideo()));
}

function videoSize(): VideoSize | null {
  const r = host?.getBoundingClientRect();

  if (!r || r.width < 1 || r.height < 1) {
    return null;
  }

  return { width: Math.floor(r.width), height: Math.max(90, Math.floor(r.height - ZOOM_TOOLBAR_PX)) };
}

function resizeVideo(): void {
  const size = videoSize();

  if (!client || !size || snap.phase !== 'joined') {
    return;
  }

  try {
    // و`ribbon` بالمقاس نفسه: إن انتقل Zoom إليه (مشاركة شاشة أو اختيار المستخدم) بقي مالئاً للغرفة لا عموداً في زاويتها
    void client.updateVideoOptions?.({ viewSizes: { default: size, ribbon: size } });
  } catch {
    /* واجهة الخيارات قد تختلف بين إصدارات SDK — الحجم الافتراضيّ يبقى */
  }
}

// ——————————————————————— التحذير عند مغادرة التبويب ———————————————————————

let unloadOn = false;

function onBeforeUnload(e: BeforeUnloadEvent): string {
  e.preventDefault();
  // المتصفّحات الحديثة تعرض نصّها الخاصّ؛ القيمة غير الفارغة هي ما يُطلق التحذير في الأقدم
  e.returnValue = ROOM_TEXT.unloadWarning;

  return ROOM_TEXT.unloadWarning;
}

function syncUnload(): void {
  const want = snap.phase === 'joining' || snap.phase === 'joined';

  if (want === unloadOn || typeof window === 'undefined') {
    return;
  }

  unloadOn = want;

  if (want) {
    window.addEventListener('beforeunload', onBeforeUnload);
  } else {
    window.removeEventListener('beforeunload', onBeforeUnload);
  }
}

function syncResume(): void {
  try {
    if (snap.phase === 'joined' && snap.room && snap.href) {
      const r: RoomResume = { key: roomKey(snap.room), title: snap.room.title, href: snap.href };
      window.sessionStorage.setItem(RESUME_KEY, JSON.stringify(r));
    } else if (!isActivePhase(snap.phase) && !snap.resume) {
      window.sessionStorage.removeItem(RESUME_KEY);
    }
  } catch {
    /* التخزين محجوب (نافذة خاصّة) — تفقد الدعوة بعد إعادة التحميل ولا يتعطّل شيء */
  }
}

// ——————————————————————— دورة حياة المكالمة ———————————————————————

/** رقم المحاولة — كلّ تفكيكٍ يزيده فتسقط أيّ محاولة انضمامٍ أقدم في منتصفها. */
let attempt = 0;
/** تفكيكٌ جارٍ — الانضمام التالي ينتظره كي لا يهدم `destroyClient` عميلاً أُنشئ بعده. */
let teardownDone: Promise<void> = Promise.resolve();

/** يُخرجك من المكالمة ويفكّك واجهة Zoom — مغادرةٌ لا إنهاء (لا `endMeeting` هنا أبداً). */
function teardownSdk(): void {
  attempt += 1;
  const c = client;
  const e = embedded;
  client = null;

  if (!c) {
    return;
  }

  teardownDone = (async () => {
    try {
      await c.leaveMeeting();
    } catch {
      /* لم يكن منضمّاً بعد */
    }

    try {
      e?.destroyClient();
    } catch {
      /* تنظيف صامت */
    }

    // init() يُظهر واجهته حتى قبل نجاح join() — لا تبقى بقاياها خلف الشاشة التالية
    zoomRoot?.replaceChildren();
  })();
}

/**
 * **سببُ الرفض من الخادم لا رسالةٌ عامّة.** الخادم يعلّل رفضَ التوقيع (`{message}` بـ403/422):
 * الجلسة لم تُعتمد، أو انتهت، أو لست طرفاً فيها. وكانت الغرفة تعرض «تعذّر تجهيز غرفة الجلسة»
 * لكلّ ذلك، فلا يعرف المستخدم أينتظر أم يراجع المكتب.
 */
function refusalMessage(e: unknown): string {
  if (!axios.isAxiosError(e)) {
    return ROOM_TEXT.prepareFailed;
  }

  if (e.response?.status === 503) {
    return ROOM_TEXT.notConfigured;
  }

  const message = (e.response?.data as { message?: unknown } | undefined)?.message;

  return typeof message === 'string' && message.trim() !== '' ? message : ROOM_TEXT.prepareFailed;
}

function fail(message: string): void {
  teardownSdk();
  set({ phase: 'error', message, joinedAt: null });
}

/** إغلاق الاتصال من داخل Zoom (انقطاع · إخراج · إنهاء المضيف قبل وصول البثّ). */
function onZoomClosed(): void {
  if (snap.phase !== 'joined' && snap.phase !== 'joining') {
    return;
  }

  teardownSdk();
  // الإنهاء يقرّره الخادم: إن كان قد أعلنه فهو «انتهت»، وإلا «غادرتَ» حتى يصل البثّ فيصحّحه
  set({ phase: snap.room?.ended ? 'ended' : 'left', joinedAt: null });
}

async function join(): Promise<void> {
  const room = snap.room;

  if (!room) {
    return;
  }

  await teardownDone;
  const mine = ++attempt;
  const stale = () => mine !== attempt;
  set({ phase: 'loading', message: '' });

  // ١) توقيع الانضمام (التفويض والدور على الخادم)
  let data: SignaturePayload;

  try {
    data = (await axios.post<SignaturePayload>('/zoom/sdk-signature', { ref: room.ref, kind: room.kind })).data;
  } catch (e) {
    if (!stale()) {
      fail(refusalMessage(e));
    }

    return;
  }

  // ٢) مكتبة Zoom من CDN عند الطلب (تُعزل عن بقيّة الصفحات وعن React المضيف)
  let sdk: ZoomEmbedded;

  try {
    sdk = await loadZoomSdk();
  } catch {
    if (!stale()) {
      fail(ROOM_TEXT.sdkFailed);
    }

    return;
  }

  if (stale()) {
    return;
  }

  // ٣) التهيئة والانضمام — الحاوية تظهر قبل init ليقيس Zoom مساحةً حقيقيّة
  const rootEl = ensureHost();
  set({ phase: 'joining', userName: data.userName || '' });

  try {
    embedded = sdk;
    const c = sdk.createClient();
    client = c;
    const size = videoSize() ?? undefined;
    await c.init({
      zoomAppRoot: rootEl,
      language: ZOOM_LANGUAGE,
      patchJsMedia: true,
      customize: {
        video: {
          isResizable: false,
          // الفيديو يملأ حاويته ولا يُسحب خارجها — الموضع تحدّده الغرفة لا المستخدم، ويثبّته CSS في أصل
          // الحاوية (`.mroom-zoom-root .react-draggable`). كان هنا `anchorElement`/`placement` ولا يقبلهما
          // Zoom للفيديو (تعريفات 6.2.0) فيُتجاهلان.
          popper: { disableDraggable: true },
          // **عرض «المتحدّث» مالئاً للشاشة الزرقاء** (ملاحظة المالك 2026-10-02): بلا نوعٍ افتراضيّ ظهر الاجتماع
          // عموداً ضيّقاً (ribbon) في الزاوية. والمقاسان معاً كي لا يصغر إن تبدّل العرض.
          defaultViewType: 'speaker',
          viewSizes: size ? { default: size, ribbon: size } : undefined,
        },
      },
    });

    try {
      c.on?.('connection-change', (p) => {
        if (mine === attempt && (p?.state === 'Closed' || p?.state === 'Fail')) {
          onZoomClosed();
        }
      });
    } catch {
      /* واجهة الأحداث قد تختلف بين إصدارات SDK */
    }

    if (stale()) {
      return;
    }

    await c.join({
      signature: data.signature,
      meetingNumber: data.meetingNumber,
      password: data.password,
      userName: data.userName,
      userEmail: data.userEmail,
      zak: data.zak || undefined,
      customerKey: data.customerKey || undefined,
    });

    if (stale()) {
      return;
    }

    set({ phase: 'joined', joinedAt: Date.now() });
    resizeVideo();
  } catch (e) {
    if (stale()) {
      return;
    }

    const err = e as { reason?: string; errorMessage?: string };
    const reason = String(err?.reason ?? err?.errorMessage ?? '').toLowerCase();
    fail(reason.includes('not start') ? ROOM_TEXT.notStarted : ROOM_TEXT.joinFailed);
  }
}

// ——————————————————————— البثّ اللحظيّ ———————————————————————

type Listener = (p: RoomStatePayload) => void;
let subscriptions: Array<{ channel: string; listener: Listener }> = [];

/**
 * حالة الغرفة من الخادم لحظةَ تغيّرها: انعقدت · انتهت · الحاضرون · المدّة المقيسة. و`recording`
 * **من قناة الطاقم وحدها** — قناة العميل لا تحمله، ولو حملته لم يُقرأ.
 */
function applyState(p: RoomStatePayload, fromStaffChannel: boolean): void {
  const room = snap.room;

  if (!room) {
    return;
  }

  const next: Room = {
    ...room,
    live: p.live,
    ended: p.ended,
    statusLabel: p.statusLabel,
    measuredDuration: p.measuredDuration ?? room.measuredDuration,
    recording: fromStaffChannel && isStaffRoom(room) && typeof p.recording === 'boolean' ? p.recording : room.recording,
    outsiders: fromStaffChannel && isStaffRoom(room) && typeof p.outsiders === 'number' ? p.outsiders : room.outsiders,
    // شرطُ الإنهاء عند الخادم «الجلسة منعقدة» — فيتبع البثَّ نفسه بلا انتظار إعادة تحميل
    endAction: room.endAction ? { ...room.endAction, enabled: p.live && !p.ended } : null,
  };
  const patch: Partial<RoomSnapshot> = { room: next, participants: p.participants ?? null };

  if (p.ended && snap.phase !== 'ended') {
    // انتهت للجميع: نغادر الواجهة بهدوء ونعرض شاشة النهاية بدل تجميد صورةٍ ميّتة
    teardownSdk();
    patch.phase = 'ended';
    patch.joinedAt = null;
  }

  set(patch);
}

function attachChannels(room: Room): void {
  detachChannels();
  const targets: Array<[string, boolean]> = [[room.channel, false]];

  if (room.staffChannel) {
    targets.push([room.staffChannel, true]);
  }

  for (const [channel, staff] of targets) {
    if (!channel) {
      continue;
    }

    const listener: Listener = (p) => applyState(p, staff);

    try {
      echo.private(channel).listen('.room.state', listener);
      subscriptions.push({ channel, listener });
    } catch {
      /* البثّ غير متاح (التطوير بلا Reverb) — الغرفة تعمل وتتحدّث عند إعادة التحميل */
    }
  }
}

function detachChannels(): void {
  for (const { channel, listener } of subscriptions) {
    try {
      // إيقاف مستمعنا وحده لا مغادرة القناة: صفحاتٌ أخرى قد تستمع على القناة نفسها
      echo.private(channel).stopListening('.room.state', listener);
    } catch {
      /* تنظيف صامت */
    }
  }

  subscriptions = [];
}

// ——————————————————————— الأفعال ———————————————————————

/**
 * صفحة الغرفة تُفتح: تُعرض الجلسة ملءَ التبويب. إن كانت هي نفسها قائمةً (عودةٌ من الشريط) لا يُعاد
 * الانضمام. وإن كانت جلسةٌ أخرى قائمة لا تُهدم بصمت — تُردّ `busy` والصفحة تعرض الخيار.
 */
export function openRoom(room: Room, href: string): 'ok' | 'busy' {
  const cur = snap.room;
  const active = isActivePhase(snap.phase);

  if (cur && active && roomKey(cur) !== roomKey(room)) {
    return 'busy';
  }

  if (cur && active) {
    set({ room, href, view: 'full', resume: null });

    return 'ok';
  }

  teardownSdk();
  detachChannels();
  set({ ...IDLE, room, href, view: 'full', phase: room.ended ? 'ended' : 'idle' });
  attachChannels(room);

  if (!room.ended) {
    void join();
  }

  return 'ok';
}

/** خصائص الصفحة تجدّدت (إعادة تحميلٍ جزئيّة) — تُعتمد لنفس الجلسة، والانتهاء يُنهي الواجهة. */
export function syncRoom(room: Room): void {
  if (!snap.room || roomKey(snap.room) !== roomKey(room)) {
    return;
  }

  if (room.ended && snap.phase !== 'ended') {
    teardownSdk();
    set({ room, phase: 'ended', joinedAt: null });

    return;
  }

  set({ room });
}

/** صفحة الغرفة فُكّكت (تنقّل): المكالمة القائمة تُصغَّر، وما سواها يُطوى. */
export function closeView(room: Room): void {
  if (!snap.room || roomKey(snap.room) !== roomKey(room)) {
    return;
  }

  if (isActivePhase(snap.phase)) {
    set({ view: 'dock' });
  } else {
    resetRoom();
  }
}

/** «مغادرة»: تخرج أنت وحدك. في الغرفة تبقى الشاشة تعرض «الانضمام من جديد»؛ وفي الشريط يُطوى. */
export function leaveRoom(): void {
  if (snap.view === 'dock') {
    resetRoom();

    return;
  }

  teardownSdk();
  set({ phase: 'left', joinedAt: null });
}

/** إعادة المحاولة / الانضمام من جديد لنفس الجلسة. */
export function rejoinRoom(): void {
  if (snap.room && !snap.room.ended) {
    void join();
  }
}

/** أنهى الطاقم الجلسة بنجاح عبر `endAction` — الواجهة تُغادر فوراً ولا تنتظر البثّ. */
export function markRoomEnded(): void {
  teardownSdk();

  if (snap.room) {
    set({
      room: { ...snap.room, live: false, ended: true, endAction: snap.room.endAction ? { ...snap.room.endAction, enabled: false } : null },
      phase: 'ended',
      joinedAt: null,
    });
  }
}

/** طيُّ الجلسة كلّها: المكالمة والاشتراك والشريط ودعوة العودة. */
export function resetRoom(): void {
  teardownSdk();
  detachChannels();
  set({ ...IDLE });
}

/** إخفاء دعوة العودة بعد إعادة التحميل. */
export function dismissResume(): void {
  set({ resume: null });
}
