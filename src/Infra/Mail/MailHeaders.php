<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Mail;

use Reptilienmarkt\Domain\Mail\MailMessage;

/**
 * Bereitet die zusaetzlichen Kopfzeilen einer Nachricht fuer den Transport auf.
 *
 * An einer Stelle, weil sonst jeder Transport seine eigene Auslegung von
 * "gueltige Kopfzeile" haette — und der schwaechste bestimmte, was einschleusbar
 * ist. Verworfen wird alles, was den vom Transport selbst gesetzten Kopf
 * ueberschreiben koennte, sowie alles mit Zeilenumbruch darin: Ein Umbruch im
 * Wert ist eine Header-Injection und damit ein zweiter Empfaenger.
 */
final readonly class MailHeaders
{
    /**
     * Koepfe, die der Transport selbst setzt. Ein zweites Vorkommen waere
     * bestenfalls doppelt und schlimmstenfalls die Uebernahme des Absenders.
     */
    private const array RESERVED = [
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'date',
        'message-id',
        'mime-version',
        'content-type',
        'content-transfer-encoding',
    ];

    /**
     * @return array<string, string>
     */
    public static function additional(MailMessage $message): array
    {
        $sauber = [];

        foreach ($message->headers as $name => $value) {
            $feld = trim(str_replace(["\r", "\n", "\0", ':'], '', $name));
            $inhalt = trim(str_replace(["\r", "\n", "\0"], '', $value));

            if ($feld === '' || $inhalt === '' || \in_array(strtolower($feld), self::RESERVED, true)) {
                continue;
            }

            $sauber[$feld] = $inhalt;
        }

        return $sauber;
    }
}
