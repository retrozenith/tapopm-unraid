<?php
// Suppress errors in output, we'll return JSON errors instead
error_reporting(0);
header('Content-Type: application/json');

// Try to load Unraid's config function, or use manual parsing
$tapopm_cfg = array();
$cfgFile = "/boot/config/plugins/tapopm/tapopm.cfg";

if (function_exists('parse_plugin_cfg')) {
    $tapopm_cfg = parse_plugin_cfg("tapopm");
} elseif (file_exists($cfgFile)) {
    $tapopm_cfg = parse_ini_file($cfgFile);
}

$tapopm_ip = isset($tapopm_cfg['TAPO_IP']) ? $tapopm_cfg['TAPO_IP'] : "";
$tapopm_email = isset($tapopm_cfg['TAPO_EMAIL']) ? $tapopm_cfg['TAPO_EMAIL'] : "";
$tapopm_pass = isset($tapopm_cfg['TAPO_PASS']) ? $tapopm_cfg['TAPO_PASS'] : "";
$tapopm_costs_price = isset($tapopm_cfg['COSTS_PRICE']) ? floatval($tapopm_cfg['COSTS_PRICE']) : 0;
$tapopm_costs_unit = isset($tapopm_cfg['COSTS_UNIT']) ? $tapopm_cfg['COSTS_UNIT'] : "USD";

if ($tapopm_ip == "" || $tapopm_email == "" || $tapopm_pass == "") {
    echo json_encode(["error" => "Configuration missing", "config" => $tapopm_cfg]);
    exit;
}

// Check if binary exists
$binaryPath = "/usr/local/bin/tapo-cli";
if (!file_exists($binaryPath)) {
    echo json_encode(["error" => "Binary not found at $binaryPath"]);
    exit;
}

// Call Go binary
$cmd = escapeshellarg($binaryPath) . 
       " -ip " . escapeshellarg($tapopm_ip) . 
       " -email " . escapeshellarg($tapopm_email) . 
       " -password " . escapeshellarg($tapopm_pass) . " 2>&1";

$output = shell_exec($cmd);

if ($output === null) {
    echo json_encode(["error" => "shell_exec failed or returned null"]);
    exit;
}

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
$power_mw = isset($data['current_power']) ? floatval($data['current_power']) : 0;
$today_wh = isset($data['today_energy']) ? floatval($data['today_energy']) : 0;
$month_wh = isset($data['month_energy']) ? floatval($data['month_energy']) : 0;

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