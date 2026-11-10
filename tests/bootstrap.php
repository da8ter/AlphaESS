<?php

declare(strict_types=1);

/*
 * Prüfstand Alpha ESS: die echten Module auf dem Symcon-Kern im Speicher der Nachbarbibliothek
 * LGThinQ (tests/sdk, Verhalten von Symcon 9.1 gemessen), dazu eine Gateway- und eine
 * Cloud-Attrappe. Kein Symcon, kein Netz, keine Anlage. Alles zusammen: tests/run.sh
 */

$sdk = __DIR__ . '/../../LGThinQ/tests/bootstrap.php';
if (!is_file($sdk)) {
    fwrite(STDERR, "Prüfstand-Kern fehlt: $sdk\n");
    exit(1);
}
require $sdk;

// Vor der Bibliothek laden: die class_exists-Wächter in libs/ behalten dann diese Klassen.
require_once __DIR__ . '/fake/AlphaClock.php';
require_once __DIR__ . '/fake/FakeAlphaCloud.php';
require_once __DIR__ . '/fake/AlphaHttpTransport.php';
require_once __DIR__ . '/fake/FakeModbusGateway.php';

// Semaphoren ohne Wiedereintritt wie in Symcon: wer eine gehaltene Sperre erneut nimmt, scheitert.
final class FakeSemaphores
{
    /** @var array<string,bool> */
    public static array $held = [];
}

if (!function_exists('IPS_SemaphoreEnter')) {
    function IPS_SemaphoreEnter(string $Name, int $Milliseconds): bool
    {
        if (isset(FakeSemaphores::$held[$Name])) {
            return false;
        }
        FakeSemaphores::$held[$Name] = true;
        return true;
    }

    function IPS_SemaphoreLeave(string $Name): bool
    {
        unset(FakeSemaphores::$held[$Name]);
        return true;
    }
}

const ALPHA_LIB = __DIR__ . '/..';
const ALPHA_SYSTEM = '{9421C388-104F-42B7-8ED6-3C3853FE6FB2}';
const ALPHA_CONFIGURATOR = '{8D5045B3-2AC0-4AFD-B2DF-F85131243B9A}';
const ALPHA_INVERTER = '{2C9BAF85-8785-4A1B-BB25-F4A631250392}';
const ALPHA_BATTERY = '{DEA587B6-0305-4D97-8884-52B36C03BF11}';
const ALPHA_WALLBOX = '{A7663357-3AD8-42FC-A19F-AF89BED95173}';

/**
 * Frischer Kern mit Bibliothek, Gateway-Attrappe und einem System daran.
 * @param array<string,mixed> $props Eigenschaften des Systems
 * @return array{0:int,1:FakeModbusGateway} [System-ID, Gateway]
 */
function alphaWorld(array $props = [], bool $withGateway = true): array
{
    Kernel::reset();
    FakeSemaphores::$held = [];
    FakeAlphaCloud::$current = null;
    Kernel::loadLibrary(ALPHA_LIB);
    $gateway = new FakeModbusGateway();
    $gatewayId = $gateway->install();
    $system = Kernel::createInstance(ALPHA_SYSTEM);
    if ($withGateway) {
        Kernel::$instances[$system]['connection'] = $gatewayId;
    }
    foreach ($props as $name => $value) {
        IPS_SetProperty($system, $name, $value);
    }
    IPS_ApplyChanges($system);
    return [$system, $gateway];
}

/** Gerät am System anlegen. @param array<string,mixed> $props */
function alphaDevice(string $moduleId, int $system, array $props = []): int
{
    $id = Kernel::createInstance($moduleId);
    Kernel::$instances[$id]['connection'] = $system;
    foreach ($props as $name => $value) {
        IPS_SetProperty($id, $name, $value);
    }
    IPS_ApplyChanges($id);
    return $id;
}

/**
 * Schaltet eine Variable wie Symcon (gemessen 10.11.2026): gibt die Aktion Text aus, ist sie gescheitert.
 * @return array{ok:bool,message:string}
 */
function alphaAction(int $instance, string $ident, mixed $value): array
{
    ob_start();
    $ok = RequestAction(World::varId($instance, $ident), $value);
    $message = (string) ob_get_clean();
    return ['ok' => $ok && $message === '', 'message' => $message];
}

/** Cloud-Attrappe mit passenden Zugangsdaten am System einrichten. */
function alphaCloud(int $system): FakeAlphaCloud
{
    $cloud = new FakeAlphaCloud();
    FakeAlphaCloud::$current = $cloud;
    IPS_SetProperty($system, 'AppID', $cloud->appId);
    IPS_SetProperty($system, 'AppSecret', $cloud->secret);
    IPS_SetProperty($system, 'SystemSN', $cloud->sysSn);
    IPS_ApplyChanges($system);
    return $cloud;
}
