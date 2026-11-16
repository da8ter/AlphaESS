<?php

declare(strict_types=1);

/*
 * Attrappe des Symcon ModBus Gateways. Antwortet wie am 10.11.2026 gemessen: Binärstring
 * „Funktionscode, Byteanzahl, Daten“, bei Lücke, Zeitüberschreitung oder totem Socket false.
 */
final class FakeModbusGateway
{
    public const MODULE_ID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    private const TX = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';
    private const CHILD = '{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}';

    /** @var array<int,int> */
    public array $registers;
    /** normal, dead (nichts antwortet) oder hex (Antwort hex-kodiert) */
    public string $mode = 'normal';
    /** @var list<array{address:int,quantity:int}> */
    public array $requests = [];

    public function __construct()
    {
        $this->registers = require __DIR__ . '/../fixtures/registers.php';
    }

    public function install(): int
    {
        Kernel::registerModule(['ModuleID' => self::MODULE_ID, 'ModuleName' => 'ModBus Gateway', 'ModuleType' => 2, 'Prefix' => 'ModBus',
            'Implemented' => [self::TX], 'ParentRequirements' => [], 'ChildRequirements' => [self::CHILD]]);
        $id = Kernel::createInstance(self::MODULE_ID);
        Kernel::$instances[$id]['handler'] = $this;
        return $id;
    }

    public function set16(int $address, int $value): void
    {
        $this->registers[$address] = $value & 0xFFFF;
    }

    public function set32(int $address, int $value): void
    {
        $this->registers[$address] = ($value >> 16) & 0xFFFF;
        $this->registers[$address + 1] = $value & 0xFFFF;
    }

    public function ForwardData(string $json): string|false
    {
        $d = json_decode($json, true);
        if ((int) $d['Function'] === 16) {
            return $this->writeRegisters($d);
        }
        $this->requests[] = ['address' => (int) $d['Address'], 'quantity' => (int) $d['Quantity']];
        if ($this->mode === 'dead' || (int) $d['Function'] !== 3) {
            return false;
        }
        $payload = '';
        for ($i = 0; $i < (int) $d['Quantity']; $i++) {
            if (!isset($this->registers[(int) $d['Address'] + $i])) {
                return false;
            }
            $payload .= pack('n', $this->registers[(int) $d['Address'] + $i]);
        }
        $answer = pack('CC', 3, strlen($payload)) . $payload;
        return $this->mode === 'hex' ? strtoupper(bin2hex($answer)) : $answer;
    }

    /** @var list<array{address:int,values:list<int>}> Schreibbefehle in der Reihenfolge des Eingangs */
    public array $writes = [];

    /** Schreiben wie gemessen: Data als Hex-String, Antwort das Modbus-Echo; „readonly“ lehnt ab wie ein Gerät mit Exception. */
    private function writeRegisters(array $d): string|false
    {
        $data = (string) $d['Data'];
        if ($this->mode === 'dead' || $this->mode === 'readonly' || !ctype_xdigit($data) || strlen($data) !== 4 * (int) $d['Quantity']) {
            return false;
        }
        $values = array_map('hexdec', str_split($data, 4));
        $this->writes[] = ['address' => (int) $d['Address'], 'values' => $values];
        foreach ($values as $i => $value) {
            $this->registers[(int) $d['Address'] + $i] = $value;
        }
        return pack('Cnn', 16, (int) $d['Address'], (int) $d['Quantity']);
    }
}
