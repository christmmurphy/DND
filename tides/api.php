<?php
declare(strict_types=1);

// ===== Configuration =====
const DATA_DIR      = __DIR__ . '/data';
const META_FILE     = DATA_DIR . '/meta.json';
const MAP_FILE      = DATA_DIR . '/map.bin';
const MAP_TYPE_FILE = DATA_DIR . '/map.type';
const MAX_MAP_BYTES = 12 * 1024 * 1024; // 12 MB hard ceiling

// Optional shared secret. Leave as '' to allow open writes (anyone with the URL
// can edit). To require a secret, set it here AND in index.html (const SECRET).
// Clients send the value in the X-Secret header for write actions.
const SECRET = '';

// ===== Setup =====
function ensure_data_dir(): void {
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    // Block direct browsing of the data dir (Apache).
    $ht = DATA_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder Allow,Deny\nDeny from all\n</IfModule>\n");
    }
    // Empty index files prevent directory listings on Nginx.
    foreach (['index.html', 'index.php'] as $f) {
        $p = DATA_DIR . '/' . $f;
        if (!file_exists($p)) @file_put_contents($p, '');
    }
}
ensure_data_dir();

// ===== Helpers =====
function send_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_auth(): void {
    if (SECRET === '') return;
    $provided = $_SERVER['HTTP_X_SECRET'] ?? '';
    if (!is_string($provided) || !hash_equals(SECRET, $provided)) {
        send_json(['error' => 'Forbidden'], 403);
    }
}

function read_meta(): array {
    if (!file_exists(META_FILE)) {
        return ['pins' => [], 'zones' => []];
    }
    $raw = @file_get_contents(META_FILE);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        return ['pins' => [], 'zones' => []];
    }
    return [
        'pins'  => array_values($data['pins']  ?? []),
        'zones' => array_values($data['zones'] ?? []),
    ];
}

// ===== Routing =====
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'load': {
        $meta = read_meta();
        send_json([
            'pins'        => $meta['pins'],
            'zones'       => $meta['zones'],
            'version'     => file_exists(META_FILE) ? filemtime(META_FILE) : 0,
            'hasMap'      => file_exists(MAP_FILE),
            'mapVersion'  => file_exists(MAP_FILE) ? filemtime(MAP_FILE) : 0,
        ]);
    }

    case 'savemeta': {
        require_auth();
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body)) {
            send_json(['error' => 'Invalid JSON body'], 400);
        }
        $payload = [
            'pins'  => array_values($body['pins']  ?? []),
            'zones' => array_values($body['zones'] ?? []),
        ];
        $tmp = META_FILE . '.tmp';
        if (file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES)) === false) {
            send_json(['error' => 'Could not write meta'], 500);
        }
        if (!@rename($tmp, META_FILE)) {
            @unlink($tmp);
            send_json(['error' => 'Could not finalise meta'], 500);
        }
        clearstatcache(true, META_FILE);
        send_json(['ok' => true, 'version' => filemtime(META_FILE)]);
    }

    case 'savemap': {
        require_auth();
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $dataUrl = is_array($body) ? (string)($body['data'] ?? '') : '';
        if (!preg_match('/^data:(image\/[a-z0-9+.\-]+);base64,(.+)$/i', $dataUrl, $m)) {
            send_json(['error' => 'Invalid data URL'], 400);
        }
        $contentType = $m[1];
        $binary = base64_decode($m[2], true);
        if ($binary === false) {
            send_json(['error' => 'Invalid base64'], 400);
        }
        if (strlen($binary) > MAX_MAP_BYTES) {
            send_json(['error' => 'Map exceeds ' . (MAX_MAP_BYTES / 1024 / 1024) . ' MB'], 413);
        }
        if (file_put_contents(MAP_FILE, $binary) === false) {
            send_json(['error' => 'Could not write map'], 500);
        }
        @file_put_contents(MAP_TYPE_FILE, $contentType);
        clearstatcache(true, MAP_FILE);
        send_json(['ok' => true, 'mapVersion' => filemtime(MAP_FILE), 'size' => strlen($binary)]);
    }

    case 'getmap': {
        if (!file_exists(MAP_FILE)) {
            http_response_code(404);
            exit;
        }
        $type = file_exists(MAP_TYPE_FILE)
            ? trim((string)file_get_contents(MAP_TYPE_FILE))
            : 'application/octet-stream';
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize(MAP_FILE));
        header('Cache-Control: public, max-age=300');
        readfile(MAP_FILE);
        exit;
    }

    case 'clearmap': {
        require_auth();
        @unlink(MAP_FILE);
        @unlink(MAP_TYPE_FILE);
        send_json(['ok' => true]);
    }

    default:
        send_json(['error' => 'Unknown action'], 400);
}
