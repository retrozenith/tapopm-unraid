<?php
header('Content-Type: application/json');

$tapopm_cfg = parse_plugin_cfg("tapopm");

$tapopm_ip = isset($tapopm_cfg['TAPO_IP']) ? $tapopm_cfg['TAPO_IP'] : "";
$tapopm_email = isset($tapopm_cfg['TAPO_EMAIL']) ? $tapopm_cfg['TAPO_EMAIL'] : "";
$tapopm_pass = isset($tapopm_cfg['TAPO_PASS']) ? $tapopm_cfg['TAPO_PASS'] : "";
$tapopm_costs_price = isset($tapopm_cfg['COSTS_PRICE']) ? $tapopm_cfg['COSTS_PRICE'] : 0;
$tapopm_costs_unit = isset($tapopm_cfg['COSTS_UNIT']) ? $tapopm_cfg['COSTS_UNIT'] : "USD";

if ($tapopm_ip == "" || $tapopm_email == "" || $tapopm_pass == "") {
    echo json_encode(["error" => "Configuration missing"]);
    exit;
}

// Call Go binary
$cmd = escapeshellcmd("/usr/local/bin/tapo-cli") . 
       " -ip " . escapeshellarg($tapopm_ip) . 
       " -email " . escapeshellarg($tapopm_email) . 
       " -password " . escapeshellarg($tapopm_pass) . " 2>&1";

$output = shell_exec($cmd);

// Find JSON in output (skip "Connecting..." lines)
$lines = explode("\n", $output);
$jsonStart = false;
$jsonData = "";
foreach ($lines as $line) {
    if (strpos($line, '{') !== false) {
        $jsonStart = true;
    }
    if ($jsonStart) {
        $jsonData .= $line;
    }
}

$data = json_decode($jsonData, true);

if ($data === null) {
    echo json_encode(["error" => "Failed to parse energy data", "raw" => $output]);
    exit;
}

// Map to expected JSON keys
// Go library returns: today_energy (Wh), month_energy (Wh), current_power (mW)
$power_mw = isset($data['current_power']) ? $data['current_power'] : 0;
$today_wh = isset($data['today_energy']) ? $data['today_energy'] : 0;
$month_wh = isset($data['month_energy']) ? $data['month_energy'] : 0;

$json = array(
    'Total' => $month_wh / 1000, // kWh
    'Today' => $today_wh / 1000, // kWh
    'Yesterday' => 0,
    'Voltage' => 0,
    'Current' => 0,
    'ApparentPower' => 0,
    'ReactivePower' => 0,
    'Factor' => 0,
    'Power' => $power_mw / 1000, // W
    'Costs_Price' => $tapopm_costs_price,
    'Costs_Unit' => $tapopm_costs_unit
);

echo json_encode($json);
?>