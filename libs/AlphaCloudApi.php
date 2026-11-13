<?php

declare(strict_types=1);

/*
 * Alpha ESS Open API: Signatur, Anfragen je Auftrag und Auswertung der Antworten.
 * Reine Logik ohne Netz. Quelle der Endpunkte: Postman-Sammlung und Demo des Herstellers
 * (github.com/alphaess-developer/alphacloud_open_api), abgeglichen mit dem Handbuch im
 * Entwicklerportal (Stand 10.11.2026).
 */
require_once __DIR__ . '/AlphaRegisters.php';

final class AlphaCloudApi
{
    public const BASE_URL = 'https://openapi.alphaess.com/api';
    public const RATE_LIMITED = 6053;

    /** Codes, die auf falsche Zugangsdaten oder eine fehlende Bindung der Anlage hinweisen */
    private const AUTH_CODES = [6002, 6005, 6007, 6009, 6010, 6011, 6012, 6038];

    private const ERRORS = [
        6001 => 'Parameter error',
        6002 => 'The serial number is not bound to this account',
        6005 => 'The AppID is not bound to the serial number',
        6006 => 'Timestamp error, check the system time',
        6007 => 'Signature verification failed',
        6008 => 'Setting failed',
        6009 => 'IP whitelist verification failed',
        6010 => 'The signature is missing',
        6011 => 'The timestamp is missing',
        6012 => 'The AppID is missing',
        6016 => 'Data does not exist',
        6026 => 'Internal error of the Alpha ESS cloud',
        6029 => 'Operation failed',
        6038 => 'The serial number does not exist',
        6042 => 'The system is offline',
        6053 => 'Too many requests',
    ];

    /** @var array<string,array<string,string>> neutraler Name => Feld der Cloud */
    public const CONFIG_FIELDS = [
        'charge' => [
            'grid_charge'   => 'gridCharge',
            'charge_limit'  => 'batHighCap',
            'charge_start1' => 'timeChaf1',
            'charge_end1'   => 'timeChae1',
            'charge_start2' => 'timeChaf2',
            'charge_end2'   => 'timeChae2',
        ],
        'discharge' => [
            'discharge_control' => 'ctrDis',
            'discharge_limit'   => 'batUseCap',
            'discharge_start1'  => 'timeDisf1',
            'discharge_end1'    => 'timeDise1',
            'discharge_start2'  => 'timeDisf2',
            'discharge_end2'    => 'timeDise2',
        ],
    ];

    private const READS = [
        'power'     => 'getLastPowerData',
        'evlist'    => 'getEvChargerConfigList',
        'evstatus'  => 'getEvChargerStatusBySn',
        'evcurrent' => 'getEvChargerCurrentsBySn',
        'charge'    => 'getChargeConfigInfo',
        'discharge' => 'getDisChargeConfigInfo',
        'sum'       => 'getSumDataForCustomer',
        'today'     => 'getOneDateEnergyBySn',
        'ess'       => 'getEssList',
    ];

    private const WRITES = [
        'set_control'   => 'remoteControlEvCharger',
        'set_current'   => 'setEvChargerCurrentsBySn',
        'set_charge'    => 'updateChargeConfigInfo',
        'set_discharge' => 'updateDisChargeConfigInfo',
    ];

    public static function sign(string $appId, string $secret, int $timestamp): string
    {
        return hash('sha512', $appId . $secret . $timestamp);
    }

    /** @return list<string> */
    public static function headers(string $appId, string $secret, int $timestamp): array
    {
        return [
            'appId: ' . $appId,
            'timeStamp: ' . $timestamp,
            'sign: ' . self::sign($appId, $secret, $timestamp),
            'Content-Type: application/json',
        ];
    }

    /**
     * @param string $job Auftrag, bei Wallbox-Aufträgen „name:Seriennummer“
     * @param array<string,mixed> $args Nutzdaten eines Schreibauftrags
     * @return array{method:string,url:string,body:?string}|null
     */
    public static function build(string $job, string $sysSn, array $args = []): ?array
    {
        [$name, $serial] = explode(':', $job, 2) + [1 => ''];
        if (isset(self::READS[$name])) {
            $query = match ($name) {
                'ess'      => [],
                'evstatus' => ['sysSn' => $sysSn, 'evchargerSn' => $serial],
                'today'    => ['sysSn' => $sysSn, 'queryDate' => (string) ($args['date'] ?? '')],
                default    => ['sysSn' => $sysSn],
            };
            return ['method' => 'GET', 'url' => self::BASE_URL . '/' . self::READS[$name] . ($query === [] ? '' : '?' . http_build_query($query)), 'body' => null];
        }
        if (!isset(self::WRITES[$name])) {
            return null;
        }
        $body = match ($name) {
            'set_control' => ['sysSn' => $sysSn, 'evchargerSn' => $serial, 'controlMode' => !empty($args['start']) ? 1 : 0],
            'set_current' => ['sysSn' => $sysSn, 'currentsetting' => (int) ($args['amps'] ?? 0)],
            default       => ['sysSn' => $sysSn] + self::toCloud(substr($name, 4), $args),
        };
        return ['method' => 'POST', 'url' => self::BASE_URL . '/' . self::WRITES[$name], 'body' => (string) json_encode($body)];
    }

    /**
     * Leistungen aus getLastPowerData, benannt wie die Modbus-Werte, damit Wechselrichter und Speicher
     * sie unverändert anzeigen. Felder laut Demo des Herstellers (pbat, pgrid, pload, ppv, soc) und
     * den Details je String und Phase. Vorzeichen wie am Modbus: pbat > 0 entlädt, pgrid > 0 bezieht
     * aus dem Netz. Fehlt ein Feld, fehlt der Wert: die Anzeige bleibt dann beim letzten Stand.
     * @return array<string,float|int>
     */
    public static function powerValues(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        $fields = ['battery_soc' => $data['soc'] ?? null, 'battery_power' => $data['pbat'] ?? null, 'pv_power' => $data['ppv'] ?? null,
            'grid_power' => $data['pgrid'] ?? null, 'load_power' => $data['pload'] ?? null];
        for ($i = 1; $i <= 4; $i++) {
            $fields["pv{$i}_power"] = is_array($data['ppvDetail'] ?? null) ? ($data['ppvDetail']["ppv$i"] ?? null) : null;
        }
        for ($i = 1; $i <= 3; $i++) {
            $fields["grid_power_l$i"] = is_array($data['pgridDetail'] ?? null) ? ($data['pgridDetail']["pmeterL$i"] ?? null) : null;
            // Wirkleistung des Wechselrichters je Phase; die Summe ist die Wechselrichterleistung
            $fields["inverter_power_l$i"] = $data["prealL$i"] ?? null;
        }
        $values = [];
        foreach ($fields as $name => $value) {
            if (is_numeric($value)) {
                $values[$name] = (float) $value;
            }
        }
        if (isset($values['battery_power'])) {
            $values['battery_state'] = AlphaRegisters::batteryState($values['battery_power']);
        }
        $phases = array_intersect_key($values, array_flip(['inverter_power_l1', 'inverter_power_l2', 'inverter_power_l3']));
        if ($phases !== []) {
            $values['inverter_power'] = array_sum($phases);
        }
        return $values;
    }

    /**
     * Summen aus getSumDataForCustomer. Die Zählerstände gibt es zweimal, über Modbus und hier;
     * sie kommen nur mit $counters (Modus „Nur Cloud“), sonst stritten zwei Quellen um eine Variable.
     * @return array{inverter:array<string,float>,battery:array<string,float>,system:array<string,float|string>}
     */
    public static function sumValues(mixed $data, bool $counters): array
    {
        $out = ['inverter' => [], 'battery' => [], 'system' => []];
        if (!is_array($data)) {
            return $out;
        }
        $take = static function (string $kind, string $name, mixed $value, float $factor = 1.0) use (&$out): void {
            if (is_numeric($value)) {
                $out[$kind][$name] = round((float) $value * $factor, 4);
            }
        };
        $take('inverter', 'load_energy', $data['eload'] ?? null);
        $take('inverter', 'self_consumption', $data['eselfConsumption'] ?? null, 100.0);
        $take('inverter', 'self_sufficiency', $data['eselfSufficiency'] ?? null, 100.0);
        $take('system', 'income_total', $data['totalIncome'] ?? null);
        $take('system', 'income_today', $data['todayIncome'] ?? null);
        $take('system', 'trees', $data['treeNum'] ?? null);
        $take('system', 'co2', $data['carbonNum'] ?? null);
        if (is_string($data['moneyType'] ?? null) && trim($data['moneyType']) !== '') {
            $out['system']['currency'] = mb_substr(trim($data['moneyType']), 0, 8);
        }
        if ($counters) {
            $take('inverter', 'pv_energy', $data['epvtotal'] ?? null);
            $take('inverter', 'grid_feed_in_energy', $data['eoutput'] ?? null);
            $take('inverter', 'grid_consumption_energy', $data['einput'] ?? null);
            $take('battery', 'battery_charge_energy', $data['echarge'] ?? null);
            $take('battery', 'battery_discharge_energy', $data['edischarge'] ?? null);
        }
        return $out;
    }

    /**
     * Tageswerte aus getOneDateEnergyBySn.
     * @return array{inverter:array<string,float>,battery:array<string,float>,wallbox:array<string,float>}
     */
    public static function todayValues(mixed $data): array
    {
        $out = ['inverter' => [], 'battery' => [], 'wallbox' => []];
        $map = ['inverter' => ['pv_energy_today' => 'epv', 'grid_feed_in_today' => 'eOutput', 'grid_consumption_today' => 'eInput'],
            'battery' => ['battery_charge_today' => 'eCharge', 'battery_discharge_today' => 'eDischarge', 'battery_grid_charge_today' => 'eGridCharge'],
            'wallbox' => ['energy_today' => 'eChargingPile']];
        foreach ($map as $kind => $fields) {
            foreach ($fields as $name => $field) {
                if (is_array($data) && is_numeric($data[$field] ?? null)) {
                    $out[$kind][$name] = (float) $data[$field];
                }
            }
        }
        return $out;
    }

    /**
     * Stammdaten der Anlage aus getEssList (die Liste aller Anlagen des Zugangs).
     * @return array{battery:array<string,float>,system:array<string,float|string>}
     */
    public static function essValues(mixed $data, string $sysSn): array
    {
        $out = ['battery' => [], 'system' => []];
        $rows = is_array($data) ? (array_is_list($data) ? $data : [$data]) : [];
        $row = null;
        foreach ($rows as $candidate) {
            if (is_array($candidate) && (string) ($candidate['sysSn'] ?? '') === $sysSn) {
                $row = $candidate;
            }
        }
        if ($row === null) {
            return $out;
        }
        foreach (['ems_status' => 'emsStatus', 'inverter_model' => 'minv', 'battery_model' => 'mbat'] as $name => $field) {
            if (is_string($row[$field] ?? null) && trim($row[$field]) !== '') {
                $out['system'][$name] = mb_substr(trim($row[$field]), 0, 60);
            }
        }
        foreach (['system' => ['pv_peak' => 'popv', 'inverter_rated' => 'poinv'],
            'battery' => ['battery_capacity' => 'cobat', 'battery_usable_capacity' => 'surplusCobat', 'battery_usable_share' => 'usCapacity']] as $kind => $fields) {
            foreach ($fields as $name => $field) {
                if (is_numeric($row[$field] ?? null)) {
                    $out[$kind][$name] = (float) $row[$field];
                }
            }
        }
        return $out;
    }

    /** @return array{ok:bool,code:int,error:string,data:mixed} */
    public static function parse(int $httpStatus, string $body, string $transportError = ''): array
    {
        if ($httpStatus === 0) {
            return ['ok' => false, 'code' => 0, 'error' => $transportError !== '' ? $transportError : 'No connection', 'data' => null];
        }
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return ['ok' => false, 'code' => $httpStatus, 'error' => 'Unexpected answer (HTTP ' . $httpStatus . ')', 'data' => null];
        }
        $code = (int) ($json['code'] ?? 0);
        if ($code === 200) {
            return ['ok' => true, 'code' => 200, 'error' => '', 'data' => $json['data'] ?? null];
        }
        // Der Meldungstext heißt je nach Endpunkt msg oder info.
        $text = $json['msg'] ?? $json['info'] ?? null;
        return ['ok' => false, 'code' => $code, 'error' => self::ERRORS[$code] ?? (is_string($text) && $text !== '' ? $text : 'Error ' . $code), 'data' => null];
    }

    public static function isAuthError(int $code): bool
    {
        return in_array($code, self::AUTH_CODES, true);
    }

    /**
     * Konfiguration der Cloud in neutrale Werte (bool, int, „HH:mm“).
     * @return array<string,bool|int|string> leer, wenn die Antwort nicht alle Felder enthält
     */
    public static function fromCloud(string $which, mixed $data): array
    {
        $out = [];
        foreach (self::CONFIG_FIELDS[$which] ?? [] as $key => $field) {
            if (!is_array($data) || !array_key_exists($field, $data)) {
                return [];
            }
            $out[$key] = self::normalize($key, $data[$field]);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $values vollständige neutrale Konfiguration
     * @return array<string,int|string>
     */
    public static function toCloud(string $which, array $values): array
    {
        $out = [];
        foreach (self::CONFIG_FIELDS[$which] ?? [] as $key => $field) {
            $v = self::normalize($key, $values[$key] ?? null);
            $out[$field] = is_bool($v) ? (int) $v : $v;
        }
        return $out;
    }

    /** Welche Konfiguration ein neutraler Name betrifft: charge, discharge oder leer. */
    public static function configOf(string $key): string
    {
        foreach (self::CONFIG_FIELDS as $which => $fields) {
            if (isset($fields[$key])) {
                return $which;
            }
        }
        return '';
    }

    public static function normalize(string $key, mixed $value): bool|int|string
    {
        if (str_contains($key, '_start') || str_contains($key, '_end')) {
            return AlphaTimeGrid::snap(is_string($value) ? substr($value, 0, 5) : '');
        }
        if (str_ends_with($key, '_limit')) {
            return max(0, min(100, (int) round((float) $value)));
        }
        return (bool) (is_string($value) ? (int) $value : $value);
    }
}
