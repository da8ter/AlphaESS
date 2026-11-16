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

    /**
     * Mehrere Register schreiben (FC 16). Gemessen 16.11.2026 (Symcon 9.1): Data als Hex-String der
     * Registerwerte; ein Binärstring kommt verstümmelt am Gerät an.
     * @param list<int> $values
     */
    public static function write(int $address, array $values): string
    {
        $data = '';
        foreach ($values as $value) {
            $data .= sprintf('%04X', $value & 0xFFFF);
        }
        return (string) json_encode([
            'DataID'   => AlphaDataFlow::MODBUS_TX,
            'Function' => 16,
            'Address'  => $address,
            'Quantity' => count($values),
            'Data'     => $data,
        ]);
    }

    /** Bestätigt das Gerät das Schreiben? Antwort: Modbus-Echo „16, Adresse, Anzahl“ (binär, Rückfall hex). */
    public static function written(mixed $response, int $address, int $quantity): bool
    {
        if (!is_string($response)) {
            return false;
        }
        $echo = pack('Cnn', 16, $address, $quantity);
        return $response === $echo || strcasecmp($response, bin2hex($echo)) === 0;
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
