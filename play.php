<?php
/**
 * hls_to_http_stream.php
 * Proxy HLS (m3u8) --> continuous HTTP stream
 *
 * Usage: hls_to_http_stream.php?url=<m3u8_url>&referrer=<optional>
 *
 * Notes:
 * - set_time_limit(0) to allow long connections
 * - ignore_user_abort(true) to keep streaming even if client disconnects briefly
 * - This is a simple implementation for many HLS streams. Encrypted streams won't work.
 */

set_time_limit(0);
ignore_user_abort(true);
ob_implicit_flush(true);
while (ob_get_level() > 0) ob_end_flush();

// Get parameters
$m3u8 = isset($_GET['url']) ? trim($_GET['url']) : '';
$referrer = isset($_GET['referrer']) ? $_GET['referrer'] : '';
$userAgent = 'Mozilla/5.0 (compatible; HLS-HTTP-Proxy/1.0)';

// Basic validation
if (!$m3u8) {
    http_response_code(400);
    echo "Missing ?url=";
    exit;
}

// Send headers for raw audio stream. No Content-Length -> chunked by webserver
header_remove(); // remove default headers
header('Content-Type: audio/mpeg'); // best-effort; some streams may be audio/aac or video/MP2T
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('Connection: keep-alive'); // encourage persistent

// Helper: fetch URL via cURL, return array('code'=>int,'body'=>string,'headers'=>array)
function http_fetch($url, $opts = []) {
    $ch = curl_init();
    $headers = [];
    $ua = isset($opts['user_agent']) ? $opts['user_agent'] : 'Mozilla/5.0 (compatible; HLS-HTTP-Proxy/1.0)';
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, $ua);
    curl_setopt($ch, CURLOPT_TIMEOUT, isset($opts['timeout']) ? $opts['timeout'] : 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if (!empty($opts['referrer'])) {
        curl_setopt($ch, CURLOPT_REFERER, $opts['referrer']);
    }
    if (!empty($opts['range'])) {
        curl_setopt($ch, CURLOPT_RANGE, $opts['range']);
    }
    // Execute
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        $code = curl_errno($ch) ?: 0;
        curl_close($ch);
        return ['code'=>0,'body'=>'','error'=>$err];
    }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $hdr = substr($resp, 0, $header_size);
    $body = substr($resp, $header_size);
    // parse headers
    $hdr_lines = preg_split("/\r\n|\n|\r/", $hdr);
    foreach ($hdr_lines as $h) {
        if (strpos($h, ':') !== false) {
            list($k,$v) = explode(':', $h, 2);
            $headers[trim($k)] = trim($v);
        }
    }
    curl_close($ch);
    return ['code'=>$http_code,'body'=>$body,'headers'=>$headers];
}

// Helper: resolve relative segment URL against playlist URL
function resolve_url($base, $rel) {
    // if rel is absolute, return
    if (parse_url($rel, PHP_URL_SCHEME) !== null) return $rel;
    // build base parts
    $p = parse_url($base);
    $scheme = $p['scheme'];
    $host = $p['host'];
    $port = isset($p['port']) ? ':' . $p['port'] : '';
    $path = isset($p['path']) ? $p['path'] : '/';
    // if rel starts with '/', it's root-relative
    if (strpos($rel, '/') === 0) {
        return "$scheme://$host$port" . $rel;
    }
    // otherwise use base directory
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    $abs = "$scheme://$host$port" . $dir . $rel;
    // normalize ../ and ./
    $parts = parse_url($abs);
    $pathParts = explode('/', preg_replace('#/+#','/', $parts['path']));
    $newParts = [];
    foreach ($pathParts as $segment) {
        if ($segment === '..') {
            array_pop($newParts);
        } elseif ($segment === '.') {
            continue;
        } else {
            $newParts[] = $segment;
        }
    }
    $normalizedPath = implode('/', $newParts);
    if ($normalizedPath === '') $normalizedPath = '/';
    $portPart = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $parts['scheme'] . '://' . $parts['host'] . $portPart . $normalizedPath;
}

// Track which segments were already streamed to avoid repeats
$streamed = [];

// Main loop: poll playlist, stream new segments
$playlist_url = $m3u8;
$playlist_fetch_interval = 3; // seconds between playlist fetches for live streams
$max_stall_iterations = 120; // if no new segments for long, exit
$stall_count = 0;

while (!connection_aborted()) {
    $pl = http_fetch($playlist_url, ['user_agent'=>$userAgent, 'referrer'=>$referrer, 'timeout'=>10]);
    if ($pl['code'] >= 200 && $pl['code'] < 400 && $pl['body'] !== '') {
        $content = $pl['body'];
        $lines = preg_split("/\r\n|\n|\r/", $content);
        $candidates = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            // non-comment lines are URIs (segments or nested playlists)
            $candidates[] = $line;
        }
        // If the playlist contains another m3u8 (variant), use the first one
        // (common for master playlists)
        $firstIsPlaylist = false;
        foreach ($candidates as $c) {
            if (preg_match('/\.m3u8($|\?)/i', $c)) {
                $playlist_url = resolve_url($playlist_url, $c);
                $firstIsPlaylist = true;
                break;
            }
        }
        if ($firstIsPlaylist) {
            // immediately continue to fetch the variant playlist
            sleep(0);
            continue;
        }

        // For each candidate segment, resolve URL and stream if not already sent
        $newSegmentFound = false;
        foreach ($candidates as $seg) {
            $seg_url = resolve_url($playlist_url, $seg);
            if (isset($streamed[$seg_url])) continue; // already streamed
            // fetch and stream this segment
            $segFetch = http_fetch($seg_url, ['user_agent'=>$userAgent, 'referrer'=>$referrer, 'timeout'=>20]);
            if ($segFetch['code'] >= 200 && $segFetch['body'] !== '') {
                // Output raw bytes immediately
                echo $segFetch['body'];
                // flush output buffers
                @flush();
                @ob_flush();
                $streamed[$seg_url] = time();
                $newSegmentFound = true;
                // small sleep to avoid tight loop — the segment itself should take time to transmit
                usleep(10000);
                // check if client still connected
                if (connection_aborted()) break 2;
            } else {
                // failed to fetch segment: maybe transient, skip for now
                // continue to next segment
                continue;
            }
        }

        if ($newSegmentFound) {
            $stall_count = 0;
        } else {
            $stall_count++;
            if ($stall_count > $max_stall_iterations) {
                // no new segments for too long; stop
                break;
            }
            // wait a bit for live playlist to update
            sleep($playlist_fetch_interval);
        }
    } else {
        // failed to fetch playlist; wait and retry a few times
        sleep(2);
        $stall_count++;
        if ($stall_count > $max_stall_iterations) break;
    }
}

// End of stream
exit;
?>
