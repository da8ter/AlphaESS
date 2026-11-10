<?php

declare(strict_types=1);

// Ersetzt den Netzzugriff: jede Anfrage geht an die Cloud-Attrappe.
final class AlphaHttpTransport
{
    /**
     * @param list<string> $headers
     * @return array{status:int,body:string,error:string}
     */
    public static function send(string $method, string $url, array $headers, ?string $body, int $timeoutSec): array
    {
        $cloud = FakeAlphaCloud::$current;
        if ($cloud === null) {
            return ['status' => 0, 'body' => '', 'error' => 'Prüfstand: keine Cloud angelegt'];
        }
        return $cloud->handle($method, $url, $headers, $body);
    }
}
