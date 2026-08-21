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

    /** نسبة ضريبة القيمة المضافة (%) كما تضبطها الإدارة — مصدر واحد لكل حسابات الضريبة. */
    public static function vatRate(): int
    {
        return (int) static::get('vat_rate', 15);
    }

    /** مبلغ الضريبة على أساسٍ ما، بنسبة الإدارة الحالية. */
    public static function vatOn(int|float $base): int
    {
        return (int) round($base * static::vatRate() / 100);
    }

    /** أسعار الاستشارات الحالية (office/video/phone) + الضريبة — بافتراضات النظام الأصلية. */
    public static function consultPrices(): array
    {
        return [
            'office' => (int) static::get('price_office', 600),
            'video' => (int) static::get('price_video', 450),
            'phone' => (int) static::get('price_phone', 350),
            'vat' => static::vatRate(),
        ];
    }
}
