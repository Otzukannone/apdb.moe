<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Portable polyfills so this page survives shared hosts that lack
// mbstring or run older PHP (these only define if missing).
if (!function_exists('mb_strlen')) {
    function mb_strlen($str, $encoding = null): int
    {
        return strlen((string) $str);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($str, $start, $length = null, $encoding = null): string
    {
        return $length === null ? substr((string) $str, (int) $start) : substr((string) $str, (int) $start, (int) $length);
    }
}
if (!defined('PHP_OS_FAMILY')) {
    define('PHP_OS_FAMILY', stripos(PHP_OS, 'WIN') === 0 ? 'Windows' : (stripos(PHP_OS, 'DARWIN') !== false ? 'Darwin' : 'Linux'));
}

if (empty($_SESSION['apdb_admin'])) {
    header('Location: ./index.php');
    exit;
}

/**
 * Best-effort server (OS) uptime in seconds.
 * Returns null when the host does not expose it.
 */
function dashboardServerUptime()
{
    // linux & most unix: the kernel exposes uptime here
    if (@is_readable('/proc/uptime')) {
        $raw = @file_get_contents('/proc/uptime');
        if (is_string($raw) && preg_match('/^(\d+(?:\.\d+)?)/', trim($raw), $m) && (float) $m[1] > 0) {
            return (int) floor((float) $m[1]);
        }
    }

    // macOS
    if (PHP_OS_FAMILY === 'Darwin') {
        @exec('sysctl -n kern.boottime', $out, $code);
        if ($code === 0 && preg_match('/sec\s*=\s*(\d+)/', (string) ($out[0] ?? ''), $m)) {
            $uptime = time() - (int) $m[1];
            if ($uptime > 0) {
                return $uptime;
            }
        }
    }

    // windows: query WMI through COM first, powershell as fallback
    if (PHP_OS_FAMILY === 'Windows') {
        try {
            $wmi = new COM('winmgmts:{impersonationLevel=impersonate}!//localhost/root/cimv2');
            foreach ($wmi->ExecQuery('SELECT LastBootUpTime FROM Win32_OperatingSystem') as $os) {
                if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', (string) $os->LastBootUpTime, $m)) {
                    $booted = mktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
                    $uptime = time() - $booted;
                    if ($uptime > 0) {
                        return $uptime;
                    }
                }
            }
        } catch (Throwable $error) {
            // COM extension not available, try the next method
        }

        @exec('powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_OperatingSystem).LastBootUpTime.ToString(\'yyyy-MM-dd HH:mm:ss\')"', $out, $code);
        if ($code === 0) {
            $booted = strtotime(trim((string) ($out[0] ?? '')));
            if ($booted !== false) {
                $uptime = time() - $booted;
                if ($uptime > 0) {
                    return $uptime;
                }
            }
        }
    }

    // generic unix fallback
    @exec('uptime -s', $out, $code);
    if ($code === 0) {
        $booted = strtotime(trim((string) ($out[0] ?? '')));
        if ($booted !== false) {
            $uptime = time() - $booted;
            if ($uptime > 0) {
                return $uptime;
            }
        }
    }

    return null;
}

$uptimeSeconds = dashboardServerUptime();
$bootedAt = $uptimeSeconds !== null ? time() - $uptimeSeconds : null;

$loggedInSince = isset($_SESSION['apdb_admin_since']) ? (int) $_SESSION['apdb_admin_since'] : null;

$atelier = null;
try {
    require_once __DIR__ . '/../lib/atelier-db.php';
    $database = atelierDatabase();
    $atelier = $database->query(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(CASE WHEN media_type = 'image' THEN 1 ELSE 0 END), 0) AS images,
                COALESCE(SUM(CASE WHEN media_type = 'video' THEN 1 ELSE 0 END), 0) AS videos
         FROM media_items"
    )->fetch();
} catch (Throwable $error) {
    $atelier = null;
}

$serverSoftware = trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? PHP_OS_FAMILY));
if (mb_strlen($serverSoftware) > 48) {
    $serverSoftware = mb_substr($serverSoftware, 0, 45) . '…';
}

$timezoneLabel = date('T');
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>dashboard — apdb.moe</title>
    <link rel="icon" type="image/png" href="../assets/yuyuk.png" />
    <style>
      :root {
        --ink: #0c0e1d;
        --paper: #f0f0ee;
        --panel: rgba(255,255,255,0.82);
        --panel-border: rgba(12,14,29,0.18);
        --muted: rgba(12,14,29,0.6);
        --danger: rgba(180,20,20,0.85);
        --dot-pitch: 5vw;
        --dot-radius: clamp(3px, 0.6vw, 12px);
      }

      * { box-sizing: border-box; }

      html, body {
        margin: 0;
        min-height: 100vh;
        font-family: "Sazanami Gothic", "Consolas", "DejaVu Sans Mono", monospace;
        background: var(--paper);
        color: var(--ink);
        scrollbar-width: none;
        -ms-overflow-style: none;
      }

      html::-webkit-scrollbar,
      body::-webkit-scrollbar {
        display: none;
      }

      body {
        display: flex;
        flex-direction: column;
      }

      body::before {
        content: "";
        position: fixed;
        inset: 0;
        background-image:
          radial-gradient(circle, rgba(12,14,29,0.55) var(--dot-radius), transparent calc(var(--dot-radius) + 0.5px)),
          radial-gradient(circle, rgba(12,14,29,0.55) var(--dot-radius), transparent calc(var(--dot-radius) + 0.5px));
        background-size: var(--dot-pitch) var(--dot-pitch);
        background-position: 0 0, calc(var(--dot-pitch) / 2) calc(var(--dot-pitch) / 2);
        opacity: 0.8;
        animation: driftDots 14s linear infinite;
        pointer-events: none;
      }

      @keyframes driftDots {
        from {
          background-position: 0 0, calc(var(--dot-pitch) / 2) calc(var(--dot-pitch) / 2);
        }
        to {
          background-position: var(--dot-pitch) var(--dot-pitch), calc(var(--dot-pitch) * 1.5) calc(var(--dot-pitch) * 1.5);
        }
      }

      .shell {
        position: relative;
        z-index: 1;
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: clamp(32px, 6vh, 64px) 20px;
      }

      .panel {
        width: min(760px, 100%);
        background: var(--panel);
        border: 1px solid var(--panel-border);
        border-radius: 18px;
        padding: clamp(44px, 7vw, 76px) clamp(28px, 6vw, 64px);
        box-shadow: 0 0 0 1px rgba(255,255,255,0.15), inset 0 0 0 1px rgba(255,255,255,0.14);
      }

      h1 {
        margin: 0 0 8px;
        font-size: clamp(2.4rem, 5vw, 4rem);
        line-height: 0.95;
        letter-spacing: -0.04em;
      }

      .lede {
        margin: 0 0 30px;
        color: var(--muted);
        font-size: clamp(0.95rem, 1.6vw, 1.1rem);
        letter-spacing: 0.04em;
      }

      .uptime-card {
        border: 1px solid var(--panel-border);
        border-radius: 14px;
        background: rgba(255,255,255,0.55);
        padding: clamp(18px, 3vw, 26px) clamp(18px, 3vw, 30px);
        margin-bottom: 18px;
      }

      .uptime-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 10px;
      }

      .chip-label {
        font-size: 0.74rem;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--muted);
      }

      .uptime-clock {
        font-size: clamp(2.1rem, 6vw, 3.6rem);
        line-height: 1;
        letter-spacing: 0.02em;
        font-variant-numeric: tabular-nums;
      }

      .uptime-since {
        margin-top: 10px;
        color: var(--muted);
        font-size: 0.8rem;
        letter-spacing: 0.06em;
      }

      .stats {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
      }

      .stat {
        border: 1px solid var(--panel-border);
        border-radius: 14px;
        background: rgba(255,255,255,0.55);
        padding: 16px 18px;
        display: grid;
        gap: 4px;
        align-content: start;
      }

      .stat-label {
        font-size: 0.68rem;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--muted);
      }

      .stat-value {
        font-size: clamp(1.05rem, 2vw, 1.35rem);
        font-variant-numeric: tabular-nums;
      }

      .stat-sub {
        font-size: 0.76rem;
        color: var(--muted);
        letter-spacing: 0.04em;
      }

      .footnote {
        margin: 26px 0 0;
        color: var(--muted);
        font-size: 0.78rem;
        letter-spacing: 0.08em;
        text-align: center;
      }

      .bottom-nav {
        position: relative;
        z-index: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-wrap: wrap;
        gap: 12px;
        padding: 18px 20px calc(24px + env(safe-area-inset-bottom, 0px));
      }

      .bottom-nav a {
        padding: 10px 20px;
        border: 1px solid var(--panel-border);
        border-radius: 999px;
        background: rgba(255,255,255,0.6);
        color: var(--ink);
        text-decoration: none;
        font-size: 0.85rem;
        letter-spacing: 0.12em;
        text-transform: lowercase;
      }

      .bottom-nav a:hover { background: #ffffff; }

      .bottom-nav a.nav-logout {
        color: var(--danger);
        border-color: rgba(180,20,20,0.35);
      }

      .bottom-nav a.nav-logout:hover {
        background: rgba(180,20,20,0.08);
      }

      @media (max-width: 560px) {
        .stats { grid-template-columns: 1fr; }
      }

    </style>
  </head>
  <body>
    <main class="shell">
      <section class="panel" aria-label="dashboard">
        <h1>dashboard</h1>
        <p class="lede">you are logged in. the server is alive and warm.</p>

        <div class="uptime-card">
          <div class="uptime-head">
            <span class="chip-label">server uptime</span>
          </div>
          <div class="uptime-clock" id="uptime" data-seconds="<?= $uptimeSeconds !== null ? (int) $uptimeSeconds : '' ?>">—</div>
          <div class="uptime-since">
            <?php if ($bootedAt !== null): ?>
              up since <?= htmlspecialchars(date('Y-m-d H:i:s', $bootedAt), ENT_QUOTES) ?> (<?= htmlspecialchars($timezoneLabel, ENT_QUOTES) ?>)
            <?php else: ?>
              uptime unavailable on this host.
            <?php endif; ?>
          </div>
        </div>

        <div class="stats">
          <div class="stat">
            <span class="stat-label">server clock</span>
            <span class="stat-value" id="clock" data-epoch="<?= time() ?>" data-offset="<?= (int) date('Z') ?>" data-tz="<?= htmlspecialchars(date_default_timezone_get(), ENT_QUOTES) ?>">—</span>
            <span class="stat-sub" id="clock-date"><?= htmlspecialchars(date('Y-m-d'), ENT_QUOTES) ?> · <?= htmlspecialchars($timezoneLabel, ENT_QUOTES) ?></span>
          </div>

          <div class="stat">
            <span class="stat-label">logged in</span>
            <span class="stat-value"><?= $loggedInSince !== null ? htmlspecialchars(date('H:i', $loggedInSince), ENT_QUOTES) : '—' ?></span>
            <span class="stat-sub">
              <?php if ($loggedInSince !== null): ?>
                <?= htmlspecialchars(date('Y-m-d', $loggedInSince), ENT_QUOTES) ?> ·
                <?php $ago = max(0, time() - $loggedInSince); printf('%dh %02dm ago', intdiv($ago, 3600), intdiv($ago % 3600, 60)); ?>
              <?php else: ?>
                older session — no timestamp
              <?php endif; ?>
            </span>
          </div>

          <div class="stat">
            <span class="stat-label">atelierphischers</span>
            <span class="stat-value"><?= $atelier ? htmlspecialchars(number_format((int) $atelier['total']) . ' entries', ENT_QUOTES) : '—' ?></span>
            <span class="stat-sub"><?= $atelier ? htmlspecialchars((int) $atelier['images'] . ' images · ' . (int) $atelier['videos'] . ' videos', ENT_QUOTES) : 'database unavailable' ?></span>
          </div>

          <div class="stat">
            <span class="stat-label">runtime</span>
            <span class="stat-value">PHP <?= htmlspecialchars(PHP_VERSION, ENT_QUOTES) ?></span>
            <span class="stat-sub"><?= htmlspecialchars($serverSoftware, ENT_QUOTES) ?></span>
          </div>
        </div>

        <p class="footnote">remember to water the server. ♪</p>
      </section>
    </main>

    <nav class="bottom-nav" aria-label="site navigation">
      <a href="../">home</a>
      <a href="./atelier.php">atelierphischers</a>
      <a class="nav-logout" href="./logout.php">logout</a>
    </nav>

    <script>
      (function () {
        var uptimeEl = document.getElementById('uptime');
        var clockEl = document.getElementById('clock');
        var dateEl = document.getElementById('clock-date');
        if (!uptimeEl || !clockEl) {
          return;
        }

        var startedMs = Date.now();
        var baseEpochMs = Number(clockEl.dataset.epoch || 0) * 1000;
        var serverOffsetSec = Number(clockEl.dataset.offset || 0);
        var hasUptime = uptimeEl.dataset.seconds !== '';
        var baseUptimeSec = hasUptime ? Number(uptimeEl.dataset.seconds) : 0;

        function pad(value) {
          return (value < 10 ? '0' : '') + value;
        }

        function renderUptime() {
          if (!hasUptime) {
            uptimeEl.textContent = '—';
            return;
          }
          var total = Math.max(0, baseUptimeSec + Math.floor((Date.now() - startedMs) / 1000));
          var days = Math.floor(total / 86400);
          var hours = Math.floor((total % 86400) / 3600);
          var minutes = Math.floor((total % 3600) / 60);
          var seconds = total % 60;
          uptimeEl.textContent = (days > 0 ? days + 'd ' : '') + pad(hours) + ':' + pad(minutes) + ':' + pad(seconds);
        }

        function renderClock() {
          // shift the epoch by the server's UTC offset, then read as UTC:
          // the result is the server's wall-clock time, ticking client-side
          var wall = new Date(baseEpochMs + (Date.now() - startedMs) + serverOffsetSec * 1000);
          clockEl.textContent = pad(wall.getUTCHours()) + ':' + pad(wall.getUTCMinutes()) + ':' + pad(wall.getUTCSeconds());
          if (dateEl) {
            dateEl.textContent = wall.getUTCFullYear() + '-' + pad(wall.getUTCMonth() + 1) + '-' + pad(wall.getUTCDate()) + ' · ' + (clockEl.dataset.tz || '');
          }
        }

        function tick() {
          renderUptime();
          renderClock();
        }

        tick();
        setInterval(tick, 1000);
      })();
    </script>
  </body>
</html>