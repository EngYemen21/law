<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * **تصفير بيانات الاختبار — يُحذف كلّ شيءٍ عدا ما يُحفظ صراحةً** (قرار المالك 2026-10-01).
 *
 * كانت قائمةً ثابتة بأربعة وعشرين جدولاً **تُحذف**، فكلّ جدولٍ أُضيف بعدها بقي بعد التصفير: سجلّ التدقيق
 * وانتقالات الرحلة والمصروفات والمستحقّات وسجلّ النسخ — ومشاركو الاجتماعات يشيرون إلى اجتماعاتٍ حُذفت
 * (ثبت بالمتصفّح). الآن القائمة قائمةُ **ما يبقى**، وما عداها يُفرَّغ؛ فالجدول الجديد يُصفَّر افتراضاً.
 */
final class TestDataReset
{
    /** الجداول التي تبقى — وكلّ ما عداها يُفرَّغ. */
    public const KEEP = [
        // المستخدمون والأدوار والصلاحيّات
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        // إعدادات النظام ومفاتيح الخدمات الخارجيّة
        'settings', 'integration_secrets',
        // الكتالوج القانونيّ: الأقسام والخدمات والمصادر
        'legal_departments', 'legal_services', 'legal_sources', 'legal_catalogue_aliases', 'legal_department_documents',
        // أقسام الموظّفين وتخصّصات المحامين
        'staff_departments', 'lawyer_specialties',
        // سجلّ مهاجرات القاعدة — لا يعمل النظام بدونه (تقنيّ، لا بيانات)
        'migrations',
    ];

    /**
     * يُفرّغ كلّ جدولٍ خارج `KEEP` ويحذف المرفقات المرفوعة كلّها.
     *
     * @return list<string> الجداول التي فُرّغت
     */
    public static function run(): array
    {
        $cleared = array_values(array_diff(self::tables(), self::KEEP));

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($cleared as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // المرفقات كلّها (مستندات التذاكر والقضايا والتنفيذ والمصروفات وإثباتات الدفع…) — المجلّدات لا قائمتها
        $disk = Storage::disk('local');
        foreach ($disk->directories() as $directory) {
            $disk->deleteDirectory($directory);
        }
        foreach ($disk->files() as $file) {
            if (basename($file) !== '.gitignore') {
                $disk->delete($file);
            }
        }

        cache()->flush();

        return $cleared;
    }

    /**
     * جداول **قاعدة الاتّصال الحاليّ وحدها** (بلا جداول SQLite الداخليّة). `getTables()` بلا مخطّط يُعيد في
     * MySQL جداول كلّ القواعد على الخادم — ثبت: قاعدتان بـ112 جدولاً بدل 56 — فيُقيَّد باسم القاعدة.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        $connection = Schema::getConnection();
        $rows = $connection->getDriverName() === 'sqlite'
            ? Schema::getTables()
            : Schema::getTables($connection->getDatabaseName());

        return array_values(array_filter(
            array_column($rows, 'name'),
            fn (string $name) => ! str_starts_with($name, 'sqlite_'),
        ));
    }
}
