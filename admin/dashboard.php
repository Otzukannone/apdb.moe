<?php
session_start();

if (empty($_SESSION['apdb_admin'])) {
    header('Location: ./index.php');
    exit;
}
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
        --panel: rgba(255,255,255,0.8);
        --line: rgba(12,14,29,0.18);
      }

      * { box-sizing: border-box; }

      body {
        margin: 0;
        min-height: 100vh;
        font-family: "Sazanami Gothic", "Consolas", "DejaVu Sans Mono", monospace;
        background: var(--paper);
        color: var(--ink);
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .panel {
        width: min(700px, calc(100vw - 40px));
        background: var(--panel);
        border: 1px solid var(--line);
        border-radius: 18px;
        padding: 28px 22px;
      }

      h1 {
        margin: 0 0 14px;
        font-size: clamp(2rem, 3vw, 3rem);
      }

      p {
        margin: 0 0 20px;
      }

      .actions {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
      }

      a, button {
        border: 1px solid rgba(12,14,29,0.18);
        border-radius: 999px;
        background: #0d1323;
        color: #fff;
        padding: 10px 16px;
        text-decoration: none;
        font: inherit;
        cursor: pointer;
      }
    </style>
  </head>
  <body>
    <div class="panel">
      <h1>dashboard</h1>
      <p>you are logged in.</p>
      <div class="actions">
        <a href="../">home</a>
        <a href="./atelier.php">atelierphischers</a>
        <a href="./logout.php">logout</a>
      </div>
    </div>
  </body>
</html>
