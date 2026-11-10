<?php

declare(strict_types=1);

/*
 * Registerkarte einer gedachten Anlage für Simulator und Gateway-Attrappe (Adresse => 16-Bit-Wert).
 * Stand: 3500 W PV (1800 + 1700), Speicher lädt mit 1500 W, 500 W Einspeisung, 1500 W Verbrauch.
 */
return (static function (): array {
    $reg = [];
    $u16 = static function (int $a, int $v) use (&$reg): void {
        $reg[$a] = $v & 0xFFFF;
    };
    $u32 = static function (int $a, int $v) use (&$reg): void {
        $reg[$a] = ($v >> 16) & 0xFFFF;
        $reg[$a + 1] = $v & 0xFFFF;
    };
    $fill = static function (int $from, int $to) use (&$reg): void {
        for ($a = $from; $a <= $to; $a++) {
            $reg[$a] ??= 0;
        }
    };

    // Netzzähler 0x0010–0x0022
    $u32(16, 123456);      // Einspeisung 1234,56 kWh
    $u32(18, 234567);      // Bezug 2345,67 kWh
    $u16(20, 2301);
    $u16(21, 2302);
    $u16(22, 2303);
    $u16(23, 12);
    $u16(24, 13);
    $u16(25, 14);
    $u16(26, 5001);        // 50,01 Hz
    $u32(27, -400);        // L1
    $u32(29, 150);         // L2
    $u32(31, -250);        // L3
    $u32(33, -500);        // gesamt, negativ = Einspeisung
    // PV-Zähler 0x00A1
    $u32(161, 0);
    // Speicher 0x0100–0x0127
    $u16(256, 512);        // 51,2 V
    $u16(257, -105);       // -10,5 A
    $u16(258, 655);        // 65,5 %
    $u32(288, 4321);       // 432,1 kWh geladen
    $u32(290, 3210);       // 321,0 kWh entladen
    $u16(294, -1500);      // negativ = Laden
    $fill(256, 295);
    // Wechselrichter 0x0400–0x0440
    $u32(1030, 800);
    $u32(1032, 700);
    $u32(1034, 500);
    $u32(1036, 2000);
    $u32(1055, 1800);      // PV1
    $u32(1059, 1700);      // PV2
    $u32(1086, 98765);     // 9876,5 kWh
    $u16(1088, 2);
    $fill(1024, 1088);

    return $reg;
})();
