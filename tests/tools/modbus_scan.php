<?php

declare(strict_types=1);

/*
 * Liest eine echte Alpha-ESS-Anlage direkt per Modbus TCP aus, ohne Symcon. Nur Lesen (FC 3).
 *
 *   php tests/tools/modbus_scan.php <Host> [Port=502] [Geräte-ID=85]
 *
 * Zeigt je Block der Registertabelle, ob er sich in einem Stück lesen lässt, sonst die lesbaren
 * Teilbereiche, dazu die dekodierten Werte zum Vergleich mit der App und einige Rohwerte, deren
 * Typ in der Herstellerliste unklar ist. Die Anlage nimmt meist nur EINE Modbus-Verbindung an:
 * vorher sicherstellen, dass kein anderes System (Symcon, evcc) verbunden ist.
 */

require_once __DIR__ . '/../../libs/AlphaRegisters.php';

$host = (string) ($argv[1] ?? '');
$port = (int) ($argv[2] ?? 502);
$unit = (int) ($argv[3] ?? 85);
if ($host === '') {
    fwrite(STDERR, "Aufruf: php modbus_scan.php <Host> [Port=502] [Geräte-ID=85]\n");
    exit(2);
}

$socket = @fsockopen($host, $port, $errno, $errstr, 5);
if ($socket === false) {
    fwrite(STDERR, "Keine Verbindung zu $host:$port ($errstr)\n");
    exit(1);
}
stream_set_timeout($socket, 3);
$tid = 0;

/** @return string|int Registerinhalt (2n Byte), Modbus-Fehlercode oder 0 bei Zeitüberschreitung */
$read = static function (int $address, int $quantity) use ($socket, $unit, &$tid): string|int {
    $tid = ($tid + 1) & 0xFFFF;
    fwrite($socket, pack('nnnCCnn', $tid, 0, 6, $unit, 3, $address, $quantity));
    $head = (string) fread($socket, 9);
    if (strlen($head) < 9) {
        return 0;
    }
    $h = unpack('ntid/npid/nlen/Cunit/Cfc/Cbytes', $head);
    if ($h['fc'] & 0x80) {
        return $h['bytes']; // bei einem Fehler steht hier der Fehlercode
    }
    $data = '';
    while (strlen($data) < $h['bytes'] && !feof($socket)) {
        $chunk = fread($socket, $h['bytes'] - strlen($data));
        if ($chunk === false || $chunk === '') {
            return 0;
        }
        $data .= $chunk;
    }
    return $data;
};

/** Sucht die lesbaren Teilbereiche eines Blocks durch Halbieren. */
$ranges = static function (int $from, int $to) use (&$ranges, $read): array {
    if (is_string($read($from, $to - $from + 1))) {
        return [[$from, $to]];
    }
    if ($from === $to) {
        return [];
    }
    $mid = intdiv($from + $to, 2);
    $merged = [];
    foreach (array_merge($ranges($from, $mid), $ranges($mid + 1, $to)) as $range) {
        $last = count($merged) - 1;
        if ($last >= 0 && $merged[$last][1] + 1 === $range[0] && is_string($read($merged[$last][0], $range[1] - $merged[$last][0] + 1))) {
            $merged[$last][1] = $range[1];
        } else {
            $merged[] = $range;
        }
    }
    return $merged;
};

echo "Anlage $host:$port, Geräte-ID $unit\n\n== Blöcke\n";
$values = [];
foreach (AlphaRegisters::BLOCKS as $name => $block) {
    $start = microtime(true);
    $data = $read($block['address'], $block['quantity']);
    $ms = (int) round((microtime(true) - $start) * 1000);
    $end = $block['address'] + $block['quantity'] - 1;
    if (is_string($data)) {
        printf("%-9s %4d–%4d  in einem Stück lesbar (%d ms)\n", $name, $block['address'], $end, $ms);
        $values += AlphaRegisters::decode($name, $data);
        continue;
    }
    printf("%-9s %4d–%4d  NICHT lesbar (%s, %d ms)\n", $name, $block['address'], $end, $data === 0 ? 'keine Antwort' : 'Fehlercode ' . $data, $ms);
    if ($data !== 0) {
        foreach ($ranges($block['address'], $end) as [$a, $b]) {
            // Klammern nötig: ohne sie liest PHP den Gedankenstrich als Teil des Variablennamens.
            echo "          lesbar: {$a}–{$b}\n";
        }
    }
}

echo "\n== Werte (mit der App vergleichen; Netz + = Bezug, Speicher + = Entladen erwartet)\n";
foreach (AlphaRegisters::derive($values) as $name => $value) {
    printf("%-26s %s\n", $name, is_float($value) ? rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') : $value);
}

echo "\n== Rohwerte mit unklarem Typ\n";
foreach ([[26, 1, 'Netzfrequenz (0,1 oder 0,01 Hz)'], [1052, 1, 'Frequenz am Wechselrichter'], [1034, 2, 'Wechselrichter L3 (16 oder 32 Bit)'],
    [1063, 2, 'PV3-Leistung (16 oder 32 Bit)'], [1088, 1, 'Betriebsmodus'], [2258, 2, 'PV-Energie des Systems']] as [$address, $quantity, $label]) {
    $data = $read($address, $quantity);
    printf("%4d  %-36s %s\n", $address, $label, is_string($data) ? strtoupper(bin2hex($data)) : ($data === 0 ? 'keine Antwort' : 'Fehlercode ' . $data));
}
fclose($socket);
