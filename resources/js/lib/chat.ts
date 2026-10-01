import { ATTACHMENT_MB } from '@/lib/upload-limits';

// ============================================================
// منطق المحادثات — مستخرج من index (82).html
// convGet / ctRenderMsg / dvSend / flowHTML ... إلخ
// ============================================================

export interface Message {
  id?: number; // معرّف الرسالة من قاعدة البيانات (للمزامنة اللحظية)
  who: 'client' | 'me' | 'ai' | 'staff' | 'lawyer' | 'admin' | 'system' | 'note';
  name: string;
  role: string;
  text: string; // قد يحتوي HTML بسيط
  time: string;
  date?: string; // تاريخ الرسالة الحقيقي من الخادم (created_at)
  // عنوان IP لمُرسِلها — يرسله الخادم للطاقم وحده (`RecordsSenderIp::senderIpField`)؛ غيابه عند العميل حجبٌ خادميّ لا إخفاءٌ في الواجهة
  ip?: string;
}

export const CLIENT_NAME = 'عبدالله العتيبي';

// امتدادات المستندات المسموح رفعها من العميل — يطابق TicketController::ALLOWED_DOC_MIMES بالخادم
export const ALLOWED_DOC_ACCEPT = '.pdf,.jpg,.jpeg,.png,.doc,.docx';
export const ALLOWED_DOC_HINT = `الصيغ المسموحة: PDF، JPG، PNG، DOC، DOCX — حتى ${ATTACHMENT_MB}MB لكل ملف`;

// يطابق nowClock()
export function nowClock(): string {
  try {
    return new Date().toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' });
  } catch {
    return new Date().toLocaleTimeString();
  }
}

// يطابق todayDate()
export function todayDate(): string {
  try {
    return new Intl.DateTimeFormat('ar', {
      weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', calendar: 'gregory',
    }).format(new Date());
  } catch {
    return new Date().toLocaleDateString();
  }
}

export function cleanTime(t?: string): string {
  t = (t || '').toString();
  if (t.indexOf('·') >= 0) return t.split('·').pop()!.trim();
  return t;
}

// مراحل دورة حياة التذكرة — تسمياتها تطابق `TicketJourney::STAGES`، والمرحلة الحاليّة `step` من الخادم (`TicketJourney::indexOf`)
export const TKT_LIFE = ['استلام الطلب', 'التحليل', 'الإحالة للقسم', 'الرأي القانوني', 'حجز الاستشارة', 'الجلسة', 'النتيجة'];

// ملف مرفق تجريبي (يطابق pool في ctAttach)
export const ATTACH_POOL = ['مستند_إضافي.pdf', 'صورة_العقد.jpg', 'كشف_حساب.pdf', 'إفادة.pdf'];

export function attachMessage(file: string): Message {
  return {
    who: 'client', name: CLIENT_NAME, role: 'العميل',
    text: `<p>تم إرفاق مستند:</p><div class="doc-list"><span class="doc-chip">📎 ${file}</span></div>`,
    time: nowClock(),
  };
}

// رد الفريق القانوني التلقائي بعد الإرسال
export function ackMessage(text = 'تم استلام رسالتك، وسيوافيكم القسم المختص بالرد في أقرب وقت.'): Message {
  return { who: 'ai', name: 'الفريق القانوني', role: 'متابعة', text, time: nowClock() };
}
