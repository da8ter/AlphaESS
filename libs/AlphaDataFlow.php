<?php

declare(strict_types=1);

// GUIDs und Paketnamen des Datenflusses zwischen dem System (Splitter) und seinen Geräten.
final class AlphaDataFlow
{
    /** Paket an das Symcon ModBus Gateway (Function, Address, Quantity, Data) */
    public const MODBUS_TX = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
    public const MODBUS_GATEWAY = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';

    /** Geräte → System (Action + Parameter), Antwort als JSON */
    public const DEVICE_TX = '{D47CC3DD-E236-42BE-A84E-5C7D88033D0A}';
    /** System → Geräte (Kind, Serial, Values) */
    public const DEVICE_RX = '{F0D93D9B-AD5D-4D1D-A5FA-BDDEFA7E1860}';

    public const MODULE_SYSTEM = '{9421C388-104F-42B7-8ED6-3C3853FE6FB2}';
    public const MODULE_INVERTER = '{2C9BAF85-8785-4A1B-BB25-F4A631250392}';
    public const MODULE_BATTERY = '{DEA587B6-0305-4D97-8884-52B36C03BF11}';
    public const MODULE_WALLBOX = '{A7663357-3AD8-42FC-A19F-AF89BED95173}';

    public const KIND_INVERTER = 'inverter';
    public const KIND_BATTERY = 'battery';
    public const KIND_WALLBOX = 'wallbox';

    /** @param array<string,mixed> $values */
    public static function toChildren(string $kind, string $serial, array $values): string
    {
        return (string) json_encode(['DataID' => self::DEVICE_RX, 'Kind' => $kind, 'Serial' => $serial, 'Values' => $values]);
    }

    /** @param array<string,mixed> $params */
    public static function toSystem(string $action, array $params = []): string
    {
        return (string) json_encode(['DataID' => self::DEVICE_TX, 'Action' => $action] + $params);
    }
}
