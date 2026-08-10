<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Mail;

use Reptilienmarkt\Domain\Mail\Mailer;
use Reptilienmarkt\Domain\Mail\MailMessage;
use Reptilienmarkt\Domain\Notification\NotificationChannel;
use Reptilienmarkt\Domain\Notification\NotificationPreferenceService;
use Reptilienmarkt\Support\Translator;

/**
 * Steht vor dem Postausgang und entscheidet, ob ueberhaupt eingereiht wird.
 *
 * Ein Umschlag um den Mailer statt einer Abfrage in jedem Dienst: Die Frage
 * "darf ich das schreiben?" gehoert an genau eine Stelle, sonst wird sie beim
 * naechsten neuen Mailanlass vergessen — und die Plattform schreibt jemandem,
 * der das abbestellt hat.
 *
 * Wer durchdarf, bekommt zusaetzlich den Abmeldelink im Text und einen
 * List-Unsubscribe-Kopf. Der Kopf ist der Weg, den Mailprogramme selbst
 * anbieten; der Link im Text der, den ein Mensch findet. Beide zeigen auf
 * dieselbe Adresse.
 */
final readonly class PreferenceAwareMailer implements Mailer
{
    /**
     * @param string $unsubscribeMailbox Postfach fuer Abmeldungen per Mail —
     *                                   leer, wenn keines betreut wird. Ein
     *                                   toter mailto: waere schlechter als
     *                                   keiner: Der Nutzer schriebe ins Leere
     *                                   und hielte sich fuer abgemeldet.
     */
    public function __construct(
        private Mailer $inner,
        private NotificationPreferenceService $preferences,
        private Translator $translator,
        private string $appUrl = 'https://example.tld',
        private string $unsubscribeMailbox = '',
    ) {}

    public function send(MailMessage $message): bool
    {
        $kanal = NotificationChannel::forPurpose($message->purpose);

        // Systempost und Mails ohne Konto — etwa die Kopie einer Kontaktanfrage
        // an den Betreiber — gehen unveraendert durch. Ein Abmeldelink haette
        // dort kein Konto, an dem er haengen koennte.
        if ($kanal->isMandatory() || $message->userId === null) {
            return $this->inner->send($message);
        }

        if (!$this->preferences->mayNotify($message->userId, $kanal)) {
            // Bewusst "true": Nicht schreiben zu duerfen ist kein Fehlschlag.
            // Ein "false" wuerde den Aufrufer eine Stoerung protokollieren
            // lassen, wo der Nutzer schlicht seinen Willen bekommen hat.
            return true;
        }

        $link = \sprintf(
            '%s/abmelden/%s?kanal=%s',
            rtrim($this->appUrl, '/'),
            rawurlencode($this->preferences->issueUnsubscribeToken($message->userId)),
            rawurlencode($kanal->value),
        );

        $adressen = ['<' . $link . '>'];

        if ($this->unsubscribeMailbox !== '') {
            $adressen[] = \sprintf(
                '<mailto:%s?subject=%s>',
                $this->unsubscribeMailbox,
                rawurlencode('Abmelden: ' . $kanal->value),
            );
        }

        return $this->inner->send($message->with(
            $message->body . "\n\n" . $this->translator->translate('mail.abmelden.hinweis', ['link' => $link]),
            [
                'List-Unsubscribe' => implode(', ', $adressen),
                // RFC 8058: Ohne diese Kopfzeile darf ein Mailprogramm gar
                // keinen Ein-Klick-Knopf anbieten — es oeffnet stattdessen die
                // Seite. Sie sagt zu, dass dieselbe Adresse einen POST
                // entgegennimmt und dass der auch wirklich abmeldet; deshalb
                // steht sie erst hier, seit /abmelden/{token} den POST kennt.
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        ));
    }
}
