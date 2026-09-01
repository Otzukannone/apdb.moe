<?php
session_start();

if (!empty($_SESSION['apdb_admin'])) {
    header('Location: ./dashboard.php');
    exit;
}

$error = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['user'] ?? ''));
    $pass = trim((string) ($_POST['pass'] ?? ''));

    if ($user === 'admin' && $pass === 'admin') {
        $_SESSION['apdb_admin'] = true;
        header('Location: ./dashboard.php');
        exit;
    }

    $error = true;
}
?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>admin — apdb.moe</title>
    <link rel="icon" type="image/png" href="../assets/yuyuk.png" />
    <style>
      :root {
        --ink: #0c0e1d;
        --paper: #f0f0ee;
        --panel: rgba(255,255,255,0.8);
        --panel-border: rgba(12,14,29,0.18);
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
      }

      body {
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
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

      .admin-shell {
        position: relative;
        z-index: 1;
        width: min(540px, calc(100vw - 40px));
      }

      .admin-panel {
        background: var(--panel);
        border: 1px solid var(--panel-border);
        border-radius: 18px;
        padding: 18px 22px 16px;
        box-shadow: 0 0 0 1px rgba(255,255,255,0.15), inset 0 0 0 1px rgba(255,255,255,0.14);
      }

      .admin-panel p {
        margin: 0 0 18px;
        text-align: center;
        color: rgba(12,14,29,0.78);
        font-size: clamp(14px, 1.65vw, 22px);
        letter-spacing: 0.1em;
        text-transform: lowercase;
      }

      form {
        display: grid;
        gap: 12px;
      }

      label {
        display: grid;
        gap: 8px;
        font-size: 0.74rem;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        color: rgba(12,14,29,0.72);
      }

      input {
        width: 100%;
        min-height: 46px;
        border: 1px solid rgba(12,14,29,0.18);
        border-radius: 10px;
        background: rgba(255,255,255,0.18);
        color: var(--ink);
        padding: 10px 14px;
        font: inherit;
        font-size: 1.1rem;
      }

      ::placeholder {
        color: rgba(12,14,29,0.48);
      }

      button {
        margin-top: 8px;
        width: 100%;
        border: 1px solid rgba(12,14,29,0.18);
        border-radius: 999px;
        background: #0d1323;
        color: #fff;
        padding: 14px 18px;
        font: inherit;
        font-size: 1rem;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        cursor: pointer;
      }

      .error {
        margin-top: 6px;
        font-size: 0.8rem;
        letter-spacing: 0.08em;
        color: rgba(180, 20, 20, 0.9);
        text-transform: uppercase;
      }

      .admin-links {
        margin-top: 18px;
        display: flex;
        justify-content: center;
        align-items: center;
      }

      .admin-links a {
        color: rgba(12,14,29,0.8);
        text-decoration: none;
        text-transform: lowercase;
        letter-spacing: 0.1em;
        font-size: 0.9rem;
      }
    </style>
  </head>
  <body>
    <div class="admin-shell">
      <div class="admin-panel">
        <p>no thoughts head empty</p>

        <form method="post" action="./index.php">
          <label>
            user
            <input type="text" name="user" autocomplete="username" placeholder="admin" required />
          </label>

          <label>
            pass
            <input type="password" name="pass" autocomplete="current-password" placeholder="••••••••" required />
          </label>

          <?php if ($error): ?>
            <div class="error">invalid credentials</div>
          <?php endif; ?>

          <button type="submit">enter</button>
        </form>

        <div class="admin-links">
          <a href="../">return</a>
        </div>
      </div>
    </div>
  </body>
</html>
