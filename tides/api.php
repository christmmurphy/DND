<?php
declare(strict_types=1);

// Capture all output so PHP notices/warnings never corrupt JSON responses.
ob_start();

// Convert PHP fatal errors to JSON so the browser sees a real message.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_RECOVERABLE_ERROR], true)) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Server error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')']);
    }
});

// ===== Configuration =====
const BASE_DIR      = __DIR__ . '/data';
const MAX_MAP_BYTES = 12 * 1024 * 1024;
const SECRET        = '';

// ===== Helpers =====
function send_json(array $data, int $status = 200): void {
    // Discard any buffered notices/warnings before sending clean JSON.
    while (ob_get_level()) ob_end_clean();
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

function valid_id(string $id): bool {
    return (bool) preg_match('/^[a-z0-9_-]{1,64}$/', $id);
}

function map_dir(string $id): string {
    return BASE_DIR . '/' . $id;
}

function snap_dir(string $mapId, string $snapId): string {
    return map_dir($mapId) . '/snapshots/' . $snapId;
}

function ensure_base(): void {
    if (!is_dir(BASE_DIR)) @mkdir(BASE_DIR, 0755, true);
    $ht = BASE_DIR . '/.htaccess';
    if (!file_exists($ht)) {
        @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder Allow,Deny\nDeny from all\n</IfModule>\n");
    }
    foreach (['index.html', 'index.php'] as $f) {
        $p = BASE_DIR . '/' . $f;
        if (!file_exists($p)) @file_put_contents($p, '');
    }
}

function ensure_map_dir(string $id): void {
    $dir = map_dir($id);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
}

// ===== Maps index =====
function maps_index_file(): string { return BASE_DIR . '/maps.json'; }

function read_maps(): array {
    $f = maps_index_file();
    if (!file_exists($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function write_maps(array $maps): void {
    file_put_contents(maps_index_file(), json_encode($maps, JSON_UNESCAPED_SLASHES));
}

// ===== Meta per map =====
function meta_file(string $mapId): string { return map_dir($mapId) . '/meta.json'; }
function map_bin(string $mapId): string   { return map_dir($mapId) . '/map.bin'; }
function map_type(string $mapId): string  { return map_dir($mapId) . '/map.type'; }

function read_meta(string $mapId): array {
    $f = meta_file($mapId);
    if (!file_exists($f)) return ['pins' => [], 'zones' => []];
    $d = json_decode((string)file_get_contents($f), true);
    if (!is_array($d)) return ['pins' => [], 'zones' => []];
    return ['pins' => array_values($d['pins'] ?? []), 'zones' => array_values($d['zones'] ?? []), 'cities' => array_values($d['cities'] ?? [])];
}

// ===== Snapshots =====
function snaps_index_file(string $mapId): string { return map_dir($mapId) . '/snapshots.json'; }

function read_snaps(string $mapId): array {
    $f = snaps_index_file($mapId);
    if (!file_exists($f)) return [];
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : [];
}

function write_snaps(string $mapId, array $snaps): void {
    file_put_contents(snaps_index_file($mapId), json_encode(array_values($snaps), JSON_UNESCAPED_SLASHES));
}

// ===== Migration: old flat data/ layout → data/default/ =====
function maybe_migrate(): void {
    $old_meta = BASE_DIR . '/meta.json';
    $old_bin  = BASE_DIR . '/map.bin';
    $old_type = BASE_DIR . '/map.type';
    $maps_f   = maps_index_file();
    if (!file_exists($old_meta) && !file_exists($old_bin)) return;
    if (file_exists($maps_f)) return; // already migrated
    ensure_map_dir('default');
    if (file_exists($old_meta)) @rename($old_meta, meta_file('default'));
    if (file_exists($old_bin))  @rename($old_bin,  map_bin('default'));
    if (file_exists($old_type)) @rename($old_type, map_type('default'));
    write_maps([['id' => 'default', 'name' => 'Menagerie Coast']]);
}

// ===== Boot =====
ensure_base();
maybe_migrate();

// ===== Resolve map param =====
$mapId = isset($_GET['map']) ? trim((string)$_GET['map']) : '';

// ===== Routing =====
$action = $_GET['action'] ?? '';

switch ($action) {

    // ------------------------------------------------------------------
    case 'listmaps': {
        $maps = read_maps();
        $out = [];
        foreach ($maps as $m) {
            $id = $m['id'];
            $out[] = [
                'id'         => $id,
                'name'       => $m['name'],
                'hasMap'     => file_exists(map_bin($id)),
                'mapVersion' => file_exists(map_bin($id)) ? filemtime(map_bin($id)) : 0,
                'version'    => file_exists(meta_file($id)) ? filemtime(meta_file($id)) : 0,
            ];
        }
        send_json(['maps' => $out]);
    }

    // ------------------------------------------------------------------
    case 'newmap': {
        require_auth();
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $name = trim((string)($body['name'] ?? 'New Map'));
        if ($name === '') $name = 'New Map';
        // generate a unique slug
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim($slug, '-') ?: 'map';
        $slug = substr($slug, 0, 40);
        $maps = read_maps();
        $existing = array_column($maps, 'id');
        $base = $slug; $n = 2;
        while (in_array($slug, $existing, true)) { $slug = $base . '-' . $n++; }
        ensure_map_dir($slug);
        $maps[] = ['id' => $slug, 'name' => $name];
        write_maps($maps);
        send_json(['ok' => true, 'id' => $slug, 'name' => $name]);
    }

    // ------------------------------------------------------------------
    case 'renamemap': {
        require_auth();
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $name = trim((string)($body['name'] ?? ''));
        if ($name === '') send_json(['error' => 'Name required'], 400);
        $maps = read_maps();
        $found = false;
        foreach ($maps as &$m) {
            if ($m['id'] === $mapId) { $m['name'] = $name; $found = true; break; }
        }
        unset($m);
        if (!$found) send_json(['error' => 'Map not found'], 404);
        write_maps($maps);
        send_json(['ok' => true]);
    }

    // ------------------------------------------------------------------
    case 'deletemap': {
        require_auth();
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        $maps = read_maps();
        if (count($maps) <= 1) send_json(['error' => 'Cannot delete the last map'], 400);
        $maps = array_values(array_filter($maps, fn($m) => $m['id'] !== $mapId));
        write_maps($maps);
        // Remove map files (keep snapshots for safety, just remove active data)
        @unlink(map_bin($mapId));
        @unlink(map_type($mapId));
        @unlink(meta_file($mapId));
        send_json(['ok' => true]);
    }

    // ------------------------------------------------------------------
    case 'reordermaps': {
        require_auth();
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $order = is_array($body['order'] ?? null) ? $body['order'] : null;
        if (!$order) send_json(['error' => 'order array required'], 400);
        $maps = read_maps();
        $indexed = [];
        foreach ($maps as $m) $indexed[$m['id']] = $m;
        $reordered = [];
        foreach ($order as $id) {
            if (isset($indexed[$id])) $reordered[] = $indexed[$id];
        }
        // append any maps not in the order list
        foreach ($maps as $m) {
            if (!in_array($m['id'], $order, true)) $reordered[] = $m;
        }
        write_maps($reordered);
        send_json(['ok' => true]);
    }

    // ------------------------------------------------------------------
    case 'load': {
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        $meta = read_meta($mapId);
        send_json([
            'pins'       => $meta['pins'],
            'zones'      => $meta['zones'],
            'version'    => file_exists(meta_file($mapId)) ? filemtime(meta_file($mapId)) : 0,
            'hasMap'     => file_exists(map_bin($mapId)),
            'mapVersion' => file_exists(map_bin($mapId)) ? filemtime(map_bin($mapId)) : 0,
        ]);
    }

    // ------------------------------------------------------------------
    case 'savemeta': {
        require_auth();
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($body)) send_json(['error' => 'Invalid JSON body'], 400);
        ensure_map_dir($mapId);
        $payload = ['pins' => array_values($body['pins'] ?? []), 'zones' => array_values($body['zones'] ?? []), 'cities' => array_values($body['cities'] ?? [])];
        $tmp = meta_file($mapId) . '.tmp';
        if (file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES)) === false)
            send_json(['error' => 'Could not write meta'], 500);
        if (!@rename($tmp, meta_file($mapId))) { @unlink($tmp); send_json(['error' => 'Could not finalise meta'], 500); }
        clearstatcache(true, meta_file($mapId));
        send_json(['ok' => true, 'version' => filemtime(meta_file($mapId))]);
    }

    // ------------------------------------------------------------------
    case 'savemap': {
        require_auth();
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $dataUrl = is_array($body) ? (string)($body['data'] ?? '') : '';
        unset($body);

        // Split at the comma first so the regex only runs on the tiny header,
        // not the multi-megabyte base64 string (avoids PCRE backtrack-limit errors).
        $comma = strpos($dataUrl, ',');
        if ($comma === false) send_json(['error' => 'Invalid data URL'], 400);
        $urlHeader = substr($dataUrl, 0, $comma);
        if (!preg_match('/^data:(image\/[a-z0-9+.\-]+);base64$/i', $urlHeader, $m))
            send_json(['error' => 'Invalid data URL'], 400);
        $contentType = $m[1];
        $b64 = substr($dataUrl, $comma + 1);
        unset($dataUrl, $urlHeader, $m);

        $binary = base64_decode($b64, true);
        unset($b64);
        if ($binary === false) send_json(['error' => 'Invalid base64'], 400);
        if (strlen($binary) > MAX_MAP_BYTES) send_json(['error' => 'Map exceeds ' . (MAX_MAP_BYTES / 1024 / 1024) . ' MB'], 413);
        ensure_map_dir($mapId);
        if (file_put_contents(map_bin($mapId), $binary) === false)
            send_json(['error' => 'Could not write map (check folder permissions on ' . map_dir($mapId) . ')'], 500);
        unset($binary);
        @file_put_contents(map_type($mapId), $contentType);
        clearstatcache(true, map_bin($mapId));
        send_json(['ok' => true, 'mapVersion' => filemtime(map_bin($mapId)), 'size' => filesize(map_bin($mapId))]);
    }

    // ------------------------------------------------------------------
    case 'getmap': {
        if (!$mapId || !valid_id($mapId)) { http_response_code(404); exit; }
        $bin = map_bin($mapId);
        if (!file_exists($bin)) { http_response_code(404); exit; }
        $type = file_exists(map_type($mapId))
            ? trim((string)file_get_contents(map_type($mapId)))
            : 'application/octet-stream';
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($bin));
        header('Cache-Control: public, max-age=300');
        readfile($bin);
        exit;
    }

    // ------------------------------------------------------------------
    case 'clearmap': {
        require_auth();
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        @unlink(map_bin($mapId));
        @unlink(map_type($mapId));
        send_json(['ok' => true]);
    }

    // ------------------------------------------------------------------
    case 'listsnapshots': {
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        send_json(['snapshots' => read_snaps($mapId)]);
    }

    // ------------------------------------------------------------------
    case 'savesnapshot': {
        require_auth();
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        $body = json_decode(file_get_contents('php://input') ?: '', true);
        $label = trim((string)($body['label'] ?? ''));
        if ($label === '') $label = date('Y-m-d H:i');
        $snapId = 'snap_' . time() . '_' . substr(md5(uniqid('', true)), 0, 6);
        $sdir = snap_dir($mapId, $snapId);
        @mkdir($sdir, 0755, true);
        // Copy current meta
        $src_meta = meta_file($mapId);
        if (file_exists($src_meta)) @copy($src_meta, $sdir . '/meta.json');
        else file_put_contents($sdir . '/meta.json', json_encode(['pins' => [], 'zones' => []]));
        // Copy current map image
        $src_bin  = map_bin($mapId);
        $src_type = map_type($mapId);
        if (file_exists($src_bin)) {
            @copy($src_bin, $sdir . '/map.bin');
            if (file_exists($src_type)) @copy($src_type, $sdir . '/map.type');
        }
        $snaps = read_snaps($mapId);
        $snaps[] = ['id' => $snapId, 'label' => $label, 'ts' => time()];
        write_snaps($mapId, $snaps);
        send_json(['ok' => true, 'id' => $snapId, 'label' => $label, 'ts' => time()]);
    }

    // ------------------------------------------------------------------
    case 'loadsnapshot': {
        require_auth();
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        $snapId = trim((string)($_GET['snapshot'] ?? ''));
        if (!preg_match('/^snap_[0-9]+_[a-f0-9]+$/', $snapId)) send_json(['error' => 'Bad snapshot id'], 400);
        $sdir = snap_dir($mapId, $snapId);
        if (!is_dir($sdir)) send_json(['error' => 'Snapshot not found'], 404);
        // Restore meta
        $snap_meta = $sdir . '/meta.json';
        if (file_exists($snap_meta)) @copy($snap_meta, meta_file($mapId));
        // Restore map
        $snap_bin  = $sdir . '/map.bin';
        $snap_type = $sdir . '/map.type';
        if (file_exists($snap_bin)) {
            @copy($snap_bin, map_bin($mapId));
            if (file_exists($snap_type)) @copy($snap_type, map_type($mapId));
        } else {
            @unlink(map_bin($mapId));
            @unlink(map_type($mapId));
        }
        clearstatcache();
        send_json([
            'ok'         => true,
            'version'    => file_exists(meta_file($mapId)) ? filemtime(meta_file($mapId)) : 0,
            'mapVersion' => file_exists(map_bin($mapId)) ? filemtime(map_bin($mapId)) : 0,
            'hasMap'     => file_exists(map_bin($mapId)),
        ]);
    }

    // ------------------------------------------------------------------
    case 'deletesnapshot': {
        require_auth();
        if (!$mapId || !valid_id($mapId)) send_json(['error' => 'Bad map id'], 400);
        $snapId = trim((string)($_GET['snapshot'] ?? ''));
        if (!preg_match('/^snap_[0-9]+_[a-f0-9]+$/', $snapId)) send_json(['error' => 'Bad snapshot id'], 400);
        $snaps = array_values(array_filter(read_snaps($mapId), fn($s) => $s['id'] !== $snapId));
        write_snaps($mapId, $snaps);
        // Remove files
        $sdir = snap_dir($mapId, $snapId);
        foreach (['meta.json', 'map.bin', 'map.type'] as $f) @unlink($sdir . '/' . $f);
        @rmdir($sdir);
        send_json(['ok' => true]);
    }

    // ------------------------------------------------------------------
    default:
        send_json(['error' => 'Unknown action'], 400);
}
