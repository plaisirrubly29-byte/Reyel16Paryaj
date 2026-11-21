<?php

namespace App\Modules\IdentityAccess\Enums;

enum KycSubmissionStatus: string
{
    case Pending = 'pending';

    case Approved = 'approved';

    case Rejected = 'rejected';
}
