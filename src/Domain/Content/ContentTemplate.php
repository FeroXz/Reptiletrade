<?php

declare(strict_types=1);

namespace Reptilienmarkt\Domain\Content;

/**
 * Die Allowlist der Vorlagen.
 *
 * Ein freies Textfeld waere ein Dateipfad aus Nutzereingabe — und damit ein
 * Weg, jede Datei im Templateverzeichnis zu rendern. Templates sind Code; wer
 * eine neue Vorlage braucht, legt sie an und ergaenzt diesen Aufzaehlungstyp.
 * Einen Vorlageneditor im Browser gibt es ausdruecklich nicht.
 */
enum ContentTemplate: string
{
    case Standard = 'standard';
    case Breit = 'breit';
    case Beitrag = 'beitrag';

    public function label(): string
    {
        return match ($this) {
            self::Standard => 'Standard',
            self::Breit => 'Volle Breite',
            self::Beitrag => 'Beitrag',
        };
    }

    public function templateFile(): string
    {
        return match ($this) {
            self::Standard => 'inhalt/seite.html.twig',
            self::Breit => 'inhalt/seite_breit.html.twig',
            self::Beitrag => 'inhalt/beitrag.html.twig',
        };
    }

    public static function forType(ContentType $type): self
    {
        return $type === ContentType::Beitrag ? self::Beitrag : self::Standard;
    }
}
