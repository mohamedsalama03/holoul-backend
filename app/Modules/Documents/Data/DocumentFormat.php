<?php

declare(strict_types=1);

namespace App\Modules\Documents\Data;

enum DocumentFormat: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';

    public function mime(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        };
    }
}
