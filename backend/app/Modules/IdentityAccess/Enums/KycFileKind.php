<?php

namespace App\Modules\IdentityAccess\Enums;

enum KycFileKind: string
{
    case Front = 'front';

    case Back = 'back';

    case Selfie = 'selfie';
}
