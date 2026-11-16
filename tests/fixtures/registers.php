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
    // Netz, erweitert 0x0023–0x0036
    $u32(41, -120);        // Blindleistung gesamt
    $u32(49, 560);         // Scheinleistung gesamt
    $u16(54, -95);         // Leistungsfaktor -0,95
    $fill(35, 54);
    // PV-Zähler 0x00A1
    $u32(161, 0);
    // Speicher 0x0100–0x0127
    $u16(256, 512);        // 51,2 V
    $u16(257, -105);       // -10,5 A
    $u16(258, 655);        // 65,5 %
    $u16(259, 2);          // Speicherstatus
    $u16(263, 3312);       // 3,312 V kleinste Zelle
    $u16(266, 3345);       // 3,345 V größte Zelle
    $u16(269, -15);        // -1,5 °C kälteste Zelle
    $u16(272, 214);        // 21,4 °C wärmste Zelle
    $u16(280, 3);          // drei Module
    $u16(281, 151);        // 15,1 kWh
    $u16(283, 987);        // SOH 98,7 %
    $u32(292, 456);        // 45,6 kWh aus dem Netz geladen
    $u32(288, 4321);       // 432,1 kWh geladen
    $u32(290, 3210);       // 321,0 kWh entladen
    $u16(294, -1500);      // negativ = Laden
    $fill(256, 295);
    // Wechselrichter 0x0400–0x0440
    $u16(1024, 2311);      // 231,1 V
    $u16(1027, -35);       // -3,5 A
    $u32(1050, 0);         // Notstrom gesamt
    $u32(1030, 800);
    $u32(1032, 700);
    $u32(1034, 500);
    $u32(1036, 2000);
    $u16(1053, 3871);      // PV1 387,1 V
    $u16(1054, 46);        // PV1 4,6 A
    $u32(1055, 1800);      // PV1
    $u16(1057, 4022);      // PV2 402,2 V
    $u32(1059, 1700);      // PV2
    $u16(1077, 382);       // 38,2 °C
    $u32(1086, 98765);     // 9876,5 kWh
    $u16(1088, 2);
    $fill(1024, 1088);
    // Einstellungen 0x0800–0x0804
    $u16(2048, 70);        // Einspeisung höchstens 70 %
    $u32(2049, 9800);      // 9,8 kWp PV
    $u32(2051, 0);
    $fill(2048, 2052);
    // Zeitsteuerung 0x084F–0x0859
    $u16(2127, 1);         // Laden zeitgesteuert
    $u16(2128, 10);        // Reserve 10 % (ganze Prozent, an einer Anlage gemessen)
    $u16(2133, 90);        // Laden bis 90 %
    $u16(2134, 1);         // Laden 01–05 Uhr
    $u16(2135, 5);
    $fill(2127, 2137);
    // System 0x08D0–0x08D5
    $u32(2258, 99000);     // 9900,0 kWh
    $fill(2256, 2261);

    return $reg;
})();
