<?php
/**
 * Brikkin Command Deck — one-time installer.
 * Creates the MySQL tables, writes config.php and the first admin. Refuses to run again once installed.
 */
declare(strict_types=1);
header('X-Content-Type-Options: nosniff');
$cfgFile = __DIR__ . '/config.php';
$msg = ''; $done = false;

function schema(string $driver): array {
  if ($driver === 'sqlite') {
    $id = 'INTEGER PRIMARY KEY AUTOINCREMENT'; $tail = ''; $txt = 'TEXT';
  } else {
    $id = 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'; $tail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'; $txt = 'LONGTEXT';
  }
  return [
    "CREATE TABLE IF NOT EXISTS cd_users (id $id, email VARCHAR(190) NOT NULL UNIQUE, name VARCHAR(190) NOT NULL DEFAULT '', role VARCHAR(10) NOT NULL DEFAULT 'user',
       pass_hash VARCHAR(255) NOT NULL DEFAULT '', legacy_salt VARCHAR(100) NULL, active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL)$tail",
    "CREATE TABLE IF NOT EXISTS cd_sessions (token_hash CHAR(64) NOT NULL PRIMARY KEY, user_id INT NOT NULL, expires_at DATETIME NOT NULL)$tail",
    "CREATE TABLE IF NOT EXISTS cd_login_log (id $id, logged_at DATETIME NOT NULL, email VARCHAR(190) NOT NULL DEFAULT '', name VARCHAR(190) NOT NULL DEFAULT '', result VARCHAR(60) NOT NULL DEFAULT '')$tail",
    "CREATE TABLE IF NOT EXISTS cd_prompts (id $id, email VARCHAR(190) NOT NULL DEFAULT 'ALL', message TEXT NOT NULL, days VARCHAR(100) NOT NULL DEFAULT 'Daily')$tail",
    "CREATE TABLE IF NOT EXISTS cd_projects (id $id, name VARCHAR(190) NOT NULL UNIQUE, brand VARCHAR(190) NULL, position INT NOT NULL DEFAULT 0, created_at DATETIME NOT NULL)$tail",
    "CREATE TABLE IF NOT EXISTS cd_columns (id $id, project_id INT NOT NULL, position INT NOT NULL DEFAULT 0, name VARCHAR(190) NOT NULL, list_values TEXT NULL)$tail",
    "CREATE TABLE IF NOT EXISTS cd_rows (id $id, project_id INT NOT NULL, position INT NOT NULL DEFAULT 0, data $txt NOT NULL, updated_at DATETIME NOT NULL)$tail",
    "CREATE TABLE IF NOT EXISTS cd_user_projects (user_id INT NOT NULL, project_id INT NOT NULL, PRIMARY KEY (user_id, project_id))$tail",
    "CREATE INDEX idx_cols_project ON cd_columns (project_id, position)",
    "CREATE INDEX idx_rows_project ON cd_rows (project_id, position)",
    "CREATE INDEX idx_log_email ON cd_login_log (email)",
  ];
}

if (is_file($cfgFile)) {
  $msg = 'Command Deck is already installed. For safety this page does nothing now. (To reinstall, delete api/config.php from cPanel File Manager first.)';
  $done = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $host = trim($_POST['db_host'] ?? 'localhost'); $name = trim($_POST['db_name'] ?? ''); $user = trim($_POST['db_user'] ?? ''); $pass = (string)($_POST['db_pass'] ?? '');
  $aName = trim($_POST['admin_name'] ?? ''); $aEmail = strtolower(trim($_POST['admin_email'] ?? '')); $aPass = (string)($_POST['admin_pass'] ?? '');
  $driver = (PHP_SAPI === 'cli-server' && ($_POST['driver'] ?? '') === 'sqlite') ? 'sqlite' : 'mysql';   // sqlite only for local testing
  try {
    if ($driver === 'mysql' && ($name === '' || $user === '')) throw new RuntimeException('Fill in the database name and database user.');
    if ($aName === '' || !filter_var($aEmail, FILTER_VALIDATE_EMAIL) || strlen($aPass) < 8) throw new RuntimeException('Enter the admin name, a valid admin email and a password of at least 8 characters.');
    $pdo = $driver === 'sqlite' ? new PDO('sqlite:' . __DIR__ . '/test.sqlite')
                                : new PDO("mysql:host=$host;dbname=$name;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (schema($driver) as $sql) { try { $pdo->exec($sql); } catch (PDOException $e) { if (stripos($sql, 'CREATE INDEX') !== 0) throw $e; } }
    date_default_timezone_set('Asia/Kolkata');
    $s = $pdo->prepare('SELECT id FROM cd_users WHERE email = ?'); $s->execute([$aEmail]);
    if ($s->fetch()) $pdo->prepare("UPDATE cd_users SET name = ?, role = 'superadmin', pass_hash = ?, legacy_salt = NULL, active = 1 WHERE email = ?")->execute([$aName, password_hash($aPass, PASSWORD_DEFAULT), $aEmail]);
    else $pdo->prepare("INSERT INTO cd_users (email, name, role, pass_hash, active, created_at) VALUES (?, ?, 'superadmin', ?, 1, ?)")->execute([$aEmail, $aName, password_hash($aPass, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
    $cfg = ['driver' => $driver, 'db_host' => $host, 'db_name' => $name, 'db_user' => $user, 'db_pass' => $pass, 'timezone' => 'Asia/Kolkata'];
    if ($driver === 'sqlite') $cfg['sqlite_path'] = __DIR__ . '/test.sqlite';
    if (file_put_contents($cfgFile, "<?php\n// Written by install.php. Keep this file private. Never upload it to GitHub.\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX) === false)
      throw new RuntimeException('Could not write api/config.php. Check that the api folder is writable.');
    @chmod($cfgFile, 0600);
    $msg = 'Installed! The tables are created and your admin account is ready. Next: open migrate.html to move the Google Sheet data, then sign in to the dashboard.';
    $done = true;
  } catch (Throwable $e) {
    $msg = 'Could not install: ' . htmlspecialchars($e instanceof PDOException ? 'database connection failed (' . $e->getMessage() . ')' : $e->getMessage());
  }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title>Command Deck · Install</title>
<style>
 body{margin:0;background:#05080a;color:#d7f5e3;font:15px/1.5 system-ui,Segoe UI,Roboto,sans-serif;display:flex;justify-content:center;padding:32px 16px}
 .card{width:100%;max-width:520px;background:#0b1410;border:1px solid #1f3a2c;border-radius:14px;padding:24px;box-shadow:0 0 30px rgba(34,197,94,.12)}
 h1{margin:0 0 4px;font-size:22px;color:#4ade80} p.s{margin:0 0 18px;color:#86a593;font-size:13px}
 h2{font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#86a593;margin:18px 0 8px}
 label{display:block;font-size:13px;margin:10px 0 4px} input{width:100%;box-sizing:border-box;padding:10px;border-radius:8px;border:1px solid #24412f;background:#060c09;color:#e6fff0;font-size:15px}
 button{margin-top:20px;width:100%;padding:12px;border:0;border-radius:10px;background:#22c55e;color:#03140a;font-weight:700;font-size:15px;cursor:pointer}
 .msg{padding:12px;border-radius:10px;margin-bottom:14px;background:#10241a;border:1px solid #22c55e55} .err{background:#2a1010;border-color:#ef444466}
 a{color:#4ade80}
</style></head><body><div class="card">
<h1>Command Deck · Install</h1><p class="s">Run this once. It sets up the database for the dashboard.</p>
<?php if ($msg): ?><div class="msg <?= $done ? '' : 'err' ?>"><?= $msg ?></div><?php endif; ?>
<?php if ($done): ?><p><a href="../migrate.html">Open migrate.html →</a> &nbsp; <a href="../">Open dashboard →</a></p>
<?php else: ?>
<form method="post" autocomplete="off">
 <h2>1 · MySQL database (from cPanel → MySQL Databases)</h2>
 <label>Database host</label><input name="db_host" value="localhost">
 <label>Database name (e.g. barterro_commanddeck)</label><input name="db_name" required>
 <label>Database user (e.g. barterro_cduser)</label><input name="db_user" required>
 <label>Database password</label><input name="db_pass" type="password">
 <h2>2 · First admin for the dashboard</h2>
 <label>Name</label><input name="admin_name" value="Admin" required>
 <label>Email ID</label><input name="admin_email" type="email" value="admin@insomniacs.in" required>
 <label>Password (min 8 characters)</label><input name="admin_pass" type="password" minlength="8" required>
 <button type="submit">Install Command Deck</button>
</form>
<?php endif; ?>
</div></body></html>
