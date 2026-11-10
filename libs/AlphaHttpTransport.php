<?php

declare(strict_types=1);

// Einziger Netzzugriff der Bibliothek. Der Prüfstand lädt vorher eine Attrappe gleichen Namens.
if (!class_exists('AlphaHttpTransport')) {
    final class AlphaHttpTransport
    {
        /**
         * @param list<string> $headers
         * @return array{status:int,body:string,error:string} status 0 bei Verbindungsfehler
         */
        public static function send(string $method, string $url, array $headers, ?string $body, int $timeoutSec): array
        {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT        => $timeoutSec,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            return ['status' => $response === false ? 0 : $status, 'body' => is_string($response) ? $response : '', 'error' => $error];
        }
    }
}
