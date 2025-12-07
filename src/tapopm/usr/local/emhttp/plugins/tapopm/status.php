<?php
include_once "TapoP110.php";

$tapopm_cfg = parse_ini_file( "/boot/config/plugins/tapopm/tapopm.cfg" );
$tapopm_ip = isset($tapopm_cfg['TAPO_IP']) ? $tapopm_cfg['TAPO_IP'] : "";
$tapopm_email = isset($tapopm_cfg['TAPO_EMAIL']) ? $tapopm_cfg['TAPO_EMAIL'] : "";
$tapopm_pass = isset($tapopm_cfg['TAPO_PASS']) ? $tapopm_cfg['TAPO_PASS'] : "";
$tapopm_costs_price = isset($tapopm_cfg['COSTS_PRICE']) ? $tapopm_cfg['COSTS_PRICE'] : "0.0";
$tapopm_costs_unit = isset($tapopm_cfg['COSTS_UNIT']) ? $tapopm_cfg['COSTS_UNIT'] : "USD";

if ($tapopm_ip == "" || $tapopm_email == "" || $tapopm_pass == "") {
    error_log("Tapopm: Configuration missing");
    die(json_encode(["error" => "Configuration missing"]));
}

try {
    $tapo = new TapoP110($tapopm_ip, $tapopm_email, $tapopm_pass);
    $tapo->handshake();
    $tapo->login();
    $data = $tapo->getEnergyUsage();
    
    // Tapo P110 usually returns current_power (mW), today_energy (Wh), month_energy (Wh)
    // Note: This is an assumption based on common generic IoT APIs. 
    // We might need to adjust keys based on actual response: e.g. 'current_power', 'today_energy'
    
    // Map to expected JSON keys
    $power_mw = isset($data['current_power']) ? $data['current_power'] : 0;
    $today_wh = isset($data['today_energy']) ? $data['today_energy'] : 0;
    $month_wh = isset($data['month_energy']) ? $data['month_energy'] : 0; // Total might be month? Or cumulative?
    
    // Unraid Dashboard expects:
    // Power (W)
    // Today (kWh)
    // Total (kWh)
    // Voltage, Current, Etc (Optional/Zeros if unknown)

    $json = array(
        'Total' => $month_wh / 1000, // Assuming Total = Month for now
        'Today' => $today_wh / 1000,
        'Yesterday' => 0, // Tapo might provide past 7 days, but simpler to omit for now
        'Voltage' => 0, // specific API call needed?
        'Current' => 0,
        'ApparentPower' => 0,
        'ReactivePower' => 0,
        'Factor' => 0,
        'Power' => $power_mw / 1000,
        'Costs_Price' => $tapopm_costs_price,
        'Costs_Unit' => $tapopm_costs_unit
    );

    header('Content-Type: application/json');
    echo json_encode($json);

} catch (Exception $e) {
    error_log("Tapopm Error: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>