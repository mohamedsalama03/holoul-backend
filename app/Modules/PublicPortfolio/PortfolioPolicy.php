<?php

declare(strict_types=1);

namespace App\Modules\PublicPortfolio;

final class PortfolioPolicy
{
    public const CATEGORIES = ['web' => 'Web Development', 'mobile' => 'Mobile Applications', 'business' => 'Business Solutions', 'commerce' => 'E-Commerce', 'custom' => 'Custom Solutions'];

    public const MAX_BYTES = 5242880;

    public const CACHE_CONTROL = 'public, max-age=0, s-maxage=60, must-revalidate';
}
