<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    /** قراءة قيمة إعداد (مع افتراضي). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $row = static::find($key);

        return $row ? $row->value : $default;
    }

    /** حفظ قيمة إعداد. */
    public static function put(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => (string) $value]);
    }

    /** أسعار الاستشارات الحالية (office/video/phone) + الضريبة — بافتراضات النظام الأصلية. */
    public static function consultPrices(): array
    {
        return [
            'office' => (int) static::get('price_office', 600),
            'video' => (int) static::get('price_video', 450),
            'phone' => (int) static::get('price_phone', 350),
            'vat' => (int) static::get('vat_rate', 15),
        ];
    }
}
