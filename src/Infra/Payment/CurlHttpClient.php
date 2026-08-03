<?php

declare(strict_types=1);

namespace Reptilienmarkt\Infra\Payment;

use Reptilienmarkt\Domain\Billing\BillingException;

final readonly class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeoutSeconds = 15) {}

    public function post(string $url, string $body, array $headers = []): string
    {
        if (!\function_exists('curl_init')) {
            throw new BillingException('Für den Zahlungsverkehr fehlt die cURL-Erweiterung.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new BillingException('Die Verbindung zum Zahlungsanbieter ließ sich nicht aufbauen.');
        }

        $formatted = [];
        foreach ($headers as $name => $value) {
            $formatted[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $formatted,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_TIMEOUT => $this->timeoutSeconds,
            // Zertifikatspruefung bleibt an. Wer sie im Zahlungsweg abschaltet,
            // hat das Problem nicht geloest, sondern unsichtbar gemacht.
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($handle);
        $error = curl_error($handle);
        curl_close($handle);

        if (!\is_string($response) || $error !== '') {
            throw new BillingException('Der Zahlungsanbieter war nicht erreichbar: ' . $error);
        }

        return $response;
    }
}
