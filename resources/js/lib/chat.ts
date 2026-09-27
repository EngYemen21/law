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
export const ALLOWED_DOC_HINT = 'الصيغ المسموحة: PDF، JPG، PNG، DOC، DOCX — حتى 10MB لكل ملف';

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

// مراحل دورة حياة التذكرة — يطابق TKT_LIFE / tktStage
export const TKT_LIFE = ['استلام الطلب', 'التحليل', 'الإحالة للقسم', 'الرأي القانوني', 'حجز الاستشارة', 'الجلسة', 'النتيجة'];

export function tktStage(status: string): number {
  const m: Record<string, number> = {
    'جديدة': 0,
    'قيد التحليل': 1, 'بانتظار مستندات': 1,
    'محالة للقسم القانوني': 2, 'بانتظار اعتماد المستشار': 2, 'بانتظار اعتماد الإدارة للملخّص': 2,
    'الرأي القانوني': 3,
    'بانتظار حجز الاستشارة': 4, 'بانتظار تحديد الموعد': 4,
    'موعد مؤكد': 5, 'بانتظار ملخّص الجلسة': 5,
    // تسميات العميل (`TicketStatus::clientLabel`) — شاشة العميل تقرأها بدل الحالة الداخليّة، فلا يرتدّ مسارها إلى الصفر
    'قيد إعداد الرأي القانوني': 2,
    'جارٍ إعداد ملخّص الجلسة': 5,
    'قيد دراسة وتوجيه الإدارة العليا': 6,
    'اكتملت الدراسة — بانتظار القرار النهائي': 6, 'تم تحويل الطلب إلى قضية رسمية': 6, 'تم تحويل الطلب إلى ملف تنفيذ قضائي': 6, 'طلب مكتمل ومغلق': 6,
    'بانتظار قرار المآل': 6, 'بانتظار اعتماد الإدارة للمسار': 6, 'مكتملة': 6, 'محولة إلى قضية': 6, 'محولة إلى تنفيذ': 6, 'مغلقة': 6,
  };
  // الارتداد 0 مطابقةً لـTicketJourney::indexOf على الخادم — كان 1 فيَعِد الزرّ بمرحلة غير التي تُنفَّذ
  return status in m ? m[status] : 0;
}

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
