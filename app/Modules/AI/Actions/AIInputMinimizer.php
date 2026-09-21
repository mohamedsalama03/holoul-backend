<?php

declare(strict_types=1);

namespace App\Modules\AI\Actions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class AIInputMinimizer
{
    public function handle(string $text, int $maximum): string
    {
        if (! mb_check_encoding($text, 'UTF-8') || $maximum < 1 || mb_strlen($text) > $maximum || trim($text) === '') {
            throw new HttpException(422);
        }
        $patterns = [
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '/(?<!\w)\+?\d[\d ().\-]{7,}\d(?!\w)/u',
            '/\b(?:password|passwd|secret|api[_ -]?key|access[_ -]?token|session[_ -]?(?:id|token)|mfa[_ -]?code)\s*[:=]\s*[^\s,;]+/iu',
            '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/iu',
        ];
        $redacted = preg_replace($patterns, '[REDACTED]', $text);
        if (! is_string($redacted)) {
            throw new HttpException(422);
        }

        return $redacted;
    }
}
