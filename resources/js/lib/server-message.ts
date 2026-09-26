/**
 * **سببُ رفض الخادم من خطأ axios/fetch — أو النصّ الاحتياطيّ.**
 *
 * الخادم يردّ كلّ رفضٍ لنداء JSON بـ`{message}` عربيّة (`App\Support\ErrorResponse`)، وأخطاء
 * الحقول بـ`{errors: {field: [..]}}`. كانت نداءاتٌ تعرض نصّاً ثابتاً («تعذّر إرسال الرد») وتُهمل
 * السبب الذي جاء معها — فيقرأ المستخدم «تعذّر» ولا يعرف لماذا ولا ما يصحّحه.
 *
 * والنصّ غير العربيّ يُهمَل (رسالة شبكةٍ من المتصفّح «Network Error») — يُعرض الاحتياطيّ.
 */
export function serverMessage(error: unknown, fallback: string): string {
  const data = (error as { response?: { data?: unknown } } | null)?.response?.data as
    | { message?: unknown; errors?: Record<string, unknown> }
    | undefined;

  const firstFieldError = data?.errors ? Object.values(data.errors).flat()[0] : undefined;

  for (const candidate of [firstFieldError, data?.message]) {
    if (typeof candidate === 'string' && /[؀-ۿ]/.test(candidate)) {
      return candidate;
    }
  }

  return fallback;
}
