<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Auth;

enum TokenType: string
{
    case PasswordReset = 'password_reset';
    case EmailVerify = 'email_verify';
    case PhoneVerify = 'phone_verify';

    /**
     * Gueltigkeitsdauer in Minuten. Ein Reset-Link ist kurzlebig, weil er
     * vollen Kontozugriff bedeutet; ein Bestaetigungslink darf laenger leben,
     * weil er nur eine Angabe bestaetigt.
     */
    public function lifetimeMinutes(): int
    {
        return match ($this) {
            self::PasswordReset => 60,
            self::EmailVerify => 60 * 24 * 3,
            self::PhoneVerify => 15,
        };
    }

    /**
     * Der Telefoncode wird abgetippt, deshalb kurz und nur aus Ziffern. Die
     * anderen laufen ueber einen Link und duerfen lang sein.
     */
    public function isNumericCode(): bool
    {
        return $this === self::PhoneVerify;
    }

    public function label(): string
    {
        return match ($this) {
            self::PasswordReset => 'Passwort zurücksetzen',
            self::EmailVerify => 'E-Mail bestätigen',
            self::PhoneVerify => 'Telefon bestätigen',
        };
    }
}
