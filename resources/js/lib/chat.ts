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
}

export const CLIENT_NAME = 'عبدالله العتيبي';
export const OFFICE_IP = '212.71.46.10';
export const CLIENT_IP = '178.45.12.90'; // عنوان عميل ثابت (بدل simIP العشوائي)

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

export function msgIP(who: Message['who']): string {
  return who === 'client' || who === 'me' ? CLIENT_IP : OFFICE_IP;
}

// يطابق convGet — البذرة العامة للمحادثة
export function seedTicket(type: string, last: string): Message[] {
  return [
    { who: 'client', name: CLIENT_NAME, role: 'العميل', text: `${type} — يرجى دراسة الموضوع وإفادتي بالرأي القانوني.`, time: '10:01 ص' },
    { who: 'ai', name: 'الفريق القانوني', role: 'استقبال', text: 'تم استلام طلبكم بنجاح وإحالته إلى القسم القانوني المختص. يمكنكم متابعة المستجدات والكتابة هنا في أي وقت.', time: '10:02 ص' },
    { who: 'ai', name: 'الفريق القانوني', role: 'متابعة', text: last || 'الطلب قيد المعالجة.', time: '10:20 ص' },
  ];
}

export function seedCase(type: string, status: string, update: string, next?: string): Message[] {
  return [
    { who: 'client', name: CLIENT_NAME, role: 'العميل', text: `بخصوص قضيتي (${type}) أرغب بمتابعة المستجدات.`, time: '09:00 ص' },
    { who: 'ai', name: 'الفريق القانوني', role: 'متابعة القضية', text: `مرحباً بكم. حالة القضية: ${status}. آخر تحديث: ${update}${next ? ` — الجلسة القادمة: ${next}` : ''}.`, time: '09:05 ص' },
  ];
}

export function seedExec(subject: string, status: string, last: string): Message[] {
  return [
    { who: 'client', name: CLIENT_NAME, role: 'العميل', text: `بخصوص طلب التنفيذ (${subject}) أرغب بمتابعة الإجراء.`, time: '09:00 ص' },
    { who: 'ai', name: 'الفريق القانوني', role: 'التنفيذ', text: `حالة الطلب: ${status}. آخر إجراء: ${last}.`, time: '09:05 ص' },
  ];
}

// مراحل دورة حياة التذكرة — يطابق TKT_LIFE / tktStage
export const TKT_LIFE = ['استلام الطلب', 'التحليل', 'الإحالة للقسم', 'الرأي القانوني', 'حجز الاستشارة', 'الجلسة', 'النتيجة'];

export function tktStage(status: string): number {
  const m: Record<string, number> = {
    'جديدة': 0,
    'قيد التحليل': 1, 'بانتظار مستندات': 1,
    'محالة للقسم القانوني': 2, 'بانتظار اعتماد المستشار': 2,
    'الرأي القانوني': 3,
    'بانتظار حجز الاستشارة': 4, 'بانتظار الدفع': 4,
    'موعد مؤكد': 5, 'قيد التنفيذ': 5,
    'بانتظار اعتماد النتيجة': 5, 'بانتظار اعتماد الإدارة': 6,
    'مكتملة': 6, 'مغلقة': 6,
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
