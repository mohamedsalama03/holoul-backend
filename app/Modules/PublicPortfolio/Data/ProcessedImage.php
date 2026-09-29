<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio\Data;

final readonly class ProcessedImage
{
    /** @param array<string,array{bytes:string,width:int,height:int}> $variants */
    public function __construct(public ?string $rejection, public array $variants = []) {}
}
