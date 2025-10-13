<?php
/**
 * radio.php
 * Fixed HLS (.m3u8) → ADTS (AAC) HTTP stream
 * Winamp/VLC compatible
 */

set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: audio/aac');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Connection: keep-alive');

/* ================================================
   🔗 THAY LINK M3U8 CỦA BẠN Ở DÒNG DƯỚI NÀY
   ================================================ */
$M3U8_URL = "https://play.vovgiaothong.vn/live/gthn2/playlist.m3u8";

/* ================================================= */

function curl_get($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'Fixed-HLS-Radio/1.0'
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}

function stream_file($u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Fixed-HLS-Radio/1.0',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FILE => fopen('php://output', 'w')
    ]);
    curl_exec($ch);
    curl_close($ch);
    flush();
}

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

// --- Load playlist ---
$playlist = curl_get($M3U8_URL);
if (!$playlist) exit("Cannot load playlist.");

$lines = explode("\n", $playlist);
$segments = [];
foreach ($lines as $l) {
    $l = trim($l);
    if ($l && $l[0] !== '#') $segments[] = $l;
}

// Handle master playlist
if (isset($segments[0]) && str_ends_with($segments[0], '.m3u8')) {
    $sub = resolve_url($M3U8_URL, $segments[0]);
    $playlist = curl_get($sub);
    $lines = explode("\n", $playlist);
    $segments = [];
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l && $l[0] !== '#') $segments[] = $l;
    }
}

$seen = [];

while (!connection_aborted()) {
    foreach ($segments as $seg) {
        $seg_url = resolve_url($M3U8_URL, $seg);
        if (isset($seen[$seg_url])) continue;
        if (!preg_match('/\.(aac|ts)(\?|$)/i', $seg_url)) continue;
        $seen[$seg_url] = true;
        stream_file($seg_url);
        if (connection_aborted()) exit;
    }
    sleep(3);
    $new = curl_get($M3U8_URL);
    if ($new) {
        $lines = explode("\n", $new);
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l && $l[0] !== '#' && preg_match('/\.(aac|ts)(\?|$)/i', $l)) {
                $seg_url = resolve_url($M3U8_URL, $l);
                if (!isset($seen[$seg_url])) {
                    $seen[$seg_url] = true;
                    stream_file($seg_url);
                }
            }
        }
    }
}
