#!/bin/bash
# ════════════════════════════════════════════════════════════════════════════
# دوالّ النشر المشتركة — يستوردها deploy.sh وrollback.sh وdeploy/backup.sh (source).
# فصل البيئات، المرحلة ٢ (2026-09-30). لا تُشغَّل وحدها.
#
# متغيّرات قابلة للضبط من البيئة (القيم الافتراضيّة بين الأقواس):
#   BACKUP_DIR           مجلّد النسخ الاحتياطيّة            ($HOME/law-backups)
#   BACKUP_KEEP          عدد النسخ المحفوظة قبل حذف الأقدم   (14)
#   BACKUP_REMOTE        وجهة نسخةٍ خارج الخادم لـscp/rsync، مثل user@host:/backups/law (فارغ = بلا)
#   DEPLOY_STATE_DIR     سجلّ الإصدارات (السابق/الحاليّ/آخر نسخة) ($HOME/law-deploy)
#   SUPERVISOR_PROGRAMS  برامج المشروع في supervisor          ("salasel-worker salasel-reverb")
# ════════════════════════════════════════════════════════════════════════════

# `set -e` يسري داخل `$(...)` أيضاً (لا يُورَّث افتراضيّاً في bash)
shopt -s inherit_errexit

BACKUP_DIR="${BACKUP_DIR:-$HOME/law-backups}"
BACKUP_KEEP="${BACKUP_KEEP:-14}"
BACKUP_REMOTE="${BACKUP_REMOTE:-}"
DEPLOY_STATE_DIR="${DEPLOY_STATE_DIR:-$HOME/law-deploy}"
SUPERVISOR_PROGRAMS="${SUPERVISOR_PROGRAMS:-salasel-worker salasel-reverb}"

# git بلا تتبّع بت التنفيذ: ملفّاتٌ غيّر وضعَها `chmod` قديمٌ على الخادم ليست تعديلاً في المحتوى
git() { command git -c core.fileMode=false "$@"; }

log() { echo "[$(date '+%H:%M:%S')] $*"; }
warn() { echo "[$(date '+%H:%M:%S')] ⚠️  $*" >&2; }
die() { echo "[$(date '+%H:%M:%S')] ⛔ $*" >&2; exit 1; }

# قيمة إعدادٍ من Laravel بالكود الحاليّ (يحترم config:cache) — بلا tinker ولا تفسير .env يدويّاً
cfg() {
    # shellcheck disable=SC2016 # شيفرة PHP حرفيّة — لا توسيع صدفة داخلها
    php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $v = config($argv[1]);
        echo is_bool($v) ? ($v ? "true" : "false") : (string) $v;
    ' "$1"
}

state_get() { cat "$DEPLOY_STATE_DIR/$1" 2>/dev/null || true; }
state_set() { mkdir -p "$DEPLOY_STATE_DIR" && printf '%s\n' "$2" > "$DEPLOY_STATE_DIR/$1"; }
state_log() { mkdir -p "$DEPLOY_STATE_DIR" && echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$DEPLOY_STATE_DIR/history.log"; }

# ── النسخة الاحتياطيّة: القاعدة + الملفّات المرفوعة، مضغوطتين ومفحوصتين ومدوّرتين ──────────────
# تطبع مسار مجلّد النسخة. تفشل (فيتوقّف المستدعي) إن تعذّر الأخذ أو الفحص.
backup_now() {
    local label="$1" conn dir
    command -v mysqldump > /dev/null || die "mysqldump غير متوفّر — لا نشر بلا نسخة احتياطيّة."

    conn="$(cfg database.default)"
    [ "$conn" = "mysql" ] || [ "$conn" = "mariadb" ] || die "النسخ الاحتياطيّ يدعم MySQL/MariaDB فقط (الاتّصال الحاليّ: $conn)."

    dir="$BACKUP_DIR/$(date +%Y%m%d-%H%M%S)-$label"
    mkdir -p "$dir"
    chmod 700 "$BACKUP_DIR" "$dir"

    # فحصٌ صريح لا اعتمادٌ على `set -e`: هذه الدالّة تُنادى داخل `$(...)` حيث لا يُورَّث — فشلُ mysqldump
    # (صلاحيّة مرفوضة مثلاً) كان يمرّ ويُكتب ملفٌّ برأسٍ فارغ، فيمضي النشر بلا نسخة (ثبت في التجربة 2026-09-30)
    if ! MYSQL_PWD="$(cfg "database.connections.$conn.password")" mysqldump \
        --no-tablespaces --single-transaction --routines --triggers \
        -h "$(cfg "database.connections.$conn.host")" -P "$(cfg "database.connections.$conn.port")" \
        -u "$(cfg "database.connections.$conn.username")" "$(cfg "database.connections.$conn.database")" \
        | gzip -9 > "$dir/db.sql.gz"; then
        die "فشل mysqldump — لا نشر بلا نسخةٍ احتياطيّة سليمة."
    fi
    gzip -t "$dir/db.sql.gz" || die "النسخة الاحتياطيّة للقاعدة تالفة: $dir/db.sql.gz"
    # mysqldump يختم بـ«Dump completed» عند النجاح وحده — نسخةٌ مبتورة لا تحمله
    gzip -dc "$dir/db.sql.gz" | tail -n 1 | grep -q 'Dump completed' || die "النسخة الاحتياطيّة للقاعدة ناقصة: $dir/db.sql.gz"

    # الملفّات المرفوعة (مرفقات الطلبات والقضايا والتسجيلات) — بلا المؤقّت
    tar --exclude='storage/app/browsershot-tmp' -czf "$dir/files.tar.gz" storage/app || die "تعذّر نسخ الملفّات المرفوعة."
    tar -tzf "$dir/files.tar.gz" > /dev/null || die "النسخة الاحتياطيّة للملفّات تالفة: $dir/files.tar.gz"

    git rev-parse HEAD > "$dir/commit.txt" 2>/dev/null || true

    if [ -n "$BACKUP_REMOTE" ]; then
        if command -v rsync > /dev/null; then
            rsync -a "$dir" "$BACKUP_REMOTE/" || warn "تعذّر نسخ النسخة إلى $BACKUP_REMOTE — هي على هذا الخادم فقط."
        else
            scp -rq "$dir" "$BACKUP_REMOTE/" || warn "تعذّر نسخ النسخة إلى $BACKUP_REMOTE — هي على هذا الخادم فقط."
        fi
    fi

    # التدوير: أحدث BACKUP_KEEP نسخة فقط (المجلّدات مسمّاة بالتاريخ فترتيبها زمنيّ)
    find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -name '20*' | sort | head -n "-$BACKUP_KEEP" | xargs -r rm -rf

    echo "$dir"
}

# ── استعادة القاعدة من نسخة: **على قاعدةٍ مُفرَغة** ───────────────────────────────────────────
# النسخة تحمل جداولها وحدها: جدولٌ أنشأه النشر الفاشل بعدها يبقى إن استُوردت فوقه، ويُسقط إعادةَ النشر بـ«الجدول موجود»
# (ثبت في التجربة 2026-09-30). فتُحذف الجداول والعروض كلّها أوّلاً ثمّ يُستورد — بصلاحيّات الجداول لا إنشاء القواعد.
restore_db() {
    local file="$1" conn host port user db
    conn="$(cfg database.default)"
    host="$(cfg "database.connections.$conn.host")"
    port="$(cfg "database.connections.$conn.port")"
    user="$(cfg "database.connections.$conn.username")"
    db="$(cfg "database.connections.$conn.database")"
    export MYSQL_PWD
    MYSQL_PWD="$(cfg "database.connections.$conn.password")"

    gzip -t "$file" || die "النسخة تالفة: $file"
    gzip -dc "$file" | tail -n 1 | grep -q 'Dump completed' || die "النسخة ناقصة: $file"

    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        mysql -N -h "$host" -P "$port" -u "$user" "$db" -e "SHOW FULL TABLES" \
            | awk -F'\t' '{ printf "DROP %s IF EXISTS `%s`;\n", ($2 == "VIEW" ? "VIEW" : "TABLE"), $1 }'
        echo "SET FOREIGN_KEY_CHECKS=1;"
    } | mysql -h "$host" -P "$port" -u "$user" "$db"

    gzip -dc "$file" | mysql -h "$host" -P "$port" -u "$user" "$db"
    unset MYSQL_PWD
}

# ── عمّال المشروع في supervisor — بالاسم لا `all` (لا يوقف برامج غيره على الخادم) ─────────────────
workers() {
    local action="$1" program
    php artisan queue:restart > /dev/null 2>&1 || true
    if ! command -v supervisorctl > /dev/null; then
        warn "supervisorctl غير متوفّر — تخطّي ${action} العمّال."
        return 0
    fi
    if [ "$EUID" -ne 0 ]; then
        warn "ليس المستخدم جذراً — تخطّي ${action} العمّال (شغّل النشر بـsudo أو راجع supervisorctl status)."
        return 0
    fi
    for program in $SUPERVISOR_PROGRAMS; do
        supervisorctl "$action" "$program:*" || warn "تعذّر $action $program"
    done
}

# ── البناء: حزم الخادم والواجهة ──────────────────────────────────────────────────────────────
build_app() {
    composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    npm ci
    if ! npx puppeteer browsers installed 2>/dev/null | grep -q "chrome"; then
        npx puppeteer browsers install chrome || warn "تعذّر تثبيت كروم لـPDF — يعمل المولّد الاحتياطيّ."
    fi
    npm run build
}

cache_app() {
    mkdir -p storage/app/browsershot-tmp
    # `X` الكبيرة: تنفيذٌ للمجلّدات وحدها — `775` كانت تضيف بت التنفيذ لملفّات `.gitignore` المتتبَّعة فيراها git
    # تعديلاً ويرفض فحصُ النظافة كلَّ نشرٍ تالٍ (ثبت في التجربة 2026-09-30)
    chmod -R ug+rwX storage bootstrap/cache || true
    php artisan storage:link > /dev/null 2>&1 || true
    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan event:cache
}

# ── فحص الصحّة **والموقع ما زال في الصيانة** — عبر رابط التجاوز السرّيّ ─────────────────────────
health_check() {
    local secret="$1" url jar code
    url="$(cfg app.url)"
    command -v curl > /dev/null || { warn "curl غير متوفّر — تخطّي فحص الصحّة."; return 0; }
    jar="$(mktemp)"
    curl -s -o /dev/null -c "$jar" "$url/$secret" || true
    code="$(curl -s -o /dev/null -b "$jar" -w '%{http_code}' "$url/up" || echo 000)"
    rm -f "$jar"
    [ "$code" = "200" ] || die "فحص الصحّة أعاد $code على $url/up."
    log "✅ فحص الصحّة: 200"
}

random_secret() { php -r 'echo bin2hex(random_bytes(16));'; }
