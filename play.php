<?php
/**
 * hls_radio_adts.php
 * Convert .m3u8 stream → continuous ADTS (AAC) stream (Winamp compatible)
 *
 * Usage: hls_radio_adts.php?url=<m3u8_url>
 */

set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: audio/aac'); // Winamp expects ADTS AAC
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');

$url = $_GET['url'] ?? '';
if (!$url) {
    http_response_code(400);
    exit('Missing ?url=');
}

// Simple curl GET
function curl_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'HLS-ADTS-Radio/1.0'
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}

// Stream file directly to output
function stream_file($u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'HLS-ADTS-Radio/1.0',
        CURLOPT_FILE => fopen('php://output', 'w'),
    ]);
    curl_exec($ch);
    curl_close($ch);
    flush();
}

// Resolve segment URL
function resolve_url($base, $rel) {
    if (parse_url($rel, PHP_URL_SCHEME)) return $rel;
    $p = parse_url($base);
    $scheme = $p['scheme'];
    $host = $p['host'];
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = isset($p['path']) ? $p['path'] : '/';
    if ($rel[0] === '/') return "$scheme://$host$port$rel";
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return "$scheme://$host$port$dir$rel";
}

// Fetch playlist once
$playlist = curl_get($url);
if (!$playlist) exit("Can't load playlist.");

// Parse playlist
$lines = explode("\n", $playlist);
$segments = [];
foreach ($lines as $l) {
    $l = trim($l);
    if ($l && $l[0] !== '#') $segments[] = $l;
}

// Handle master playlist
if (isset($segments[0]) && str_ends_with($segments[0], '.m3u8')) {
    $sub = resolve_url($url, $segments[0]);
    $playlist = curl_get($sub);
    $lines = explode("\n", $playlist);
    $segments = [];
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l && $l[0] !== '#') $segments[] = $l;
    }
}

// Stream all segments sequentially
foreach ($segments as $seg) {
    $seg_url = resolve_url($url, $seg);
    // Only stream AAC or TS (Winamp ignores video if ADTS header present)
    if (!preg_match('/\.(aac|ts)(\?|$)/i', $seg_url)) continue;
    stream_file($seg_url);
    if (connection_aborted()) exit;
}

// For live playlists, you can loop every 5s:
while (!connection_aborted()) {
    sleep(5);
    $newlist = curl_get($url);
    if (!$newlist) continue;
    $lines = explode("\n", $newlist);
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l && $l[0] !== '#' && preg_match('/\.(aac|ts)(\?|$)/i', $l)) {
            stream_file(resolve_url($url, $l));
            if (connection_aborted()) exit;
        }
    }
}
