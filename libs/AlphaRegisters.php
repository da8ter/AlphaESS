<?php

declare(strict_types=1);

/*
 * Registertabelle der Alpha-ESS-Anlage (Holding Register, FC 3, Big-Endian, Geräte-ID 85)
 * und die Dekodierung der gelesenen Blöcke. Reine Logik ohne Symcon.
 * Adressen und Typen nach der „AlphaESS Register Parameter List“.
 */
final class AlphaRegisters
{
    /** @var array<string,array{address:int,quantity:int}> zusammenhängende Lesebereiche */
    public const BLOCKS = [
        'inverter' => ['address' => 1024, 'quantity' => 65],
        'battery'  => ['address' => 256, 'quantity' => 40],
        'grid'     => ['address' => 16, 'quantity' => 19],
        'pvmeter'  => ['address' => 161, 'quantity' => 2],
    ];

    /** @var array<string,array<string,array{0:int,1:string,2:float}>> Block => Wert => [Adresse, Typ, Faktor] */
    private const MAP = [
        'grid' => [
            'grid_feed_in_energy'     => [16, 'u32', 0.01],
            'grid_consumption_energy' => [18, 'u32', 0.01],
            'grid_frequency_raw'      => [26, 'u16', 1.0],
            'grid_power_l1'           => [27, 'i32', 1.0],
            'grid_power_l2'           => [29, 'i32', 1.0],
            'grid_power_l3'           => [31, 'i32', 1.0],
            'grid_power'              => [33, 'i32', 1.0],
        ],
        'pvmeter' => [
            'pv_meter_power' => [161, 'i32', 1.0],
        ],
        'battery' => [
            'battery_voltage'          => [256, 'u16', 0.1],
            'battery_current'          => [257, 'i16', 0.1],
            'battery_soc'              => [258, 'u16', 0.1],
            'battery_charge_energy'    => [288, 'u32', 0.1],
            'battery_discharge_energy' => [290, 'u32', 0.1],
            'battery_power'            => [294, 'i16', 1.0],
        ],
        'inverter' => [
            'inverter_power' => [1036, 'i32', 1.0],
            'pv1_power'      => [1055, 'u32', 1.0],
            'pv2_power'      => [1059, 'u32', 1.0],
            'pv3_power'      => [1063, 'u32', 1.0],
            'pv4_power'      => [1067, 'u32', 1.0],
            'pv5_power'      => [1071, 'u32', 1.0],
            'pv6_power'      => [1075, 'u32', 1.0],
            'pv_energy'      => [1086, 'u32', 0.1],
            'inverter_mode'  => [1088, 'u16', 1.0],
        ],
    ];

    private const BATTERY_KEYS = ['battery_voltage', 'battery_current', 'battery_soc', 'battery_charge_energy',
        'battery_discharge_energy', 'battery_power', 'battery_state'];

    /** Leistung in Watt, unter der der Speicher als ruhend gilt */
    private const IDLE_WATT = 30;

    /**
     * @param string $data Registerinhalt ohne Kopf, zwei Byte je Register
     * @return array<string,float|int> leer, wenn die Länge nicht zum Block passt
     */
    public static function decode(string $block, string $data): array
    {
        $def = self::BLOCKS[$block] ?? null;
        if ($def === null || strlen($data) !== $def['quantity'] * 2) {
            return [];
        }
        $values = [];
        foreach (self::MAP[$block] as $name => [$address, $type, $factor]) {
            $raw = self::number(substr($data, ($address - $def['address']) * 2, 4), $type);
            $values[$name] = $factor === 1.0 ? $raw : round($raw * $factor, 2);
        }
        return $values;
    }

    /**
     * Ergänzt die errechneten Werte eines Zyklus.
     * @param array<string,float|int> $v
     * @return array<string,float|int>
     */
    public static function derive(array $v): array
    {
        if (isset($v['grid_frequency_raw'])) {
            // Die Auflösung ist je nach Firmware 0,1 oder 0,01 Hz; 50 Hz liegen bei 500 bzw. 5000.
            $raw = (int) $v['grid_frequency_raw'];
            $v['grid_frequency'] = round($raw / ($raw > 1000 ? 100 : 10), 2);
            unset($v['grid_frequency_raw']);
        }
        if (isset($v['pv1_power'])) {
            $sum = (int) ($v['pv_meter_power'] ?? 0);
            for ($i = 1; $i <= 6; $i++) {
                $sum += (int) $v["pv{$i}_power"];
            }
            $v['pv_power'] = $sum;
        }
        if (isset($v['grid_power'], $v['inverter_power'])) {
            // Verbrauch = Netzbezug + AC-Abgabe des Wechselrichters + AC-gekoppelte Erzeugung
            $v['load_power'] = max(0, (int) $v['grid_power'] + (int) $v['inverter_power'] + (int) ($v['pv_meter_power'] ?? 0));
        }
        if (isset($v['battery_power'])) {
            $v['battery_state'] = self::batteryState((float) $v['battery_power']);
        }
        return $v;
    }

    /** Ruhend 0, lädt 1, entlädt 2; positive Leistung heißt entladen (wie am Modbus und in der Cloud). */
    public static function batteryState(float $power): int
    {
        return $power > self::IDLE_WATT ? 2 : ($power < -self::IDLE_WATT ? 1 : 0);
    }

    /**
     * @param array<string,float|int> $values
     * @return array{inverter:array<string,float|int>,battery:array<string,float|int>}
     */
    public static function split(array $values): array
    {
        $battery = array_intersect_key($values, array_flip(self::BATTERY_KEYS));
        return ['inverter' => array_diff_key($values, $battery), 'battery' => $battery];
    }

    private static function number(string $bytes, string $type): int
    {
        if ($type === 'u16' || $type === 'i16') {
            $n = unpack('n', substr($bytes, 0, 2))[1];
            return $type === 'i16' && $n >= 0x8000 ? $n - 0x10000 : $n;
        }
        $n = unpack('N', $bytes)[1];
        return $type === 'i32' && $n >= 0x80000000 ? $n - 0x100000000 : $n;
    }
}
