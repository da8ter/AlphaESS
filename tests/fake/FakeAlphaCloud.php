<?php

declare(strict_types=1);

/*
 * Attrappe der Alpha ESS Open API. Prüft Signatur und Zeitstempel, verlangt den Mindestabstand
 * zwischen Aufrufen (sonst 6053) und hält Konfiguration und Wallbox-Zustand. Feldnamen wie in
 * der Postman-Sammlung des Herstellers; Seriennummern sind Platzhalter.
 */
final class FakeAlphaCloud
{
    public static ?FakeAlphaCloud $current = null;

    public string $appId = 'alpha0000000000000';
    public string $secret = 'geheim-nur-im-pruefstand';
    public string $sysSn = 'AL0000000000001';
    public int $spacing = 10;
    public bool $offline = false;
    /** Erzwingt diesen Fehlercode für jeden Aufruf */
    public ?int $failCode = null;
    /** Lesen liefert nach einem Schreiben noch so viele Sekunden den alten Stand */
    public int $staleSeconds = 0;

    /** @var array<string,mixed> */
    public array $charge = ['gridCharge' => 0, 'batHighCap' => 100, 'timeChaf1' => '00:00', 'timeChae1' => '00:00', 'timeChaf2' => '00:00', 'timeChae2' => '00:00'];
    /** @var array<string,mixed> */
    public array $discharge = ['ctrDis' => 0, 'batUseCap' => 10, 'timeDisf1' => '00:00', 'timeDise1' => '00:00', 'timeDisf2' => '00:00', 'timeDise2' => '00:00'];
    /** @var array<string,array{model:string,status:int,power:float}> */
    public array $wallboxes = [];
    public int $amps = 16;
    /** Wird während eines Aufrufs ausgeführt (z. B. um einen Befehl mitten im Aufruf zu geben) */
    public ?Closure $onCall = null;

    /** @var list<array{at:int,method:string,endpoint:string,params:array<string,mixed>}> */
    public array $calls = [];
    private int $lastCall = -1000;
    /** @var array<string,array{until:int,data:array<string,mixed>}> */
    private array $stale = [];

    /**
     * @param list<string> $headers
     * @return array{status:int,body:string,error:string}
     */
    public function handle(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->offline) {
            return ['status' => 0, 'body' => '', 'error' => 'Could not resolve host'];
        }
        $now = Kernel::now();
        $parts = parse_url($url);
        $endpoint = basename((string) $parts['path']);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $params = $method === 'POST' ? (array) json_decode((string) $body, true) : $query;
        $this->calls[] = ['at' => $now, 'method' => $method, 'endpoint' => $endpoint, 'params' => $params];
        if ($this->onCall !== null) {
            $hook = $this->onCall;
            $this->onCall = null;
            $hook($endpoint);
        }

        $h = [];
        foreach ($headers as $line) {
            [$k, $v] = explode(': ', $line, 2);
            $h[$k] = $v;
        }
        $code = $this->check($h, $params, $now);
        $tooFast = $now - $this->lastCall < $this->spacing;
        $this->lastCall = $now;
        if ($code === 200 && $tooFast) {
            $code = 6053;
        }
        if ($code !== 200) {
            return $this->answer($code, null);
        }
        return $this->answer(200, $this->dispatch($method, $endpoint, $params, $now));
    }

    /** @return list<string> Endpunkte in Aufrufreihenfolge */
    public function endpoints(): array
    {
        return array_column($this->calls, 'endpoint');
    }

    /**
     * @param array<string,string> $h
     * @param array<string,mixed> $params
     */
    private function check(array $h, array $params, int $now): int
    {
        if ($this->failCode !== null) {
            return $this->failCode;
        }
        if (($h['appId'] ?? '') === '' || ($h['sign'] ?? '') === '' || ($h['timeStamp'] ?? '') === '') {
            return 6010;
        }
        if (abs($now - (int) $h['timeStamp']) > 300) {
            return 6006;
        }
        if ($h['appId'] !== $this->appId || !hash_equals(hash('sha512', $this->appId . $this->secret . $h['timeStamp']), $h['sign'])) {
            return 6007;
        }
        return ($params['sysSn'] ?? '') === $this->sysSn ? 200 : 6005;
    }

    /** @param array<string,mixed> $p */
    private function dispatch(string $method, string $endpoint, array $p, int $now): mixed
    {
        switch ($method . ' ' . $endpoint) {
            case 'GET getEvChargerConfigList':
                $list = [];
                foreach ($this->wallboxes as $sn => $box) {
                    $list[] = ['evchargerSn' => $sn, 'evchargerModel' => $box['model']];
                }
                return $list;
            case 'GET getEvChargerStatusBySn':
                // Laut Handbuch des Herstellers ist data hier eine Liste.
                return [['evchargerStatus' => $this->wallboxes[(string) $p['evchargerSn']]['status'] ?? 0]];
            case 'GET getEvChargerCurrentsBySn':
                return $this->read('current', ['currentsetting' => $this->amps], $now);
            case 'GET getLastPowerData':
                $detail = [];
                foreach (array_values($this->wallboxes) as $i => $box) {
                    $detail['ev' . ($i + 1) . 'Power'] = $box['power'];
                }
                return ['soc' => 65.5, 'pev' => array_sum($detail), 'pevDetail' => $detail];
            case 'GET getChargeConfigInfo':
                return $this->read('charge', $this->charge, $now);
            case 'GET getDisChargeConfigInfo':
                return $this->read('discharge', $this->discharge, $now);
            case 'POST updateChargeConfigInfo':
                $this->keep('charge', $this->charge, $now);
                $this->charge = array_intersect_key($p, $this->charge) + $this->charge;
                return null;
            case 'POST updateDisChargeConfigInfo':
                $this->keep('discharge', $this->discharge, $now);
                $this->discharge = array_intersect_key($p, $this->discharge) + $this->discharge;
                return null;
            case 'POST setEvChargerCurrentsBySn':
                $this->keep('current', ['currentsetting' => $this->amps], $now);
                $this->amps = (int) $p['currentsetting'];
                return null;
            case 'POST remoteControlEvCharger':
                $sn = (string) $p['evchargerSn'];
                if (isset($this->wallboxes[$sn])) {
                    $this->wallboxes[$sn]['status'] = (int) $p['controlMode'] === 1 ? 3 : 6;
                }
                return null;
        }
        return null;
    }

    /** @param array<string,mixed> $old */
    private function keep(string $what, array $old, int $now): void
    {
        if ($this->staleSeconds > 0) {
            $this->stale[$what] = ['until' => $now + $this->staleSeconds, 'data' => $old];
        }
    }

    /**
     * @param array<string,mixed> $fresh
     * @return array<string,mixed>
     */
    private function read(string $what, array $fresh, int $now): array
    {
        return isset($this->stale[$what]) && $now < $this->stale[$what]['until'] ? $this->stale[$what]['data'] : $fresh;
    }

    /** @return array{status:int,body:string,error:string} */
    private function answer(int $code, mixed $data): array
    {
        return ['status' => 200, 'body' => (string) json_encode(['code' => $code, 'msg' => $code === 200 ? 'Success' : 'error', 'data' => $data]), 'error' => ''];
    }
}
