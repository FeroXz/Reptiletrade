<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

enum KeywordMode: string
{
    /** Nachricht geht durch, die Moderation sieht sie. */
    case Markieren = 'markieren';

    /** Nachricht wird nicht zugestellt. */
    case Sperre = 'sperre';
}
