<?php

namespace App\Console\Commands;

use App\Support\Permissions;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * إزالة الصلاحيات اليتيمة: صفوف في القاعدة لم تعد في كتالوج Permissions.
 *
 * لماذا: PermissionSeeder يُنشئ (firstOrCreate) ولا يحذف. فالصلاحية التي تُزال من
 * الكتالوج تبقى صفّاً في القاعدة **ومُسنَدة فعلاً** لمن أُسندت له — فيمرّ حارس
 * permission: لموظف يُفترض أنه لم يعد يملكها. (مثاله: «إدارة الفروع» بعد إزالة كيان الفرع.)
 *
 * لا يحذف بلا ‎--force: الحذف يمسّ إسنادات قائمة، فيُعرض أولاً ثم يُنفَّذ بقرار صريح.
 */
class PrunePermissions extends Command
{
    protected $signature = 'permissions:prune {--force : نفّذ الحذف فعلاً بدل العرض فقط}';

    protected $description = 'عرض/إزالة الصلاحيات التي لم تعد في كتالوج Permissions';

    public function handle(): int
    {
        $known = Permissions::all();
        $orphans = Permission::whereNotIn('name', $known)->get();

        if ($orphans->isEmpty()) {
            $this->info('لا صلاحيات يتيمة — القاعدة تطابق الكتالوج ('.count($known).' صلاحية).');

            return self::SUCCESS;
        }

        $this->warn('صلاحيات في القاعدة وليست في الكتالوج:');
        $this->table(
            ['الصلاحية', 'أدوار مُسنَدة', 'مستخدمون مُسنَدون'],
            $orphans->map(fn (Permission $p) => [
                $p->name,
                $p->roles()->count(),
                $p->users()->count(),
            ])->all(),
        );

        if (! $this->option('force')) {
            $this->line('');
            $this->info('عرض فقط. للحذف الفعليّ: php artisan permissions:prune --force');

            return self::SUCCESS;
        }

        $names = $orphans->pluck('name')->all();
        Permission::whereIn('name', $names)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('حُذفت '.count($names).' صلاحية: '.implode('، ', $names));

        return self::SUCCESS;
    }
}
