<?php
/**
 * hls_to_adts_stream.php
 * Convert HLS (.m3u8) into continuous ADTS (AAC) HTTP stream
 * Usage: hls_to_adts_stream.php?url=<m3u8_url>
 */

set_time_limit(0);
ignore_user_abort(true);
header("Content-Type: audio/aac"); // ADTS format
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

$url = $_GET['url'] ?? '';
if (!$url) {
    http_response_code(400);
    exit("Missing ?url=");
}

// --- Helper: fetch playlist ---
function fetch_playlist($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => "HLS-to-ADTS/1.0"
    ]);
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

// --- Helper: resolve relative segment URL ---
function resolve_url($base, $rel) {
    if (parse_url($rel, PHP_URL_SCHEME)) return $rel;
    $p = parse_url($base);
    $scheme = $p["scheme"];
    $host = $p["host"];
    $port = isset($p["port"]) ? ":" . $p["port"] : "";
    $path = isset($p["path"]) ? $p["path"] : "/";
    if ($rel[0] === "/") return "$scheme://$host$port$rel";
    $dir = preg_replace("#/[^/]*$#", "/", $path);
    return "$scheme://$host$port$dir$rel";
}

// --- Helper: stream segment directly to client ---
function stream_aac_segment($seg_url) {
    $ch = curl_init($seg_url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => "HLS-to-ADTS/1.0",
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FILE => fopen("php://output", "w")
    ]);
    curl_exec($ch);
    curl_close($ch);
    flush();
}

$seen = [];

while (!connection_aborted()) {
    $body = fetch_playlist($url);
    if (!$body) {
        sleep(2);
        continue;
    }

    $lines = explode("\n", $body);
    $segments = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") continue;
        $segments[] = $line;
    }

    // handle master playlist
    if (isset($segments[0]) && str_ends_with($segments[0], ".m3u8")) {
        $url = resolve_url($url, $segments[0]);
        continue;
    }

    // stream new AAC segments
    foreach ($segments as $seg) {
        $seg_url = resolve_url($url, $seg);
        if (isset($seen[$seg_url])) continue;

        // only stream .aac files (ADTS compatible)
        if (!preg_match('/\.aac($|\?)/i', $seg_url)) continue;

        $seen[$seg_url] = true;
        stream_aac_segment($seg_url);

        if (connection_aborted()) exit;
    }

    sleep(2);
}
