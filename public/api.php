<?php
// MistikAI API Entry Point
// All requests go through this file.

ini_set('display_errors', 0);
error_reporting(E_ALL);

// ======================================================================
// Security & Session
// ======================================================================
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Strict');
session_start();

if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['chat_history'])) {
    $_SESSION['chat_history'] = [];
}

set_exception_handler(function($e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['reply' => '⚠️ System Error: ' . $e->getMessage()]);
    exit;
});

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Europe/Istanbul');

// ======================================================================
// Database & Config
// ======================================================================
$configPaths = [
    __DIR__ . '/../config/db.php',
    dirname(__DIR__) . '/config/db.php'
];

$configLoaded = false;
foreach ($configPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $configLoaded = true;
        break;
    }
}

if (!$configLoaded) {
    exit(json_encode(['reply' => '⚠️ Configuration file not found. Copy config/db.php.example to config/db.php']));
}

global $db, $GROQ_API_KEY, $BERT_API_URL;

// ======================================================================
// Request Handling
// ======================================================================
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

// CSRF Token endpoint
if (isset($input['action']) && $input['action'] === 'get_csrf') {
    exit(json_encode(['csrf_token' => $_SESSION['csrf_token']]));
}

// CSRF Verification
if (!isset($input['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$input['csrf_token'])) {
    http_response_code(403);
    exit(json_encode(['reply' => '⚠️ Security violation.']));
}

// ======================================================================
// Process Message
// ======================================================================
if (isset($input['message']) || isset($input['payload'])) {
    require_once __DIR__ . '/../src/MistikEngine.php';

    $engine = new MistikEngine($db, [
        'groq_api_key'  => $GROQ_API_KEY ?? '',
        'bert_api_url'  => $BERT_API_URL ?? '',
        'llm_model'     => 'llama-3.1-8b-instant',
        'plugin_dir'    => __DIR__ . '/../modules/'
    ]);

    $proc = !empty($input['payload']) ? $input['payload'] : $input['message'];
    echo $engine->process($proc, $proc);
} else {
    echo json_encode(['reply' => 'System active.']);
}
