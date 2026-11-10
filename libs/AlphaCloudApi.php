<?php

declare(strict_types=1);

/*
 * Alpha ESS Open API: Signatur, Anfragen je Auftrag und Auswertung der Antworten.
 * Reine Logik ohne Netz. Quelle der Endpunkte: Postman-Sammlung und Demo des Herstellers
 * (github.com/alphaess-developer/alphacloud_open_api).
 */
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
            $query = ['sysSn' => $sysSn] + ($name === 'evstatus' ? ['evchargerSn' => $serial] : []);
            return ['method' => 'GET', 'url' => self::BASE_URL . '/' . self::READS[$name] . '?' . http_build_query($query), 'body' => null];
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
        return ['ok' => false, 'code' => $code, 'error' => self::ERRORS[$code] ?? (string) ($json['msg'] ?? ('Error ' . $code)), 'data' => null];
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
