<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Job\Handler;

use Reptilienmarkt\Domain\Job\Job;
use Reptilienmarkt\Domain\Job\JobHandler;
use Reptilienmarkt\Domain\User\UserModerationService;

/**
 * Hebt abgelaufene Kontosperren auf.
 *
 * Eine befristete Sperre, die niemand aufhebt, ist eine unbefristete. Deshalb
 * laeuft das stuendlich und nicht auf Zuruf.
 */
final readonly class BanExpiryHandler implements JobHandler
{
    public function __construct(private UserModerationService $moderation) {}

    public function type(): string
    {
        return 'user.ban_expiry';
    }

    public function handle(Job $job): string
    {
        $entsperrt = $this->moderation->releaseExpired();

        return \sprintf('%d Sperren aufgehoben', $entsperrt);
    }
}
