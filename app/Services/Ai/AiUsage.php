<?php

namespace App\Services\Ai;

/**
 * استهلاك التوكنات في نداء واحد — يُقرأ من ردّ المزوّد لا يُقدَّر.
 *
 * كلا المزوّدين يُعيدانه وكانت الشيفرة تُهمله: Gemini في `usageMetadata` وGLM في
 * `usage` (عقد OpenAI). فبلا هذا الرقم لا كلفة ولا ميزانية ولا تنبيه قفزة إنفاق —
 * وهي كلّها مطالب المرحلة P6.
 */
final class AiUsage
{
    public function __construct(
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function isEmpty(): bool
    {
        return $this->total() === 0;
    }

    /** استهلاك Gemini من `usageMetadata`. */
    public static function fromGemini(?array $response): self
    {
        $meta = $response['usageMetadata'] ?? [];

        return new self(
            inputTokens: (int) ($meta['promptTokenCount'] ?? 0),
            outputTokens: (int) ($meta['candidatesTokenCount'] ?? 0),
        );
    }

    /** استهلاك GLM/المتوافق مع OpenAI من `usage`. */
    public static function fromOpenAiCompatible(?array $response): self
    {
        $meta = $response['usage'] ?? [];

        return new self(
            inputTokens: (int) ($meta['prompt_tokens'] ?? 0),
            outputTokens: (int) ($meta['completion_tokens'] ?? 0),
        );
    }
}
