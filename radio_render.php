<?php
/**
 * radio_render.php — optimized for Render.com
 * Fixed HLS (.m3u8) → ADTS stream, continuous flushing
 */

set_time_limit(0);
ignore_user_abort(true);
ini_set('output_buffering', 'off');
ini_set('zlib.output_compression', '0');
header('Content-Type: audio/aac');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Connection: keep-alive');

/* 🔗 LINK M3U8 CỐ ĐỊNH */
$M3U8_URL = "https://play.vovgiaothong.vn/live/gthn2/playlist.m3u8";

/* --- Helper: tải nội dung nhỏ (playlist) --- */
function get_text($url) {
    $opts = [
        'http' => [
            'timeout' => 5,
            'user_agent' => 'RenderRadio/1.0'
        ]
    ];
    return @file_get_contents($url, false, stream_context_create($opts));
}

/* --- Resolve URL tương đối --- */
function join_url($base, $rel) {
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

/* --- Stream segment ra output --- */
function stream_segment($url) {
    $ctx = stream_context_create(['http' => ['user_agent' => 'RenderRadio/1.0', 'timeout' => 10]]);
    $fp = @fopen($url, 'rb', false, $ctx);
    if (!$fp) return;
    while (!feof($fp)) {
        $buf = fread($fp, 8192);
        if ($buf === false) break;
        echo $buf;
        @ob_flush();
        @flush();
        if (connection_aborted()) {
            fclose($fp);
            exit;
        }
    }
    fclose($fp);
}

/* --- Load playlist --- */
$playlist = get_text($M3U8_URL);
if (!$playlist) {
    echo "Cannot load playlist";
    exit;
}

$lines = explode("\n", $playlist);
$segments = [];

foreach ($lines as $l) {
    $l = trim($l);
    if ($l && $l[0] !== '#') $segments[] = $l;
}

if (isset($segments[0]) && str_ends_with($segments[0], '.m3u8')) {
    $sub = join_url($M3U8_URL, $segments[0]);
    $playlist = get_text($sub);
    $lines = explode("\n", $playlist);
    $segments = [];
    foreach ($lines as $l) {
        $l = trim($l);
        if ($l && $l[0] !== '#') $segments[] = $l;
    }
}

/* --- Main loop --- */
$played = [];
while (!connection_aborted()) {
    foreach ($segments as $seg) {
        $seg_url = join_url($M3U8_URL, $seg);
        if (isset($played[$seg_url])) continue;
        $played[$seg_url] = true;
        stream_segment($seg_url);
        if (connection_aborted()) exit;
    }

    // Giữ kết nối sống
    echo " "; // gửi space để tránh timeout
    @ob_flush();
    @flush();
    sleep(3);

    // Cập nhật playlist mới
    $playlist = get_text($M3U8_URL);
    if ($playlist) {
        $lines = explode("\n", $playlist);
        foreach ($lines as $l) {
            $l = trim($l);
            if ($l && $l[0] !== '#') {
                $seg_url = join_url($M3U8_URL, $l);
                if (!isset($played[$seg_url])) {
                    $played[$seg_url] = true;
                    stream_segment($seg_url);
                }
            }
        }
    }
}
