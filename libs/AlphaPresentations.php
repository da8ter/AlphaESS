<?php

declare(strict_types=1);

// Darstellungs-Arrays für die Variablen der Bibliothek. Nur dokumentierte Parameter.
final class AlphaPresentations
{
    public const GREEN = 0x2ECC71;
    public const ORANGE = 0xF39C12;
    public const RED = 0xE74C3C;
    public const BLUE = 0x3498DB;
    public const GREY = 0x95A5A6;

    /** @return array<string,mixed> */
    public static function value(string $icon, string $suffix, int $digits = 0): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'         => $icon,
            'SUFFIX'       => $suffix,
            'DIGITS'       => $digits,
        ];
    }

    /**
     * Anzeige eines Zustands: je Wert ein Intervall mit Text und Farbe. Eine Aufzählung ohne
     * Aktion zeigt die Kachel-Visu als nackte Zahl, deshalb die Wertanzeige.
     * @param list<array{0:int,1:string,2:int}> $rows [Wert, Beschriftung, Farbe]
     * @return array<string,mixed>
     */
    /** Text ohne Einheit und Stellen: die Wertdarstellung nur mit Symbol */
    public static function text(string $icon): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'ICON' => $icon];
    }

    public static function state(string $icon, array $rows): array
    {
        $intervals = [];
        foreach ($rows as [$value, $caption, $color]) {
            $intervals[] = [
                'IntervalMinValue'   => $value,
                'IntervalMaxValue'   => $value,
                'ConstantActive'     => true,
                'ConstantValue'      => $caption,
                'ConversionFactor'   => 1,
                'PrefixActive'       => false, 'PrefixValue' => '',
                'SuffixActive'       => false, 'SuffixValue' => '',
                'DigitsActive'       => false, 'DigitsValue' => 0,
                'IconActive'         => false, 'IconValue' => '',
                'ColorActive'        => true, 'ColorValue' => $color,
                'ContentColorActive' => false, 'ContentColorValue' => -1,
            ];
        }
        return [
            'PRESENTATION'     => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'ICON'             => $icon,
            'INTERVALS_ACTIVE' => true,
            'INTERVALS'        => (string) json_encode($intervals, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** @return array<string,mixed> */
    public static function toggle(string $icon): array
    {
        return [
            'PRESENTATION'   => VARIABLE_PRESENTATION_SWITCH,
            'USE_ICON_FALSE' => false,
            'ICON_TRUE'      => $icon,
        ];
    }

    /** @return array<string,mixed> */
    public static function slider(string $icon, string $suffix, int $min, int $max, int $step = 1): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'ICON'         => $icon,
            'SUFFIX'       => $suffix,
            'MIN'          => $min,
            'MAX'          => $max,
            'STEP_SIZE'    => $step,
        ];
    }

    /**
     * Schaltbare Auswahl. Nur dokumentierte Parameter: ICON, OPTIONS (Value, Caption, IconActive, IconValue, Color).
     * @param list<array{0:int,1:string,2:int}> $rows [Wert, Beschriftung, Farbe]
     * @return array<string,mixed>
     */
    public static function enumeration(string $icon, array $rows): array
    {
        $options = [];
        foreach ($rows as [$value, $caption, $color]) {
            $options[] = ['Value' => $value, 'Caption' => $caption, 'IconActive' => false, 'IconValue' => '', 'Color' => $color];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => $icon,
            'OPTIONS'      => (string) json_encode($options, JSON_UNESCAPED_UNICODE),
        ];
    }

    /** Uhrzeit ohne Datum, Stunden und Minuten. @return array<string,mixed> */
    public static function clock(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
            'DATE'         => 0,
            'TIME'         => 1,
        ];
    }
}
