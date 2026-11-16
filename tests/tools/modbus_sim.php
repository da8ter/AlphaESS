<?php

declare(strict_types=1);

/*
 * Modbus-TCP-Simulator einer Alpha-ESS-Anlage für Messungen gegen ein Test-Symcon.
 *
 *   php tests/tools/modbus_sim.php [Port=5020] [Modus=normal|silent|exception] [fehlende Register, z. B. 1040-1045,1060]
 *
 * normal:    beantwortet FC 3 aus der Registerkarte, Lücken mit Exception 2
 * silent:    nimmt Anfragen an und antwortet nie (Timeout-Messung)
 * exception: beantwortet jede Anfrage mit Exception 2
 * Das dritte Argument nimmt Register aus der Karte heraus (prüft die Bereichssuche von modbus_scan.php).
 * Jede Anfrage wird protokolliert (Geräte-ID, Funktion, Adresse, Anzahl).
 */

$port = (int) ($argv[1] ?? 5020);
$mode = (string) ($argv[2] ?? 'normal');

/** @var array<int,int> $reg Adresse => 16-Bit-Wert */
$reg = require __DIR__ . '/../fixtures/registers.php';
foreach (array_filter(explode(',', (string) ($argv[3] ?? ''))) as $gap) {
    [$from, $to] = array_map('intval', explode('-', $gap, 2)) + [1 => (int) $gap];
    for ($address = $from; $address <= $to; $address++) {
        unset($reg[$address]);
    }
}

$server = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Start fehlgeschlagen: $errstr\n");
    exit(1);
}
echo "Simulator auf Port $port, Modus $mode\n";
$clients = [];
$buffers = [];
while (true) {
    $read = array_merge([$server], $clients);
    $write = $except = null;
    if (stream_select($read, $write, $except, 1) < 1) {
        continue;
    }
    foreach ($read as $s) {
        if ($s === $server) {
            $c = stream_socket_accept($server, 0);
            if ($c !== false) {
                $clients[(int) $c] = $c;
                $buffers[(int) $c] = '';
                echo date('H:i:s') . ' Verbindung von ' . stream_socket_get_name($c, true) . "\n";
            }
            continue;
        }
        $data = fread($s, 4096);
        if ($data === '' || $data === false) {
            echo date('H:i:s') . " Verbindung getrennt\n";
            fclose($s);
            unset($clients[(int) $s], $buffers[(int) $s]);
            continue;
        }
        $buffers[(int) $s] .= $data;
        while (strlen($buffers[(int) $s]) >= 8) {
            $h = unpack('ntid/npid/nlen/Cunit/Cfc', $buffers[(int) $s]);
            $total = 6 + $h['len'];
            if (strlen($buffers[(int) $s]) < $total) {
                break;
            }
            $pdu = substr($buffers[(int) $s], 8, $total - 8);
            $buffers[(int) $s] = substr($buffers[(int) $s], $total);
            $a = strlen($pdu) >= 4 ? unpack('naddr/nqty', $pdu) : ['addr' => -1, 'qty' => 0];
            echo date('H:i:s') . sprintf(" Anfrage unit=%d fc=%d addr=%d qty=%d roh=%s\n", $h['unit'], $h['fc'], $a['addr'], $a['qty'], bin2hex($pdu));
            if ($mode === 'silent') {
                continue;
            }
            // Schreiben: FC 6 (ein Register, Wert statt Anzahl) und FC 16 (mehrere); Antwort wie im Standard
            if ($mode === 'normal' && ($h['fc'] === 6 || $h['fc'] === 16)) {
                $values = $h['fc'] === 6 ? [$a['qty']] : array_values(unpack('n*', substr($pdu, 5)) ?: []);
                foreach ($values as $i => $value) {
                    $reg[$a['addr'] + $i] = $value;
                }
                echo date('H:i:s') . ' geschrieben ab ' . $a['addr'] . ': ' . implode(',', $values) . "\n";
                $body = substr($pdu, 0, 4);
                fwrite($s, pack('nnnCC', $h['tid'], 0, strlen($body) + 2, $h['unit'], $h['fc']) . $body);
                continue;
            }
            $ok = $mode === 'normal' && $h['fc'] === 3 && $a['qty'] >= 1 && $a['qty'] <= 125;
            $payload = '';
            for ($i = 0; $ok && $i < $a['qty']; $i++) {
                if (!isset($reg[$a['addr'] + $i])) {
                    $ok = false;
                    break;
                }
                $payload .= pack('n', $reg[$a['addr'] + $i]);
            }
            $body = $ok ? pack('CC', 3, strlen($payload)) . $payload : pack('CC', $h['fc'] | 0x80, 2);
            fwrite($s, pack('nnnC', $h['tid'], 0, strlen($body) + 1, $h['unit']) . $body);
        }
    }
}
