<?php
// dev-range-router.php — optional router for PHP's built-in server (php -S).
// PHP's built-in server does NOT support HTTP Range requests, which breaks
// forward-seeking in <video> (browsers need a 206 Partial Content to jump
// ahead to unbuffered parts of a file). A real web server (Apache/nginx)
// already handles this, so this router is only needed for local dev:
//
//   php -S localhost:3000 -t e:\apdb\code\apdb.moe e:\apdb\code\apdb.moe\dev-range-router.php
//
// It answers Range requests for static files with a 206 and lets everything
// else fall through to the built-in server's normal handling.

$docroot = realpath(__DIR__);
$uri = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$uri = rawurldecode($uri);
$requested = ($docroot !== null ? $docroot : '') . '/' . preg_replace('/^\/+/', '', $uri);
$fullPath = realpath($requested);

// only serve files that actually live inside the site root
if ($fullPath !== null && is_file($fullPath) && is_readable($fullPath)) {
    $fullPath = (string) $fullPath;
    $range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '') {
        $size = (int) filesize($fullPath);
        if ($size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m)) {
            $rawStart = (string) $m[1];
            $rawEnd = (string) $m[2];
            if ($rawStart === '') {
                $suffixLength = $rawEnd === '' ? $size : (int) $rawEnd;
                $start = max(0, $size - $suffixLength);
                $end = $size - 1;
            } else {
                $start = (int) $rawStart;
                $end = $rawEnd === '' ? $size - 1 : min((int) $rawEnd, $size - 1);
            }

            if ($start <= $end && $start < $size) {
                $length = $end - $start + 1;
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = (string) ($finfo->file($fullPath) ?: 'application/octet-stream');
                $body = file_get_contents($fullPath, false, null, $start, $length);
                http_response_code(206);
                header('Content-Type: ' . $mime);
                header('Accept-Ranges: bytes');
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
                header('Content-Length: ' . $length);
                header('Cache-Control: no-cache');
                return (string) $body;
            }

            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            header('Accept-Ranges: bytes');
            header('Content-Type: text/plain');
            return '';
        }
    }
}

// not a file, unreadable, or no Range header: let the built-in server handle it
return false;