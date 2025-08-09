<?php
// guest-token-configurable.php — Configurable version
// Receives configurations via POST and returns JWT in text/plain

header('Content-Type: text/plain; charset=utf-8');

// Check if it's POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed. Use POST.";
    exit;
}

// Get parameters
$supersetUrl = $_POST['superset_url'] ?? '';
$dashboardUuid = $_POST['dashboard_uuid'] ?? '';

// Validations
if (empty($supersetUrl)) {
    http_response_code(400);
    echo "Superset URL is required";
    exit;
}

if (empty($dashboardUuid)) {
    http_response_code(400);
    echo "Dashboard UUID is required";
    exit;
}

if (!filter_var($supersetUrl, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    echo "Invalid Superset URL";
    exit;
}

// ===== CONFIGURATIONS =====
$USER = 'admin';     // Superset user to issue the token
$PASS = '1234';      // password
$REFERER = 'https://domain.com'; // must match ALLOWED_REFERRERS
// =========================

function http($method, $url, $headers = [], $body = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_HEADER         => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'guest-token-configurable/1.0',
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $resp = curl_exec($ch);
    if ($resp === false) {
        http_response_code(502);
        echo "cURL error: " . curl_error($ch);
        curl_close($ch);
        exit;
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $resp];
}

try {
    // 1) Login -> access_token
    $loginPayload = json_encode([
        "provider" => "db",
        "username" => $USER,
        "password" => $PASS,
        "refresh" => false
    ]);
    
    list($code, $resp) = http('POST', "$supersetUrl/api/v1/security/login",
        ["Content-Type: application/json", "Accept: application/json"],
        $loginPayload
    );
    
    if ($code !== 200) {
        http_response_code($code);
        echo "login failed ($code): $resp";
        exit;
    }
    
    $access = json_decode($resp, true)['access_token'] ?? null;
    if (!$access) {
        http_response_code(500);
        echo "no access_token in login response";
        exit;
    }

    // 2) Guest token with Bearer + Referer
    $guestPayload = json_encode([
        "user" => [
            "username" => "guest_username",
            "first_name" => "Guest",
            "last_name" => "User"
        ],
        "resources" => [
            [
                "type" => "dashboard",
                "id" => $dashboardUuid
            ]
        ],
        "rls" => [] // IMPORTANT: Superset 5.x expects empty 'rls'
    ]);
    
    list($code, $resp) = http('POST', "$supersetUrl/api/v1/security/guest_token/",
        [
            "Content-Type: application/json", 
            "Accept: application/json",
            "Authorization: Bearer $access", 
            "Referer: $REFERER"
        ],
        $guestPayload
    );
    
    if ($code !== 200) {
        http_response_code($code);
        echo "guest_token failed ($code): $resp";
        exit;
    }

    $token = json_decode($resp, true)['token'] ?? '';
    if (!$token) {
        http_response_code(500);
        echo "guest token not found";
        exit;
    }

    // Return the token
    echo $token;

} catch (Exception $e) {
    http_response_code(500);
    echo "Internal error: " . $e->getMessage();
} 
