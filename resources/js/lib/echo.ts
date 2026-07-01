import axios from 'axios';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// عميل البث اللحظي — Laravel Reverb (WebSocket)
// يُستخدم لمزامنة محادثات التذاكر بين العميل والموظف دون إعادة تحميل.

declare global {
  interface Window { Pusher: typeof Pusher; Echo: Echo<'reverb'>; }
}

window.Pusher = Pusher;

const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';

// مصادقة طلبات axios (الإرسال بلا إعادة تحميل) عبر CSRF
axios.defaults.headers.common['X-CSRF-TOKEN'] = csrf;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

export const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_APP_KEY,
  wsHost: import.meta.env.VITE_REVERB_HOST,
  wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
  wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
  forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
  enabledTransports: ['ws', 'wss'],
  // مصادقة القنوات الخاصة عبر CSRF
  auth: { headers: { 'X-CSRF-TOKEN': csrf } },
});

window.Echo = echo;
