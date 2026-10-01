<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/**
 * سجلّ البوّابات: الاسم ← الصنف من `config('services.payments.gateways')`، والافتراضيّة من
 * `PAYMENT_GATEWAY`. المصدر الوحيد لاختيار البوّابة — لا يُنادى صنف بوّابةٍ بعينه خارج هذا السجلّ.
 */
final class PaymentGateways
{
    /** البوّابة التي تُنشأ بها روابط الدفع الجديدة. */
    public function default(): PaymentGateway
    {
        return $this->get((string) config('services.payments.default'));
    }

    public function get(string $name): PaymentGateway
    {
        $class = config("services.payments.gateways.{$name}");
        if (! is_string($class) || ! is_a($class, PaymentGateway::class, true)) {
            throw new InvalidArgumentException("بوّابة دفع غير مسجّلة: {$name}");
        }

        /** @var PaymentGateway */
        return app($class);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys((array) config('services.payments.gateways', []));
    }

    /** @return list<PaymentGateway> */
    public function all(): array
    {
        return array_map(fn (string $name) => $this->get($name), $this->names());
    }

    /** الاسم المعروض لبوّابةٍ مسجَّلة في الدفتر — والاسم الخامّ إن لم تعد مسجّلة. */
    public function label(?string $name): string
    {
        return $name !== null && in_array($name, $this->names(), true) ? $this->get($name)->label() : (string) $name;
    }
}
