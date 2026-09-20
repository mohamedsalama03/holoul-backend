<?php

declare(strict_types=1);

namespace App\Modules\ProjectIntake\Data;

enum RequestState: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case InformationRequired = 'information_required';
    case Discovery = 'discovery';
    case Proposal = 'proposal';
    case Approved = 'approved';
    case Converted = 'converted';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function amendable(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::InformationRequired], true);
    }

    public function withdrawable(): bool
    {
        return in_array($this, [self::Submitted, self::UnderReview, self::InformationRequired, self::Discovery], true);
    }
}
