<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // فرع افتراضي — عزل الرؤية بحسب الفرع يتطلّب فرعاً على الموظف/المحامي والسجلات
            'branch' => Branch::DEFAULT,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * منح الموظف/المحامي كامل الصلاحيات افتراضياً في الاختبارات (يمنح قدرة كاملة داخل لوحته)،
     * كي لا يكسر حارس الصلاحيات الاختبارات الوظيفية؛ وتقيّده الاختبارات المتخصّصة عبر syncPermissions.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user) {
            if (in_array($user->role, [Role::Employee, Role::Lawyer], true)) {
                foreach (Permissions::all() as $name) {
                    Permission::findOrCreate($name, 'web');
                }
                // نماذج (لا أسماء) لتفادي بحث spatie المخبّأ داخل نفس العملية
                $user->syncPermissions(Permission::whereIn('name', Permissions::all())->get());
                // إعادة تحميل الذاكرة كي يقرأ can() الصلاحيات الجديدة عند الإنفاذ
                app(PermissionRegistrar::class)->forgetCachedPermissions();
            }
        });
    }
}
