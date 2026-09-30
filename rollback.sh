#!/bin/bash
# ════════════════════════════════════════════════════════════════════════════
# الرجوع إلى الإصدار السابق (فصل البيئات، المرحلة ٢ — 2026-09-30)
#
#   ./rollback.sh                 الكود فقط — حين لم يغيّر النشر الفاشل بنية القاعدة أو كان ترحيله إضافيّاً
#   ./rollback.sh --with-db       والقاعدة من نسخة ما قبل النشر ⚠️ يُفقد ما كُتب بعدها
#   ./rollback.sh --with-files    والملفّات المرفوعة من النسخة نفسها (الحاليّة تُحفظ جانباً لا تُحذف)
#   --yes                         بلا سؤال تأكيد
#
# الإصدار السابق والنسخة من سجلّ deploy.sh في $DEPLOY_STATE_DIR. الموقع يبقى في الصيانة عند أيّ فشل.
# ════════════════════════════════════════════════════════════════════════════
set -Eeuo pipefail
cd "$(dirname "$0")"
# shellcheck source=deploy/lib.sh
source deploy/lib.sh

# الجسم كلّه داخل دالّةٍ تُنادى في آخر سطر: bash يقرأ السكربت قطعةً قطعة أثناء التنفيذ، و`git checkout` أدناه
# يبدّل هذا الملفّ نفسه على القرص — فبلا الدالّة قد يُنفَّذ خليطٌ من نسختين. هكذا يُقرأ كاملاً قبل أيّ تنفيذ.
main() {
WITH_DB=0
WITH_FILES=0
ASSUME_YES=0
for arg in "$@"; do
    case "$arg" in
        --with-db) WITH_DB=1 ;;
        --with-files) WITH_FILES=1 ;;
        --yes) ASSUME_YES=1 ;;
        *) die "خيارٌ غير معروف: $arg (المتاح: --with-db --with-files --yes)" ;;
    esac
done

STEP="التحضير"
SITE_DOWN=0
on_exit() {
    local status="$1"
    [ "$status" -eq 0 ] && return 0
    echo "⛔ فشل التراجع عند: ${STEP}"
    [ "$SITE_DOWN" -eq 1 ] && echo "   الموقع ما زال في الصيانة. النسخة: ${BACKUP:-—} — راجع السبب ثمّ أعد ./rollback.sh"
    state_log "FAILED rollback at: ${STEP}"
}
trap 'on_exit $?' EXIT

PREVIOUS="$(state_get previous_commit)"
BACKUP="$(state_get last_backup)"
[ -n "$PREVIOUS" ] || die "لا إصدار سابق مسجَّل في $DEPLOY_STATE_DIR — لم يُنشر بـdeploy.sh الجديد بعد."
git cat-file -e "${PREVIOUS}^{commit}" 2>/dev/null || die "الإصدار السابق ${PREVIOUS} غير موجود في المستودع — git fetch أوّلاً."
if [ "$WITH_DB" -eq 1 ] || [ "$WITH_FILES" -eq 1 ]; then
    [ -n "$BACKUP" ] && [ -d "$BACKUP" ] || die "لا نسخة احتياطيّة مسجَّلة ($BACKUP)."
fi

echo "الإصدار الحاليّ: $(git rev-parse --short HEAD) ← الرجوع إلى: ${PREVIOUS:0:10}"
[ "$WITH_DB" -eq 1 ] && echo "⚠️  استعادة القاعدة من: $BACKUP/db.sql.gz — **يُفقد كلّ ما كُتب بعد $(basename "$BACKUP" | cut -c1-15)**"
[ "$WITH_FILES" -eq 1 ] && echo "⚠️  استعادة الملفّات المرفوعة من: $BACKUP/files.tar.gz (الحاليّة تُنقل جانباً)"
if [ "$ASSUME_YES" -ne 1 ]; then
    read -r -p "اكتب ROLLBACK للمتابعة: " answer
    [ "$answer" = "ROLLBACK" ] || die "أُلغي التراجع — لم يتغيّر شيء."
fi

STEP="تفعيل الصيانة"
SECRET="$(random_secret)"
php artisan down --secret="$SECRET" --retry=60
SITE_DOWN=1

STEP="إيقاف العمّال"
workers stop

STEP="التحويل إلى الإصدار السابق"
FAILED_SHA="$(git rev-parse HEAD)"
git checkout --quiet -- package-lock.json composer.lock 2>/dev/null || true
git checkout --quiet --detach "$PREVIOUS"

STEP="البناء (composer/npm)"
build_app

if [ "$WITH_DB" -eq 1 ]; then
    STEP="استعادة القاعدة"
    conn="$(cfg database.default)"
    gzip -dc "$BACKUP/db.sql.gz" | MYSQL_PWD="$(cfg "database.connections.$conn.password")" mysql \
        -h "$(cfg "database.connections.$conn.host")" -P "$(cfg "database.connections.$conn.port")" \
        -u "$(cfg "database.connections.$conn.username")" "$(cfg "database.connections.$conn.database")"
    log "✅ استُعيدت القاعدة"
fi

if [ "$WITH_FILES" -eq 1 ]; then
    STEP="استعادة الملفّات المرفوعة"
    aside="storage/app.before-rollback-$(date +%Y%m%d-%H%M%S)"
    mv storage/app "$aside"
    tar -xzf "$BACKUP/files.tar.gz"
    log "✅ استُعيدت الملفّات (الحاليّة محفوظة في $aside)"
fi

STEP="الكاشات"
cache_app

STEP="فحص الصحّة (من خلف الصيانة)"
health_check "$SECRET"

STEP="تشغيل العمّال"
workers start

STEP="إتاحة الموقع"
php artisan up
SITE_DOWN=0

state_set current_commit "$PREVIOUS"
state_set previous_commit "$FAILED_SHA"
state_log "OK rollback ${FAILED_SHA:0:10} -> ${PREVIOUS:0:10} db=${WITH_DB} files=${WITH_FILES}"
log "✅ رجع الموقع إلى ${PREVIOUS:0:10}"
}

main "$@"
