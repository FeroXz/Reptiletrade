<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Trust;

/**
 * Maskiert E-Mail-Adressen und Telefonnummern.
 *
 * Zweck ist nicht, den Austausch von Kontaktdaten zu verhindern — das laesst
 * sich ohnehin umgehen. Zweck ist, das massenhafte Einsammeln von Adressen
 * durch automatisiertes Anschreiben unattraktiv zu machen. Deshalb greift die
 * Maskierung nur in den ersten Nachrichten einer Konversation: Wer wirklich
 * verhandelt, ist danach frei.
 *
 * Der Text wird beim Anzeigen maskiert, nicht beim Speichern. Das Original
 * bleibt in der Datenbank, weil die Moderation im Missbrauchsfall sehen
 * koennen muss, was tatsaechlich geschrieben wurde.
 */
final readonly class ContactMasker
{
    public const string PLACEHOLDER_EMAIL = '[E-Mail-Adresse ausgeblendet]';

    public const string PLACEHOLDER_PHONE = '[Telefonnummer ausgeblendet]';

    public function __construct(
        private bool $enabled = true,
        private int $firstMessages = 3,
    ) {}

    public function appliesTo(int $sequence): bool
    {
        return $this->enabled && $sequence <= $this->firstMessages;
    }

    public function firstMessages(): int
    {
        return $this->firstMessages;
    }

    /**
     * Maskiert, wenn die Nachricht in den ersten $firstMessages liegt.
     */
    public function maskForSequence(string $text, int $sequence): string
    {
        return $this->appliesTo($sequence) ? $this->mask($text) : $text;
    }

    public function mask(string $text): string
    {
        $masked = $this->maskEmails($text);

        return $this->maskPhones($masked);
    }

    public function containsContactData(string $text): bool
    {
        return $this->mask($text) !== $text;
    }

    private function maskEmails(string $text): string
    {
        // Auch die ueblichen Umschreibungen: "name (at) domain punkt de".
        $pattern = '/[\p{L}\p{N}._%+-]+\s*(?:@|\(at\)|\[at\]|\s+at\s+)\s*[\p{L}\p{N}.-]+\s*'
            . '(?:\.|\s*\(dot\)\s*|\s+punkt\s+|\s+dot\s+)\s*[a-z]{2,}/iu';

        return (string) preg_replace($pattern, self::PLACEHOLDER_EMAIL, $text);
    }

    private function maskPhones(string $text): string
    {
        // Mindestens sieben Ziffern, Trenner erlaubt. Kuerzere Zahlenfolgen
        // sind Preise, Gewichte oder Schlupfjahre und bleiben stehen.
        $pattern = '/(?<![\p{L}\p{N}])(?:\+|00)?[\d][\d\s\/().-]{6,}\d(?![\p{L}\p{N}])/u';

        return (string) preg_replace_callback(
            $pattern,
            static function (array $treffer): string {
                $digits = preg_replace('/\D+/', '', (string) $treffer[0]);

                return \strlen((string) $digits) >= 7 ? self::PLACEHOLDER_PHONE : (string) $treffer[0];
            },
            $text,
        );
    }
}
