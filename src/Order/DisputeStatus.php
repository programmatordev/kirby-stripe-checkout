<?php

declare(strict_types=1);

namespace ProgrammatorDev\StripeCheckout\Order;

enum DisputeStatus: string
{
    case None = 'none';
    case NeedsResponse = 'needs_response';
    case UnderReview = 'under_review';
    case ResolvedFavorable = 'resolved_favorable';
    case ResolvedLost = 'resolved_lost';
    case Mixed = 'mixed';
}
