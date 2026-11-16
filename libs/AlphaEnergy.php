<?php

declare(strict_types=1);

/*
 * Werte, die ohne Cloud fehlen, aus den Modbus-Zählern errechnet. Reine Logik ohne Symcon.
 * Die Anlage nutzt sie nur, wenn kein Cloud-Zugang eingerichtet ist; sonst liefert die Cloud.
 */
final class AlphaEnergy
{
    /** Zählerstände in kWh, die jede Rechnung braucht */
    public const COUNTERS = ['grid_consumption_energy', 'grid_feed_in_energy', 'pv_energy', 'battery_charge_energy', 'battery_discharge_energy'];

    /** Zähler => Tageswert und Gerät, an das er geht */
    private const TODAY = [
        'pv_energy'                => ['pv_energy_today', 'inverter'],
        'grid_feed_in_energy'      => ['grid_feed_in_today', 'inverter'],
        'grid_consumption_energy'  => ['grid_consumption_today', 'inverter'],
        'battery_charge_energy'    => ['battery_charge_today', 'battery'],
        'battery_discharge_energy' => ['battery_discharge_today', 'battery'],
        'battery_grid_charge_energy' => ['battery_grid_charge_today', 'battery'],
    ];

    /** Zähler, die nicht jede Anlage liefert; ohne sie fehlt nur ihr Tageswert */
    private const OPTIONAL = ['battery_grid_charge_energy'];

    /**
     * Nur vollständige, positive Zählerstände; ein Lesefehler liefert sonst 0 und verdirbt jede Rechnung.
     * @param array<string,mixed> $values
     * @return array<string,float>|null
     */
    public static function counters(array $values): ?array
    {
        $out = [];
        foreach (self::COUNTERS as $key) {
            if (!is_numeric($values[$key] ?? null) || (float) $values[$key] <= 0.0) {
                return null;
            }
            $out[$key] = (float) $values[$key];
        }
        foreach (self::OPTIONAL as $key) {
            if (is_numeric($values[$key] ?? null) && (float) $values[$key] > 0.0) {
                $out[$key] = (float) $values[$key];
            }
        }
        return $out;
    }

    /**
     * Gesamtverbrauch als Energiebilanz und die Quoten über die ganze Laufzeit.
     * Verbrauch = Bezug − Einspeisung + PV-Ertrag + Entladen − Laden; die Verluste von Wechselrichter
     * und Speicher zählen dabei als Verbrauch mit.
     * @param array<string,float> $c aus counters()
     * @return array<string,float>
     */
    public static function totals(array $c): array
    {
        $load = $c['grid_consumption_energy'] - $c['grid_feed_in_energy'] + $c['pv_energy']
            + $c['battery_discharge_energy'] - $c['battery_charge_energy'];
        $out = [];
        if ($load > 0.0) {
            $out['load_energy'] = round($load, 2);
            // Autarkie: Anteil des Verbrauchs, der nicht aus dem Netz kam
            $out['self_sufficiency'] = round(max(0.0, min(100.0, ($load - $c['grid_consumption_energy']) / $load * 100)), 1);
        }
        // Eigenverbrauch: Anteil des PV-Ertrags, der nicht eingespeist wurde
        $out['self_consumption'] = round(max(0.0, min(100.0, ($c['pv_energy'] - $c['grid_feed_in_energy']) / $c['pv_energy'] * 100)), 1);
        return $out;
    }

    /**
     * Verbrauch, Eigenverbrauch und Autarkie des Tages aus den fünf Tageswerten (Modbus oder Cloud).
     * Eine Quote fehlt, solange ihr Nenner 0 ist (morgens vor dem ersten PV-Ertrag).
     * @param array<string,mixed> $t pv_energy_today, grid_feed_in_today, grid_consumption_today, battery_charge_today, battery_discharge_today
     * @return array<string,float>
     */
    public static function todayTotals(array $t): array
    {
        $keys = ['pv_energy_today', 'grid_feed_in_today', 'grid_consumption_today', 'battery_charge_today', 'battery_discharge_today'];
        foreach ($keys as $key) {
            if (!is_numeric($t[$key] ?? null)) {
                return [];
            }
        }
        $pv = (float) $t['pv_energy_today'];
        $feedIn = (float) $t['grid_feed_in_today'];
        $grid = (float) $t['grid_consumption_today'];
        $load = max(0.0, $grid - $feedIn + $pv + (float) $t['battery_discharge_today'] - (float) $t['battery_charge_today']);
        $out = ['load_energy_today' => round($load, 2)];
        if ($pv > 0.0) {
            $out['self_consumption_today'] = round(max(0.0, min(100.0, ($pv - $feedIn) / $pv * 100)), 1);
        }
        if ($load > 0.0) {
            $out['self_sufficiency_today'] = round(max(0.0, min(100.0, ($load - $grid) / $load * 100)), 1);
        }
        return $out;
    }

    /**
     * Tageswerte als Abstand zum ersten Zählerstand des Tages. Ein neuer Tag oder ein zurückgesprungener
     * Zähler setzt den Startstand neu.
     * @param array<string,float> $c aus counters()
     * @param array<string,mixed> $start bisheriger Startstand {date, counters}
     * @return array{0:array{inverter:array<string,float>,battery:array<string,float>},1:array{date:string,counters:array<string,float>}}
     */
    public static function today(array $c, array $start, string $date): array
    {
        $base = ($start['date'] ?? '') === $date && is_array($start['counters'] ?? null) ? $start['counters'] : [];
        foreach ($c as $key => $value) {
            if (!is_numeric($base[$key] ?? null) || $value < (float) $base[$key]) {
                $base[$key] = $value;
            }
        }
        $out = ['inverter' => [], 'battery' => []];
        foreach (self::TODAY as $key => [$name, $kind]) {
            if (isset($c[$key])) {
                $out[$kind][$name] = round($c[$key] - (float) $base[$key], 2);
            }
        }
        return [$out, ['date' => $date, 'counters' => $base]];
    }
}
