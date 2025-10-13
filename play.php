<?php
/**
 * HLS → HTTP Stream Proxy (minimal version)
 * Usage: hls_to_http_stream.php?url=<m3u8_url>
 */

set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: audio/mpeg');
header('Cache-Control: no-cache');

$url = $_GET['url'] ?? '';
if (!$url) {
    http_response_code(400);
    exit('Missing ?url=');
}

// Resolve relative URLs
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

// Fetch playlist
function fetch($u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'HLS-HTTP-Proxy/1.0'
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}

// Stream segment directly to client
function stream_segment($u) {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'HLS-HTTP-Proxy/1.0',
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FILE => fopen('php://output', 'w')
    ]);
    curl_exec($ch);
    curl_close($ch);
    flush();
}

$played = [];

while (!connection_aborted()) {
    $body = fetch($url);
    if (!$body) { sleep(2); continue; }

    $lines = explode("\n", $body);
    $segments = [];
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l && $l[0] !== '#') $segments[] = $l;
    }

    // handle master playlist
    if (isset($segments[0]) && str_ends_with($segments[0], '.m3u8')) {
        $url = resolve_url($url, $segments[0]);
        continue;
    }

    foreach ($segments as $seg) {
        $seg_url = resolve_url($url, $seg);
        if (isset($played[$seg_url])) continue;
        stream_segment($seg_url);
        $played[$seg_url] = true;
        if (connection_aborted()) exit;
    }

    sleep(2);
}
