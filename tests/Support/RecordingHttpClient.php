<?php

declare(strict_types=1);

namespace Reptilienmarkt\Tests\Support;

use Reptilienmarkt\Domain\Billing\BillingException;
use Reptilienmarkt\Infra\Payment\HttpClient;

/**
 * Zeichnet auf, was gesendet worden waere, und antwortet mit vorbereiteten
 * Zeichenketten. Damit laesst sich die Anbieterumsetzung pruefen, ohne dass
 * ein Test je eine fremde Schnittstelle anruft.
 */
final class RecordingHttpClient implements HttpClient
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    private array $calls = [];

    /**
     * @param list<string> $responses
     */
    public function __construct(private array $responses = []) {}

    public function post(string $url, string $body, array $headers = []): string
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'headers' => $headers];

        $response = array_shift($this->responses);

        if ($response === null) {
            throw new BillingException('Keine Antwort vorbereitet.');
        }

        return $response;
    }

    /**
     * @return array{url: string, body: string, headers: array<string, string>}
     */
    public function lastCall(): array
    {
        if ($this->calls === []) {
            throw new BillingException('Es wurde nichts gesendet.');
        }

        return $this->calls[\count($this->calls) - 1];
    }

    /**
     * @return list<array{url: string, body: string, headers: array<string, string>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
