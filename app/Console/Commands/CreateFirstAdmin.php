<?php

namespace App\Console\Commands;

use App\Support\FirstAdmin;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * `php artisan admin:first` — ينشئ **المدير الأوّل** عند تثبيتٍ من الصفر (فصل البيئات 2026-09-30).
 * آمن: يرفض إن وُجد أيّ مدير، ولا يكتب فوق حسابٍ قائم، ولا يطبع كلمة مرور (الدخول بالهويّة ورمز الجوال).
 */
class CreateFirstAdmin extends Command
{
    protected $signature = 'admin:first
        {--national-id= : رقم الهويّة (الافتراضيّ من FirstAdmin)}
        {--email= : البريد}
        {--phone= : الجوال بصيغة دوليّة}
        {--name= : الاسم}';

    protected $description = 'ينشئ المدير الأوّل لتثبيتٍ جديد — يرفض إن وُجد مدير';

    public function handle(): int
    {
        $data = [
            'name' => $this->option('name') ?: FirstAdmin::ATTRIBUTES['name'],
            'national_id' => $this->option('national-id') ?: FirstAdmin::ATTRIBUTES['national_id'],
            'email' => $this->option('email') ?: FirstAdmin::ATTRIBUTES['email'],
            'phone' => $this->option('phone') ?: FirstAdmin::ATTRIBUTES['phone'],
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:120'],
            'national_id' => ['required', 'regex:/^\d{10}$/'],
            'email' => ['required', 'email'],
            'phone' => ['required', Phone::RULE],
        ], [
            'national_id.regex' => 'رقم الهويّة عشرة أرقام.',
            'phone.regex' => 'الجوال بصيغة 05xxxxxxxx أو دوليّة كاملة.',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($why = FirstAdmin::blocker($data['national_id'], $data['email'])) {
            $this->error($why);

            return self::FAILURE;
        }

        $admin = FirstAdmin::create($data);

        $this->info("أُنشئ المدير الأوّل: {$admin->name} — الهويّة {$admin->national_id} — الجوال ".Phone::mask((string) $admin->phone));
        $this->line('الدخول برقم الهويّة ورمز الجوال (تقنيات) — لا كلمة مرور.');

        return self::SUCCESS;
    }
}
