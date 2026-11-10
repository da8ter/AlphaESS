<?php

declare(strict_types=1);

/*
 * Paket an das Symcon ModBus Gateway und Auswertung seiner Antwort.
 * Gemessen 10.11.2026 (Symcon 9.1, IPSModuleStrict-Kind): Die Antwort kommt synchron als
 * Binärstring „Funktionscode, Byteanzahl, Daten“ (2 + 2n Byte), die Adresse geht unverändert
 * aufs Telegramm. Fehler, Timeout und geschlossener Socket liefern false.
 */
final class AlphaModbusGateway
{
    public static function request(int $address, int $quantity): string
    {
        return (string) json_encode([
            'DataID'   => AlphaDataFlow::MODBUS_TX,
            'Function' => 3,
            'Address'  => $address,
            'Quantity' => $quantity,
            'Data'     => '',
        ]);
    }

    /** @return string|null Registerinhalt (2n Byte) oder null bei Fehler oder unpassender Länge */
    public static function payload(mixed $response, int $quantity): ?string
    {
        if (!is_string($response) || $response === '') {
            return null;
        }
        $expected = 2 + 2 * $quantity;
        if (strlen($response) === 2 * $expected && ctype_xdigit($response)) {
            // Rückfall, falls eine Symcon-Version die Antwort wie das Feld Buffer hex-kodiert
            $response = (string) hex2bin($response);
        }
        return strlen($response) === $expected ? substr($response, 2) : null;
    }
}
