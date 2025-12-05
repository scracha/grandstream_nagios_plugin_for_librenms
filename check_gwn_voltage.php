#!/usr/bin/env php
<?php

// Nagios Exit Codes
define('STATE_OK', 0);
define('STATE_WARNING', 1);
define('STATE_CRITICAL', 2);
define('STATE_UNKNOWN', 3);

// --- 1. NAGIOS EXIT HANDLER (Modified for Standard Output) ---

function check_exit($state, $message, $perfdata = "") {
    $status_text = 'UNKNOWN';

    switch ($state) {
        case STATE_OK:
            $status_text = 'OK';
            break;
        case STATE_WARNING:
            $status_text = 'WARNING';
            break;
        case STATE_CRITICAL:
            $status_text = 'CRITICAL';
            break;
        default:
            $status_text = 'UNKNOWN';
            break;
    }
    
    // Standard Nagios output format: STATUS - MESSAGE | PERFDATA
    echo "{$status_text} - {$message}{$perfdata}\n"; 
    exit($state);
}

// --- 2. CONFIGURATION: PARSE COMMAND LINE ARGUMENTS ---

$options = getopt("H:U:P:w:c:h");

if (isset($options['h']) || !isset($options['H']) || !isset($options['U']) || !isset($options['P']) || !isset($options['w']) || !isset($options['c'])) {
    check_exit(STATE_UNKNOWN, "Usage: check_gwn_voltage -H <ip> -U <user> -P <pass> -w <warn_volts> -c <crit_volts>\nExample: check_gwn_voltage -H 172.16.171.101 -U admin -P Wiz26c@n -w 40 -c 35");
}

$device_ip = $options['H'];
$username = $options['U'];
$password = $options['P'];

$warn_threshold = (float)$options['w'];
$crit_threshold = (float)$options['c'];

if ($warn_threshold <= 0 || $crit_threshold <= 0 || $crit_threshold > $warn_threshold) {
    check_exit(STATE_UNKNOWN, "Invalid threshold range provided. Critical threshold must be lower than Warning threshold, and both must be positive values.");
}

// --- 3. GLOBAL VARIABLES & ENDPOINTS ---

$nonce_url = "http://{$device_ip}/get.cgi?cmd=get_nonce";
$login_url = "http://{$device_ip}/set.cgi?cmd=login"; 
$logout_url = "http://{$device_ip}/set.cgi?cmd=logout"; 
$voltage_page_url = "http://{$device_ip}/get.cgi?cmd=poe_get_powerinfo"; 

// --- HELPER FUNCTION: HASHING ---

function calculate_final_hash($user, $nonce, $plain_pass) {
    $input_string = "{$user}:{$nonce}:{$plain_pass}";
    return hash('sha256', $input_string);
}

// --- 4. CORE PLUGIN FUNCTIONS ---

/**
 * Executes both the get_nonce and login steps using a single cURL handle
 */
function login_grandstream($nonce_url, $login_url, $ip, $user, $plain_pass) {
    $ch = curl_init();
    
    // Setup for fresh, memory-based session
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true); 
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, "");
    curl_setopt($ch, CURLOPT_COOKIEFILE, ""); 
    
    // --- Step 1: GET NONCE ---
    curl_setopt($ch, CURLOPT_URL, $nonce_url);
    
    $nonce_headers = [
        'Host: ' . $ip,
        'Accept: application/json, text/plain, */*',
        'Referer: http://' . $ip . '/',
        'User-Agent: Mozilla/5.0',
        'X-Requested-With: XMLHttpRequest',
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $nonce_headers);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($http_code !== 200) {
        curl_close($ch);
        return null;
    }
    
    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['data']['nonce'])) {
        curl_close($ch);
        return null;
    }
    $nonce = $data['data']['nonce'];


    // --- Step 2: LOGIN ---
    $final_password = calculate_final_hash($user, $nonce, $plain_pass);
    
    $post_data = json_encode(['username' => $user, 'password' => $final_password]);

    curl_setopt($ch, CURLOPT_URL, $login_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data); 
    
    $login_headers = [
        'Host: ' . $ip,
        'Accept: application/json',
        'Content-Type: application/json',
        'Origin: http://' . $ip,
        'Referer: http://' . $ip . '/',
        'User-Agent: Mozilla/5.0',
        'X-Requested-With: XMLHttpRequest',
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $login_headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch); 

    if ($http_code == 200) {
        $login_data = json_decode($response, true);
        if (isset($login_data['code']) && $login_data['code'] == 200 && isset($login_data['data']['token'])) {
            return $login_data['data']['token']; 
        }
    }
    return null;
}

/**
 * Retrieves and checks the voltage against Nagios thresholds.
 */
function check_voltage($url, $auth_token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, true); 
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
    
    $headers = [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0',
        'X-Requested-With: XMLHttpRequest',
        'Authorization: ' . $auth_token 
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200) {
        return ['state' => STATE_UNKNOWN, 'message' => "API status retrieval failed. HTTP Code: {$http_code}", 'perfdata' => ""];
    }

    $data = json_decode($response, true);
    
    if ($data !== null && isset($data['data']['inputVoltage']) && is_numeric($data['data']['inputVoltage'])) {
         
         $raw_voltage_mv = $data['data']['inputVoltage'];
         $voltage_v = round($raw_voltage_mv / 1000, 3);
         
         $state = STATE_OK;
         
         // Base message format
         $message = sprintf(
             "%s: Input_Voltage %.3fV, (Warn < %.1fV, Crit < %.1fV).",
             $GLOBALS['device_ip'],
             $voltage_v,
             $GLOBALS['warn_threshold'],
             $GLOBALS['crit_threshold']
         );

         // Handle WARNING/CRITICAL logic
         if ($voltage_v <= $GLOBALS['crit_threshold']) {
             $state = STATE_CRITICAL;
             $message = sprintf(
                 "%s: Input_Voltage %.3fV (Threshold: < %.1fV).",
                 $GLOBALS['device_ip'],
                 $voltage_v,
                 $GLOBALS['crit_threshold']
             );
         } elseif ($voltage_v <= $GLOBALS['warn_threshold']) {
             $state = STATE_WARNING;
             $message = sprintf(
                 "%s: Input_Voltage %.3fV (Threshold: < %.1fV).",
                 $GLOBALS['device_ip'],
                 $voltage_v,
                 $GLOBALS['warn_threshold']
             );
         }
         
         // LIBRENMS COMPLIANCE FIX: 
         // 1. Label changed to 'Input_Voltage'.
         // 2. Unit 'V' REMOVED from the value string to allow LibreNMS to assign it to UOM.
         $perfdata = sprintf(
            "| 'Input_Voltage'=%.3f;%.1f;%.1f;0;60", 
            $voltage_v,
            $GLOBALS['warn_threshold'],
            $GLOBALS['crit_threshold']
        );

         return ['state' => $state, 'message' => $message, 'perfdata' => $perfdata];
    }
    
    return ['state' => STATE_UNKNOWN, 'message' => "API response structure invalid: could not find 'inputVoltage' data.", 'perfdata' => ""];
}

/**
 * Logs out of the Grandstream device using the authentication token.
 */
function logout_grandstream($url, $ip, $auth_token) {
    $ch = curl_init();
    
    $post_data = json_encode(['token' => $auth_token]);

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $headers = [
        'Host: ' . $ip,
        'Accept: application/json, text/plain, */*',
        'Authorization: ' . $auth_token,
        'Content-Type: application/json',
        'Origin: http://' . $ip,
        'Referer: http://' . $ip . '/',
        'User-Agent: Mozilla/5.0',
        'X-Requested-With: XMLHttpRequest',
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $http_code === 200;
}


// --- 5. MAIN EXECUTION ---

// Setup global variables
$GLOBALS['warn_threshold'] = $warn_threshold;
$GLOBALS['crit_threshold'] = $crit_threshold;
$GLOBALS['device_ip'] = $device_ip; 

// 1. ATTEMPT LOGIN 
$auth_token = login_grandstream($nonce_url, $login_url, $device_ip, $username, $password);

if (!$auth_token) {
    // Standard error message if login fails
    check_exit(STATE_CRITICAL, "Login failed. Check IP/connectivity or authentication credentials.");
}

// 2. RETRIEVE AND CHECK VOLTAGE
$check_result = check_voltage($voltage_page_url, $auth_token);

// 3. LOGOUT (Critical for freeing the session)
logout_grandstream($logout_url, $device_ip, $auth_token);

// 4. FINAL EXIT
check_exit($check_result['state'], $check_result['message'], $check_result['perfdata']);

?>
