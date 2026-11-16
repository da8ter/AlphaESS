<?php

declare(strict_types=1);

/*
 * Fehler- und Warnbits des Wechselrichters als Text. Bitnamen nach der Registerliste von Alpha ESS
 * (Note26 Fehler, Note32 Warnungen, „Household Inverter“). Die Fehlernummern folgen dem
 * Installationshandbuch SMILE-G3-T4…T10 (V07, Kapitel 9.2): Fehler 1 Bit n = 100000 + n,
 * Fehler 2 Bit n = 100032 + n (z. B. 100012 PV_Reverse, 100042 Output_short). Warnungen haben dort
 * keine Nummer. Reine Logik ohne Symcon.
 */
final class AlphaFaults
{
    private const FAULT1 = ['Grid_OVP', 'Grid_UVP', 'Grid_OFP', 'Grid_UFP', 'Phase_locked_fault', 'BUS_OVP1', 'BUS_OVP2',
        'Insulation_fault', 'GFCI_fault', 'GFCI_test_fault', 'Grid_relay_fault', 'Over_Temperature', 'PV_Reverse', 'BAT_Reverse',
        'M_S_com_fault', 'Display_com_fault', 'Chip1_upgrade_fault', 'MPPT1_OVP', 'MPPT1_SW_OCP', 'MPPT1_HW_OCP', 'MPPT1_OTP',
        'MPPT2_OVP', 'MPPT2_SW_OCP', 'MPPT2_HW_OCP', 'MPPT2_OTP', 'BAT_OVP', 'BAT_UVP', 'Battery_lose', 'BAT_OTP',
        'BAT1_charge_OCP', 'BAT1_discharge_OCP', 'BAT2_charge_OCP'];

    private const FAULT2 = ['BAT2_discharge_OCP', 'BAT1_HW_OCP', 'BAT2_HW_OCP', 'INV_OTP', 'INV_OVP', 'INV_UVP',
        'Output_DC_over_current', 'INV_OCP', 'INV_HW_OCP', 'Output_DC_over_voltage', 'Output_short_circuit', 'Output_overload',
        'APU_UVP', 'BAT_relay_fault', 'DC_input_disturbance', 'Grid_disturbance', 'Grid_unbalance', 'Freq_jitter',
        'Grid_overcurrent', 'Grid_current_track_fault', 'Backup_ovp', 'DC_bus_unbalance_volt', 'DC_bus_undervolt',
        'DC_bus_unbalance_volt2', 'IGBT_over_current', 'Grid_disturbance2', 'AFCI_check_protect',
        'Grid_current_sampling_abnormal', 'DSP_selfcheck', 'Grid_short_time_over_current', 'BAT_overvolt_hardware_fault',
        'Zero_ground_fault'];

    private const WARNING1 = ['bat_over_voltage_alarm', 'bat_under_voltage_alarm', 'output_overload_alarm',
        'abnormal_temperature_sensor', 'dc_power_alarm', 'battery_stops_running_alarm', 'over_temperature_alarm',
        'pv_volt_high_alarm', 'bat_open_alarm', 'bat_reverse_alarm', 'bus_over_alarm', 'grid_loss_alarm', 'grid_volt_alarm',
        'grid_freq_alarm', '10min_grid_volt_alarm', 'grid_volt_inst_over', 'pe_loss_alarm', 'ln_reverse', 'low_temper_alarm',
        'gfci_alarm', 'iso_alarm', 'dci_alarm', 'dcv_alarm', 'island_alarm', 'fan_abnormal_alarm', 'n_loss_alarm',
        'ems_sci_alarm', 'ems_can_alarm', 'flashid_alarm', 'read_flash_alarm', 'write_flash_alarm', 'machine_type_alarm'];

    private const WARNING2 = ['inv_volt_low_alarm', 'inv_over_curr_sw_alarm', 'inv_over_curr_hw_alarm',
        'bst_over_curr_sw_alarm', 'bst_over_curr_hw_alarm', 'buck_bst_over_curr_sw_alarm', 'buck_bst_over_curr_hw_alarm',
        'bus_under', 'no_pv_input_alarm', 'input_power_limit_alarm', 'output_power_limit_alarm', 'reduce_pby_over_freq_alarm',
        'reduce_pby_over_volt_alarm', 'reduce_pby_over_temp_alarm', 'hvrt_alarm', 'lvrt_alarm', 'ntc_fail_alarm',
        'grid_waveform_abnormal_alarm', 'eps_capacitance_decrease', 'para_alarm', 'para_error_location', 'para_avg_overload',
        'para_module_addr_same', 'para_online_enter_fail', 'para_unbalance_power', 'para_turnon_inconsistent',
        'grid_backup_n_lost', 'bat_num_abnormal', 'grid_phase_order_fault', 'dcv_sample_abnormal', 'blackbox_flash_fault',
        'rtc_fault'];

    /** Gesetzte Fehler als „100012 PV_Reverse, …“, leer ohne Fehler. */
    public static function faults(int $fault1, int $fault2): string
    {
        $out = [];
        foreach ([[$fault1, self::FAULT1, 100000], [$fault2, self::FAULT2, 100032]] as [$mask, $names, $base]) {
            foreach (self::bits($mask) as $bit) {
                $out[] = ($base + $bit) . ' ' . $names[$bit];
            }
        }
        return implode(', ', $out);
    }

    /** Gesetzte Warnungen als Bitnamen, leer ohne Warnung. */
    public static function warnings(int $warning1, int $warning2): string
    {
        $out = [];
        foreach ([[$warning1, self::WARNING1], [$warning2, self::WARNING2]] as [$mask, $names]) {
            foreach (self::bits($mask) as $bit) {
                $out[] = $names[$bit];
            }
        }
        return implode(', ', $out);
    }

    /** @return list<int> */
    private static function bits(int $mask): array
    {
        $bits = [];
        for ($bit = 0; $bit < 32; $bit++) {
            if (($mask >> $bit) & 1) {
                $bits[] = $bit;
            }
        }
        return $bits;
    }
}
