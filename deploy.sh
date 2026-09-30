#!/bin/bash
# ════════════════════════════════════════════════════════════════════════════
# النشر الآمن على الإنتاج (فصل البيئات، المرحلة ٢ — 2026-09-30)
#
#   ./deploy.sh            ينشر آخر main (origin/main)
#   ./deploy.sh v1.4.0     ينشر وسماً (tag) أو فرعاً أو commit بعينه
#
# الضمانات:
#   • يتوقّف عند أوّل خطأ (set -Eeuo pipefail) ويبقى الموقع **في الصيانة** — لا يُفتح على كودٍ مختلط.
#   • النسخة الاحتياطيّة (القاعدة + الملفّات المرفوعة) **بعد** إيقاف الموقع والعمّال، مضغوطةً مفحوصةً مدوّرة.
#   • يُسجَّل الإصدار السابق في $DEPLOY_STATE_DIR فيعيده ./rollback.sh.
#   • php artisan env:check وفحص /up عبر رابطٍ سرّيّ **قبل** الإتاحة للعملاء.
#   • يوقف عمّال المشروع بأسمائهم (SUPERVISOR_PROGRAMS) لا كلّ برامج الخادم.
# المتغيّرات القابلة للضبط: انظر deploy/lib.sh.
# ════════════════════════════════════════════════════════════════════════════
set -Eeuo pipefail
cd "$(dirname "$0")"
# shellcheck source=deploy/lib.sh
source deploy/lib.sh

# الجسم كلّه داخل دالّةٍ تُنادى في آخر سطر: bash يقرأ السكربت قطعةً قطعة أثناء التنفيذ، و`git checkout` أدناه
# يبدّل هذا الملفّ نفسه على القرص — فبلا الدالّة قد يُنفَّذ خليطٌ من نسختين. هكذا يُقرأ كاملاً قبل أيّ تنفيذ.
main() {
TARGET="${1:-origin/main}"
STEP="التحضير"
SITE_DOWN=0

# عند أيّ خروجٍ غير ناجح — خطأ أمرٍ (set -e) أو رفضٍ صريح (die) — يُطبع أين توقّف وما العمل.
# (فخّ EXIT لا ERR: `die` تخرج بـexit فلا يلتقطها ERR)
on_exit() {
    local status="$1"
    [ "$status" -eq 0 ] && return 0
    echo
    echo "════════════════════════════════════════════════════════════"
    echo "⛔ فشل النشر عند: ${STEP}"
    if [ "$SITE_DOWN" -eq 1 ]; then
        echo "   الموقع **ما زال في الصيانة** عمداً — لم يُفتح على نشرٍ ناقص."
        echo "   • أصلح السبب ثمّ أعد: ./deploy.sh ${TARGET}"
        echo "   • أو ارجع للإصدار السابق: ./rollback.sh          (الكود فقط)"
        echo "                              ./rollback.sh --with-db (والقاعدة من نسخة ما قبل النشر)"
        echo "   • آخر نسخة احتياطيّة: $(state_get last_backup)"
    else
        echo "   لم يتغيّر شيء — الموقع ما زال يعمل بالإصدار الحاليّ."
    fi
    echo "════════════════════════════════════════════════════════════"
    state_log "FAILED deploy ${TARGET} at: ${STEP}"
}
trap 'on_exit $?' EXIT

log "🚀 النشر الآمن — الهدف: ${TARGET}"

# ── ١. التحضير (الموقع يعمل؛ أيّ رفضٍ هنا لا يغيّر شيئاً) ─────────────────────────────────────
STEP="التحضير: جلب الإصدار"
git fetch --tags --prune origin
TARGET_SHA="$(git rev-parse --verify "${TARGET}^{commit}")"
CURRENT_SHA="$(git rev-parse HEAD)"
log "الحاليّ: ${CURRENT_SHA:0:10} ← الهدف: ${TARGET_SHA:0:10}"

STEP="التحضير: نظافة نسخة الخادم"
# ملفّا القفل لا يُعدَّلان على الخادم أبداً — نسختهما من المستودع هي الحقّ (npm ci فاشل عدّلهما مرّة، 2026-09-26)
git checkout -- package-lock.json composer.lock 2>/dev/null || true
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    git status --short --untracked-files=no
    die "تعديلاتٌ محلّيّة على ملفّات المستودع في الخادم — لا يُكتب فوقها. احفظها أو تخلّص منها ثمّ أعد النشر."
fi

STEP="التحضير: فحص إعداد البيئة (الكود الحاليّ)"
if php artisan list --raw 2>/dev/null | grep -q '^env:check'; then
    php artisan env:check
fi

# ── ٢. الصيانة ثمّ إيقاف العمّال ثمّ النسخة (لا كتابة بين النسخة والترحيل) ───────────────────────
STEP="تفعيل الصيانة"
SECRET="$(random_secret)"
php artisan down --secret="$SECRET" --retry=60
SITE_DOWN=1

STEP="إيقاف العمّال"
workers stop

STEP="النسخة الاحتياطيّة"
BACKUP="$(backup_now predeploy)"
state_set last_backup "$BACKUP"
log "💾 النسخة: $BACKUP"

# ── ٣. الكود الجديد ───────────────────────────────────────────────────────────────────────
STEP="تسجيل الإصدار السابق"
state_set previous_commit "$CURRENT_SHA"

STEP="التحويل إلى الإصدار الهدف"
git checkout --quiet --detach "$TARGET_SHA"

STEP="البناء (composer/npm)"
build_app

# ── ٤. القاعدة والإعداد ───────────────────────────────────────────────────────────────────
STEP="ترحيل القاعدة"
php artisan migrate --force

STEP="مزامنة المصادر القانونيّة"
# متكرّرة بلا أثر: الجديد مسودة، والمعتمد لا يُخفَّض، وملفٌّ معطوب يُسقط النشر هنا قبل أيّ كتابة
php artisan ai:sync-sources

STEP="الكاشات"
cache_app

STEP="فحص إعداد البيئة (الكود الجديد)"
php artisan env:check

# ── ٥. التحقّق قبل الإتاحة ثمّ الإتاحة ──────────────────────────────────────────────────────
STEP="فحص الصحّة (من خلف الصيانة)"
health_check "$SECRET"

STEP="تشغيل العمّال"
workers start

STEP="إتاحة الموقع"
php artisan up
SITE_DOWN=0

state_set current_commit "$TARGET_SHA"
state_log "OK deploy ${TARGET} ${CURRENT_SHA:0:10} -> ${TARGET_SHA:0:10} backup=${BACKUP}"

# ── ٦. تنبيهات تشغيليّة (لا تُفشل النشر) ─────────────────────────────────────────────────────
if [ "$(cfg queue.default)" = "sync" ]; then
    warn "QUEUE_CONNECTION=sync في الإنتاج — كلّ مهمّة تُنفَّذ داخل طلب المستخدم. اضبطه database وشغّل عاملاً."
elif ! pgrep -f "artisan queue:work" > /dev/null 2>&1; then
    warn "لا عامل طابور يعمل — تسجيلات Zoom والإشعارات لن تُنفَّذ. راجع: supervisorctl status"
else
    log "✅ عامل الطابور يعمل"
fi

# بلا المجدول لا تُطلَق روابط الجلسات ولا التذكيرات — والعطل صامتٌ تماماً
if crontab -l 2>/dev/null | grep -q "schedule:run" || grep -qs "schedule:run" /etc/cron.d/*; then
    log "✅ المجدول مسجَّل في cron"
else
    warn "لا مدخل cron لـschedule:run — أضِفه: * * * * * cd $(pwd) && php artisan schedule:run >> /dev/null 2>&1"
fi

log "✅ اكتمل النشر: ${TARGET_SHA:0:10} — للتراجع: ./rollback.sh"
}

main "$@"
