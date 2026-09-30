#!/bin/bash
# ════════════════════════════════════════════════════════════════════════════
# النسخة الاحتياطيّة اليوميّة (القاعدة + الملفّات المرفوعة) — بلا إيقاف الموقع (mysqldump --single-transaction).
# تُدوَّر مع نسخ النشر (BACKUP_KEEP)، وتُنسخ خارج الخادم إن ضُبط BACKUP_REMOTE. في cron الجذر أو مستخدم الموقع:
#   30 3 * * * /var/www/law/deploy/backup.sh >> /var/log/law-backup.log 2>&1
# ════════════════════════════════════════════════════════════════════════════
set -Eeuo pipefail
cd "$(dirname "$0")/.."
# shellcheck source=deploy/lib.sh
source deploy/lib.sh

log "💾 النسخة اليوميّة: $(backup_now daily)"
