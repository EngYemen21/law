<?php

/*
|--------------------------------------------------------------------------
| رسائل التحقّق بالعربيّة
|--------------------------------------------------------------------------
|
| **لماذا وُجد هذا الملفّ؟** كان `APP_LOCALE=en` ولا مجلّد `lang/`، فكلّ قاعدة تحقّقٍ مبنيّة في
| لارافل تصل المستخدمَ العربيّ بالإنجليزيّة: رُصد حيّاً أنّ العميل يرى
| «The file field must be a file of type: pdf, jpg…» تحت رسالةٍ عربيّة من قواعد المشروع
| المخصّصة — تناقضٌ في الشاشة الواحدة (2026-09-25).
|
| **والترجمة كاملةٌ لا مقتصرةٌ على المستعمَل.** القواعد المستعملة اليوم خمس عشرة، لكنّ أيّ
| قاعدةٍ تُضاف غداً تجد ترجمتها هنا — فلا يعود العطل بصيغةٍ أخرى. وهذا شرطُ الإصلاح
| المستدام في `AGENTS.md` القاعدة السادسة.
|
| ويحرسه `ArabicValidationMessagesTest`: يفشل إن عادت اللغة إنجليزيّة أو نقص مفتاحٌ
| تستعمله قاعدةٌ في المشروع.
|
*/

return [
    'accepted' => 'يجب قبول :attribute.',
    'accepted_if' => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url' => ':attribute ليس رابطاً صحيحاً.',
    'after' => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute تاريخاً بعد أو يساوي :date.',
    'alpha' => 'يجب ألّا يحتوي :attribute إلّا على حروف.',
    'alpha_dash' => 'يجب ألّا يحتوي :attribute إلّا على حروف وأرقام وشرطات.',
    'alpha_num' => 'يجب ألّا يحتوي :attribute إلّا على حروف وأرقام.',
    'any_of' => ':attribute غير صالح.',
    'array' => 'يجب أن يكون :attribute مصفوفة.',
    'ascii' => 'يجب ألّا يحتوي :attribute إلّا على حروف وأرقام ورموز أحاديّة البايت.',
    'before' => 'يجب أن يكون :attribute تاريخاً قبل :date.',
    'before_or_equal' => 'يجب أن يكون :attribute تاريخاً قبل أو يساوي :date.',
    'between' => [
        'array' => 'يجب أن يحتوي :attribute بين :min و:max عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute بين :min و:max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و:max.',
        'string' => 'يجب أن يكون طول :attribute بين :min و:max حرفاً.',
    ],
    'boolean' => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة.',
    'can' => ':attribute يحتوي قيمةً غير مصرَّح بها.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'contains' => ':attribute لا يحتوي قيمةً مطلوبة.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'date' => ':attribute ليس تاريخاً صحيحاً.',
    'date_equals' => 'يجب أن يكون :attribute تاريخاً مساوياً لـ:date.',
    'date_format' => 'لا يطابق :attribute الصيغة :format.',
    'decimal' => 'يجب أن يحتوي :attribute على :decimal منزلة عشريّة.',
    'declined' => 'يجب رفض :attribute.',
    'declined_if' => 'يجب رفض :attribute عندما يكون :other هو :value.',
    'different' => 'يجب أن يختلف :attribute عن :other.',
    'digits' => 'يجب أن يتكوّن :attribute من :digits رقماً.',
    'digits_between' => 'يجب أن يتكوّن :attribute من عددِ أرقامٍ بين :min و:max.',
    'dimensions' => 'أبعاد صورة :attribute غير صالحة.',
    'distinct' => 'قيمة :attribute مكرّرة.',
    'doesnt_end_with' => 'يجب ألّا ينتهي :attribute بأحد التالي: :values.',
    'doesnt_start_with' => 'يجب ألّا يبدأ :attribute بأحد التالي: :values.',
    'email' => 'يجب أن يكون :attribute بريداً إلكترونيّاً صحيحاً.',
    'ends_with' => 'يجب أن ينتهي :attribute بأحد التالي: :values.',
    'enum' => ':attribute المختار غير صالح.',
    'exists' => ':attribute المختار غير موجود.',
    'extensions' => 'يجب أن يكون امتداد :attribute أحد التالي: :values.',
    'file' => 'يجب أن يكون :attribute ملفّاً.',
    'filled' => 'حقل :attribute مطلوب.',
    'gt' => [
        'array' => 'يجب أن يحتوي :attribute أكثر من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string' => 'يجب أن يكون طول :attribute أكثر من :value حرفاً.',
    ],
    'gte' => [
        'array' => 'يجب أن يحتوي :attribute :value عنصراً أو أكثر.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أكبر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أكبر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أكثر.',
    ],
    'hex_color' => 'يجب أن يكون :attribute لوناً ستّ عشريّاً صحيحاً.',
    'image' => 'يجب أن يكون :attribute صورة.',
    'in' => ':attribute المختار غير صالح.',
    'in_array' => 'قيمة :attribute غير موجودة في :other.',
    'in_array_keys' => 'يجب أن يحتوي :attribute أحد المفاتيح التالية على الأقلّ: :values.',
    'integer' => 'يجب أن يكون :attribute عدداً صحيحاً.',
    'ip' => 'يجب أن يكون :attribute عنوان IP صحيحاً.',
    'ipv4' => 'يجب أن يكون :attribute عنوان IPv4 صحيحاً.',
    'ipv6' => 'يجب أن يكون :attribute عنوان IPv6 صحيحاً.',
    'json' => 'يجب أن يكون :attribute نصّ JSON صحيحاً.',
    'list' => 'يجب أن يكون :attribute قائمة.',
    'lowercase' => 'يجب أن يكون :attribute بحروفٍ صغيرة.',
    'lt' => [
        'array' => 'يجب أن يحتوي :attribute أقلّ من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute أصغر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أصغر من :value.',
        'string' => 'يجب أن يكون طول :attribute أقلّ من :value حرفاً.',
    ],
    'lte' => [
        'array' => 'يجب ألّا يحتوي :attribute أكثر من :value عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute :value كيلوبايت أو أصغر.',
        'numeric' => 'يجب أن تكون قيمة :attribute :value أو أصغر.',
        'string' => 'يجب أن يكون طول :attribute :value حرفاً أو أقلّ.',
    ],
    'mac_address' => 'يجب أن يكون :attribute عنوان MAC صحيحاً.',
    'max' => [
        'array' => 'يجب ألّا يحتوي :attribute أكثر من :max عنصراً.',
        'file' => 'يجب ألّا يتجاوز حجم :attribute :max كيلوبايت.',
        'numeric' => 'يجب ألّا تتجاوز قيمة :attribute :max.',
        'string' => 'يجب ألّا يتجاوز طول :attribute :max حرفاً.',
    ],
    'max_digits' => 'يجب ألّا يحتوي :attribute أكثر من :max رقماً.',
    'mimes' => 'يجب أن يكون :attribute ملفّاً من نوع: :values.',
    'mimetypes' => 'يجب أن يكون :attribute ملفّاً من نوع: :values.',
    'min' => [
        'array' => 'يجب أن يحتوي :attribute :min عنصراً على الأقلّ.',
        'file' => 'يجب أن يكون حجم :attribute :min كيلوبايت على الأقلّ.',
        'numeric' => 'يجب أن تكون قيمة :attribute :min على الأقلّ.',
        'string' => 'يجب أن يكون طول :attribute :min حرفاً على الأقلّ.',
    ],
    'min_digits' => 'يجب أن يحتوي :attribute :min رقماً على الأقلّ.',
    'missing' => 'يجب ألّا يوجد حقل :attribute.',
    'missing_if' => 'يجب ألّا يوجد حقل :attribute عندما يكون :other هو :value.',
    'missing_unless' => 'يجب ألّا يوجد حقل :attribute ما لم يكن :other هو :value.',
    'missing_with' => 'يجب ألّا يوجد حقل :attribute عند وجود :values.',
    'missing_with_all' => 'يجب ألّا يوجد حقل :attribute عند وجود :values.',
    'multiple_of' => 'يجب أن تكون قيمة :attribute من مضاعفات :value.',
    'not_in' => ':attribute المختار غير صالح.',
    'not_regex' => 'صيغة :attribute غير صالحة.',
    'numeric' => 'يجب أن يكون :attribute رقماً.',
    'password' => [
        'letters' => 'يجب أن تحتوي :attribute على حرفٍ واحد على الأقلّ.',
        'mixed' => 'يجب أن تحتوي :attribute على حرفٍ كبير وآخر صغير على الأقلّ.',
        'numbers' => 'يجب أن تحتوي :attribute على رقمٍ واحد على الأقلّ.',
        'symbols' => 'يجب أن تحتوي :attribute على رمزٍ واحد على الأقلّ.',
        'uncompromised' => 'ظهرت :attribute في تسريبِ بيانات — اختر غيرها.',
    ],
    'present' => 'يجب أن يوجد حقل :attribute.',
    'present_if' => 'يجب أن يوجد حقل :attribute عندما يكون :other هو :value.',
    'present_unless' => 'يجب أن يوجد حقل :attribute ما لم يكن :other هو :value.',
    'present_with' => 'يجب أن يوجد حقل :attribute عند وجود :values.',
    'present_with_all' => 'يجب أن يوجد حقل :attribute عند وجود :values.',
    'prohibited' => 'حقل :attribute ممنوع.',
    'prohibited_if' => 'حقل :attribute ممنوع عندما يكون :other هو :value.',
    'prohibited_if_accepted' => 'حقل :attribute ممنوع عند قبول :other.',
    'prohibited_if_declined' => 'حقل :attribute ممنوع عند رفض :other.',
    'prohibited_unless' => 'حقل :attribute ممنوع ما لم يكن :other ضمن :values.',
    'prohibits' => 'حقل :attribute يمنع وجود :other.',
    'regex' => 'صيغة :attribute غير صالحة.',
    'required' => 'حقل :attribute مطلوب.',
    'required_array_keys' => 'يجب أن يحتوي :attribute المفاتيح: :values.',
    'required_if' => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عند قبول :other.',
    'required_if_declined' => 'حقل :attribute مطلوب عند رفض :other.',
    'required_unless' => 'حقل :attribute مطلوب ما لم يكن :other ضمن :values.',
    'required_with' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all' => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without' => 'حقل :attribute مطلوب عند غياب :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند غياب :values جميعاً.',
    'same' => 'يجب أن يتطابق :attribute مع :other.',
    'size' => [
        'array' => 'يجب أن يحتوي :attribute :size عنصراً.',
        'file' => 'يجب أن يكون حجم :attribute :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute :size.',
        'string' => 'يجب أن يكون طول :attribute :size حرفاً.',
    ],
    'starts_with' => 'يجب أن يبدأ :attribute بأحد التالي: :values.',
    'string' => 'يجب أن يكون :attribute نصّاً.',
    'timezone' => 'يجب أن يكون :attribute منطقةً زمنيّة صحيحة.',
    'unique' => ':attribute مستعمَلٌ من قبل.',
    'uploaded' => 'تعذّر رفع :attribute.',
    'uppercase' => 'يجب أن يكون :attribute بحروفٍ كبيرة.',
    'url' => 'يجب أن يكون :attribute رابطاً صحيحاً.',
    'ulid' => 'يجب أن يكون :attribute معرّف ULID صحيحاً.',
    'uuid' => 'يجب أن يكون :attribute معرّف UUID صحيحاً.',

    /*
    |--------------------------------------------------------------------------
    | رسائل مخصّصة لحقولٍ بعينها
    |--------------------------------------------------------------------------
    |
    | رسائلُ المشروع الخاصّة تبقى حيث هي — في `validate()` عند موضعها، لأنّها تحمل سياق
    | العمل لا صيغة القاعدة. وهذا الموضع لما يتكرّر عبر شاشاتٍ كثيرة.
    |
    */
    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | أسماء الحقول كما تُعرض للمستخدم
    |--------------------------------------------------------------------------
    |
    | بدونها تظهر الرسالة باسم الحقل البرمجيّ («حقل reason مطلوب»). وتُذكر هنا الحقول
    | الشائعة في نماذج المشروع؛ ويُضاف إليها كلّ حقلٍ جديد يراه المستخدم.
    |
    */
    /*
     * **قيمُ الحقول كما تُقرأ.** `required_if` يطبع القيمة المشروطة في رسالته، فبدونها تقول:
     * «حقل الملاحظة مطلوب عندما يكون السبب other».
     */
    'values' => [
        'reason' => [
            'other' => 'سببٌ آخر',
        ],
    ],

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الجوال',
        'mobile' => 'رقم الجوال',
        'national_id' => 'رقم الهوية',
        'password' => 'كلمة المرور',
        'subject' => 'موضوع الطلب',
        'details' => 'نصّ الرسالة',
        'body' => 'نصّ الرسالة',
        'reason' => 'السبب',
        'note' => 'الملاحظة',
        'file' => 'الملف',
        'docs' => 'المستندات',
        'price' => 'السعر',
        'amount' => 'المبلغ',
        'fee' => 'الأتعاب',
        'channel' => 'قناة الاستشارة',
        'track' => 'المسار',
        'status' => 'الحالة',
        'department' => 'القسم',
        'department_id' => 'القسم',
        'service_id' => 'الخدمة',
        'type' => 'النوع',
        'priority' => 'الأهمية',
        'day' => 'التاريخ',
        'time' => 'الوقت',
        'date' => 'التاريخ',
        'title' => 'العنوان',
        'court' => 'المحكمة',
        'summary' => 'الملخّص',
        'lawyer_id' => 'المحامي',
        'assigned_to' => 'المُسنَد إليه',
        'due' => 'تاريخ الاستحقاق',
        // مدّة جلسة المحكمة المتوقّعة (قرار المالك 2026-09-26) — فيُقال «المدّة المتوقّعة» لا «duration min»
        'duration_min' => 'المدّة المتوقّعة (بالدقائق)',
        'hearing_duration_min' => 'المدّة المتوقّعة للجلسة (بالدقائق)',
    ],
];
