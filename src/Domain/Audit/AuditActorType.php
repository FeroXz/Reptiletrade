<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Audit;

enum AuditActorType: string
{
    case User = 'user';
    case System = 'system';
    case Admin = 'admin';
}
