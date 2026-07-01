// ============================================================
// بيانات معالج فتح التذكرة — مستخرجة حرفياً من index (82).html
// SVC / SVC_GROUPS / RAIL / STAGE_RAIL / الأسعار
// ============================================================

export interface Svc { label: string; dept: string; docs: string[]; }

export const SVC: Record<string, Svc> = {
  contract: { label: 'نزاع تجاري', dept: 'القسم التجاري', docs: ['الهوية', 'العقد', 'المراسلات'] },
  labor: { label: 'قضية عمالية', dept: 'قسم القضايا العمالية', docs: ['الهوية', 'عقد العمل', 'مسير الرواتب', 'المراسلات'] },
  realestate: { label: 'نزاع عقاري', dept: 'القسم العقاري', docs: ['الهوية', 'الصك', 'عقد البيع/الإيجار'] },
  admin: { label: 'قضية إدارية', dept: 'القسم الإداري', docs: ['الهوية', 'القرار الإداري', 'التظلم السابق'] },
  criminal: { label: 'قضية جزائية', dept: 'القسم الجزائي', docs: ['الهوية', 'محضر الواقعة', 'صك الوكالة'] },
  banking: { label: 'نزاع مالي ومصرفي', dept: 'قسم القضايا المالية والمصرفية', docs: ['الهوية', 'عقد التمويل', 'كشف الحساب'] },
  insurance: { label: 'نزاع تأميني', dept: 'قسم التأمين', docs: ['الهوية', 'وثيقة التأمين', 'مطالبة التعويض'] },
  construction: { label: 'مقاولات وتحكيم هندسي', dept: 'قسم المقاولات والتحكيم الهندسي', docs: ['الهوية', 'عقد المقاولة', 'المخططات', 'المستخلصات'] },
  ip: { label: 'نزاع ملكية فكرية', dept: 'قسم الملكية الفكرية', docs: ['الهوية', 'شهادة التسجيل', 'أدلة التعدي'] },
  enforcement: { label: 'طلب تنفيذ', dept: 'قسم التنفيذ', docs: ['الهوية', 'السند التنفيذي', 'بيانات المنفّذ ضده'] },
  cheques: { label: 'أوراق تجارية (شيكات)', dept: 'قسم الأوراق التجارية', docs: ['الهوية', 'أصل الشيك', 'إفادة الإرجاع'] },
  medical: { label: 'أخطاء طبية', dept: 'قسم القضايا الطبية', docs: ['الهوية', 'التقرير الطبي', 'الملف العلاجي'] },
  traffic: { label: 'حوادث ومرور', dept: 'قسم المرور والحوادث', docs: ['الهوية', 'تقرير الحادث', 'الرخصة والاستمارة'] },
  divorce: { label: 'طلاق وفسخ', dept: 'قسم الأحوال الشخصية', docs: ['الهوية', 'عقد النكاح', 'سجل الأسرة'] },
  custody: { label: 'حضانة ونفقة', dept: 'قسم الأحوال الشخصية', docs: ['الهوية', 'سجل الأسرة', 'إثبات الدخل'] },
  lineage: { label: 'إثبات ونسب', dept: 'قسم الأحوال الشخصية', docs: ['الهوية', 'المستندات المؤيدة', 'بيانات الشهود'] },
  inheritance: { label: 'مواريث وقسمة تركة', dept: 'قسم المواريث والتركات', docs: ['الهوية', 'صك حصر الورثة', 'حصر التركة'] },
  endowment: { label: 'وصايا وأوقاف', dept: 'قسم الأوقاف والوصايا', docs: ['الهوية', 'صك الوقف/الوصية', 'بيان الأصول'] },
  formation: { label: 'تأسيس شركة', dept: 'قسم الشركات والحوكمة', docs: ['الهوية', 'عقد التأسيس', 'بيانات الشركاء'] },
  governance: { label: 'حوكمة وامتثال', dept: 'قسم الشركات والحوكمة', docs: ['السجل التجاري', 'النظام الأساسي', 'اللوائح الداخلية'] },
  drafting: { label: 'صياغة ومراجعة العقود', dept: 'قسم العقود', docs: ['الهوية', 'مسودة العقد', 'بيانات الأطراف'] },
  ma: { label: 'اندماج واستحواذ', dept: 'قسم الاندماج والاستحواذ', docs: ['السجل التجاري', 'القوائم المالية', 'هيكل الصفقة'] },
  insolvency: { label: 'إفلاس وتصفية', dept: 'قسم الإفلاس والتصفية', docs: ['السجل التجاري', 'القوائم المالية', 'بيان الديون'] },
  investment: { label: 'استثمار أجنبي وتراخيص', dept: 'قسم الاستثمار والتراخيص', docs: ['الهوية/الجواز', 'خطة المشروع', 'التراخيص الحالية'] },
  tax: { label: 'ضرائب وزكاة', dept: 'قسم الضرائب والزكاة', docs: ['السجل التجاري', 'الإقرارات', 'مراسلات الهيئة'] },
  data: { label: 'تقنية وحماية بيانات', dept: 'قسم التقنية وحماية البيانات', docs: ['السجل التجاري', 'سياسات المعالجة', 'نموذج المعالجة'] },
  arbitration: { label: 'تحكيم تجاري', dept: 'قسم التحكيم وتسوية المنازعات', docs: ['الهوية', 'شرط/اتفاق التحكيم', 'العقد محل النزاع'] },
  mediation: { label: 'وساطة وتسوية ودية', dept: 'قسم التحكيم وتسوية المنازعات', docs: ['الهوية', 'العقد', 'المراسلات'] },
  general: { label: 'استشارة قانونية عامة', dept: 'قسم الاستشارات العامة', docs: ['الهوية', 'المستندات ذات العلاقة'] },
  consumer: { label: 'حماية المستهلك', dept: 'قسم حماية المستهلك', docs: ['الهوية', 'فاتورة الشراء', 'المراسلات'] },
  memos: { label: 'صياغة لوائح ومذكرات', dept: 'قسم الاستشارات العامة', docs: ['الهوية', 'أوراق الدعوى', 'المستندات الداعمة'] },
};

export const SVC_GROUPS: [string, string[]][] = [
  ['القضايا والمنازعات', ['contract', 'labor', 'realestate', 'admin', 'criminal', 'banking', 'insurance', 'construction', 'ip', 'enforcement', 'cheques', 'medical', 'traffic']],
  ['الأحوال الشخصية', ['divorce', 'custody', 'lineage', 'inheritance', 'endowment']],
  ['الشركات والأعمال', ['formation', 'governance', 'drafting', 'ma', 'insolvency', 'investment', 'tax', 'data']],
  ['التحكيم والتسوية', ['arbitration', 'mediation']],
  ['استشارات عامة', ['general', 'consumer', 'memos']],
];

// مسار المعالجة (RAIL) ومرحلة كل حالة
export const RAIL: [string, string][] = [
  ['استقبال الطلب', 'إنشاء التذكرة'],
  ['التحليل', 'تصنيف واستخراج'],
  ['الإحالة', 'القسم المختص'],
  ['الرأي القانوني', 'مراجعة المستشار'],
  ['حجز الاستشارة', 'النوع والدفع'],
  ['الموعد', 'تأكيد وبطاقة'],
  ['الاستشارة', 'انعقاد الجلسة'],
  ['النتيجة', 'الملخص'],
];

export const STAGE_RAIL: Record<string, number> = {
  create: 0, welcome: 1, analysis: 1, missing: 1, referred: 2, study: 3,
  legalreply: 3, consult: 4, invoice: 4, paid: 4, slot: 5, confirm: 5,
  session: 6, lawyerrev: 7, adminrev: 7, result: 7,
};

export const CONSULT_PRICES: Record<string, number> = { 'حضورية': 600, 'مرئية': 450, 'هاتفية': 350 };
export const VAT_RATE = 0.15;

export const TF_FILES = ['عقد_التوريد.pdf', 'الهوية_الوطنية.jpg', 'مراسلات_البريد.pdf', 'إشعار_إخلال.pdf'];

// مولّد رمز QR — يطابق qrSVG في الأصل (حتمي عبر seed)
export function qrRects(seed: string): { x: number; y: number; c: number }[] {
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0;
  const n = 21, cell = 4;
  const rnd = () => { h = (h * 1103515245 + 12345) & 0x7fffffff; return h / 0x7fffffff; };
  const fin = (x: number, y: number) => {
    const f = (a: number, b: number) => x >= a && x < a + 7 && y >= b && y < b + 7;
    return f(0, 0) || f(n - 7, 0) || f(0, n - 7);
  };
  const out: { x: number; y: number; c: number }[] = [];
  for (let y = 0; y < n; y++) for (let x = 0; x < n; x++) {
    let on: boolean;
    if (fin(x, y)) {
      const ix = x < 7 ? x : x - (n - 7), iy = y < 7 ? y : y - (n - 7);
      on = ix === 0 || ix === 6 || iy === 0 || iy === 6 || (ix >= 2 && ix <= 4 && iy >= 2 && iy <= 4);
    } else on = rnd() > 0.55;
    if (on) out.push({ x: x * cell, y: y * cell, c: cell });
  }
  return out;
}
export const QR_SIZE = 21 * 4;
