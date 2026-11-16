<?php

declare(strict_types=1);

/*
 * Registertabelle der Alpha-ESS-Anlage (Holding Register, FC 3, Big-Endian, Geräte-ID 85)
 * und die Dekodierung der gelesenen Blöcke. Reine Logik ohne Symcon.
 * Adressen und Typen nach der „AlphaESS Register Parameter List“.
 */
final class AlphaRegisters
{
    /**
     * Zusammenhängende Lesebereiche. Die ersten vier kennt jede Anlage; die übrigen kamen später dazu
     * und stehen hinten, damit ein Bereich, den eine Firmware nicht kennt, die übrigen nicht aufhält.
     * @var array<string,array{address:int,quantity:int}>
     */
    public const BLOCKS = [
        'inverter' => ['address' => 1024, 'quantity' => 65],
        'battery'  => ['address' => 256, 'quantity' => 40],
        'grid'     => ['address' => 16, 'quantity' => 19],
        'pvmeter'  => ['address' => 161, 'quantity' => 2],
        'gridext'  => ['address' => 35, 'quantity' => 20],
        'settings' => ['address' => 2048, 'quantity' => 5],
        'windows'  => ['address' => 2127, 'quantity' => 11],
        'system'   => ['address' => 2256, 'quantity' => 6],
    ];

    /** @var array<string,array<string,array{0:int,1:string,2:float}>> Block => Wert => [Adresse, Typ, Faktor] */
    private const MAP = [
        'grid' => [
            'grid_feed_in_energy'     => [16, 'u32', 0.01],
            'grid_consumption_energy' => [18, 'u32', 0.01],
            'grid_voltage_l1'         => [20, 'u16', 0.1],
            'grid_voltage_l2'         => [21, 'u16', 0.1],
            'grid_voltage_l3'         => [22, 'u16', 0.1],
            'grid_current_l1'         => [23, 'i16', 0.1],
            'grid_current_l2'         => [24, 'i16', 0.1],
            'grid_current_l3'         => [25, 'i16', 0.1],
            'grid_frequency_raw'      => [26, 'u16', 1.0],
            'grid_power_l1'           => [27, 'i32', 1.0],
            'grid_power_l2'           => [29, 'i32', 1.0],
            'grid_power_l3'           => [31, 'i32', 1.0],
            'grid_power'              => [33, 'i32', 1.0],
        ],
        'gridext' => [
            'grid_reactive_power_l1' => [35, 'i32', 1.0],
            'grid_reactive_power_l2' => [37, 'i32', 1.0],
            'grid_reactive_power_l3' => [39, 'i32', 1.0],
            'grid_reactive_power'    => [41, 'i32', 1.0],
            'grid_apparent_power_l1' => [43, 'i32', 1.0],
            'grid_apparent_power_l2' => [45, 'i32', 1.0],
            'grid_apparent_power_l3' => [47, 'i32', 1.0],
            'grid_apparent_power'    => [49, 'i32', 1.0],
            'grid_power_factor_l1'   => [51, 'i16', 0.01],
            'grid_power_factor_l2'   => [52, 'i16', 0.01],
            'grid_power_factor_l3'   => [53, 'i16', 0.01],
            'grid_power_factor'      => [54, 'i16', 0.01],
        ],
        'pvmeter' => [
            'pv_meter_power' => [161, 'i32', 1.0],
        ],
        'battery' => [
            'battery_voltage'             => [256, 'u16', 0.1],
            'battery_current'             => [257, 'i16', 0.1],
            'battery_soc'                 => [258, 'u16', 0.1],
            'battery_status'              => [259, 'u16', 1.0],
            'battery_min_cell_voltage'    => [263, 'u16', 0.001],
            'battery_max_cell_voltage'    => [266, 'u16', 0.001],
            'battery_min_cell_temperature' => [269, 'i16', 0.1],
            'battery_max_cell_temperature' => [272, 'i16', 0.1],
            'battery_modules'             => [280, 'u16', 1.0],
            'battery_capacity'            => [281, 'u16', 0.1],
            'battery_soh'                 => [283, 'u16', 0.1],
            'battery_warning'             => [284, 'u32', 1.0],
            'battery_fault'               => [286, 'u32', 1.0],
            'battery_charge_energy'       => [288, 'u32', 0.1],
            'battery_discharge_energy'    => [290, 'u32', 0.1],
            'battery_grid_charge_energy'  => [292, 'u32', 0.1],
            'battery_power'               => [294, 'i16', 1.0],
        ],
        'inverter' => [
            'inverter_voltage_l1' => [1024, 'u16', 0.1],
            'inverter_voltage_l2' => [1025, 'u16', 0.1],
            'inverter_voltage_l3' => [1026, 'u16', 0.1],
            'inverter_current_l1' => [1027, 'i16', 0.1],
            'inverter_current_l2' => [1028, 'i16', 0.1],
            'inverter_current_l3' => [1029, 'i16', 0.1],
            'inverter_power_l1'   => [1030, 'i32', 1.0],
            'inverter_power_l2'   => [1032, 'i32', 1.0],
            'inverter_power_l3'   => [1034, 'i32', 1.0],
            'inverter_power'      => [1036, 'i32', 1.0],
            'backup_voltage_l1'   => [1038, 'u16', 0.1],
            'backup_voltage_l2'   => [1039, 'u16', 0.1],
            'backup_voltage_l3'   => [1040, 'u16', 0.1],
            'backup_current_l1'   => [1041, 'u16', 0.1],
            'backup_current_l2'   => [1042, 'u16', 0.1],
            'backup_current_l3'   => [1043, 'u16', 0.1],
            'backup_power_l1'     => [1044, 'i32', 1.0],
            'backup_power_l2'     => [1046, 'i32', 1.0],
            'backup_power_l3'     => [1048, 'i32', 1.0],
            'backup_power'        => [1050, 'i32', 1.0],
            // je String vier Register: Spannung (0,1 V), Strom (0,1 A), Leistung (32 Bit)
            'pv1_voltage'    => [1053, 'u16', 0.1],
            'pv1_current'    => [1054, 'u16', 0.1],
            'pv1_power'      => [1055, 'u32', 1.0],
            'pv2_voltage'    => [1057, 'u16', 0.1],
            'pv2_current'    => [1058, 'u16', 0.1],
            'pv2_power'      => [1059, 'u32', 1.0],
            'pv3_voltage'    => [1061, 'u16', 0.1],
            'pv3_current'    => [1062, 'u16', 0.1],
            'pv3_power'      => [1063, 'u32', 1.0],
            'pv4_voltage'    => [1065, 'u16', 0.1],
            'pv4_current'    => [1066, 'u16', 0.1],
            'pv4_power'      => [1067, 'u32', 1.0],
            'pv5_voltage'    => [1069, 'u16', 0.1],
            'pv5_current'    => [1070, 'u16', 0.1],
            'pv5_power'      => [1071, 'u32', 1.0],
            'pv6_voltage'    => [1073, 'u16', 0.1],
            'pv6_current'    => [1074, 'u16', 0.1],
            'pv6_power'      => [1075, 'u32', 1.0],
            'inverter_temperature' => [1077, 'i16', 0.1],
            'inverter_warning1'    => [1078, 'u32', 1.0],
            'inverter_warning2'    => [1080, 'u32', 1.0],
            'inverter_fault1'      => [1082, 'u32', 1.0],
            'inverter_fault2'      => [1084, 'u32', 1.0],
            'pv_energy'      => [1086, 'u32', 0.1],
            'inverter_mode'  => [1088, 'u16', 1.0],
        ],
        'settings' => [
            'feed_in_limit'          => [2048, 'u16', 1.0],
            'pv_capacity'            => [2049, 'u32', 1.0],
            'pv_capacity_ac_coupled' => [2051, 'u32', 1.0],
        ],
        // Zeitsteuerung der Anlage; Zeiten in vollen Stunden laut Registerliste
        'windows' => [
            'window_control'          => [2127, 'u16', 1.0],
            'window_reserve_soc'      => [2128, 'u16', 0.1],
            'window_discharge_start1' => [2129, 'u16', 1.0],
            'window_discharge_end1'   => [2130, 'u16', 1.0],
            'window_discharge_start2' => [2131, 'u16', 1.0],
            'window_discharge_end2'   => [2132, 'u16', 1.0],
            'window_charge_limit'     => [2133, 'u16', 0.1],
            'window_charge_start1'    => [2134, 'u16', 1.0],
            'window_charge_end1'      => [2135, 'u16', 1.0],
            'window_charge_start2'    => [2136, 'u16', 1.0],
            'window_charge_end2'      => [2137, 'u16', 1.0],
        ],
        'system' => [
            'pv_energy_ac_coupled' => [2256, 'u32', 0.1],
            'system_pv_energy'     => [2258, 'u32', 0.1],
            'system_fault'         => [2260, 'u32', 1.0],
        ],
    ];

    private const BATTERY_KEYS = ['battery_voltage', 'battery_current', 'battery_soc', 'battery_charge_energy',
        'battery_discharge_energy', 'battery_power', 'battery_state', 'battery_status', 'battery_min_cell_voltage',
        'battery_max_cell_voltage', 'battery_min_cell_temperature', 'battery_max_cell_temperature', 'battery_modules',
        'battery_capacity', 'battery_soh', 'battery_warning', 'battery_fault', 'battery_grid_charge_energy',
        'window_control', 'window_reserve_soc', 'window_discharge_start1', 'window_discharge_end1', 'window_discharge_start2',
        'window_discharge_end2', 'window_charge_limit', 'window_charge_start1', 'window_charge_end1', 'window_charge_start2',
        'window_charge_end2'];

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
            $values[$name] = $factor === 1.0 ? $raw : round($raw * $factor, 3);
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
