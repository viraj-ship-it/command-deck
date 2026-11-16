<?php
/**
 * Brikkin Command Deck — API (PHP + MySQL). Replaces the Google Sheet / Apps Script backend.
 * The dashboard sends POST requests with a JSON body: {"action": "...", "token": "...", ...}
 * Database settings live in config.php (written by install.php, never uploaded from GitHub).
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (!is_file(__DIR__ . '/config.php')) out(['ok' => false, 'error' => 'Command Deck is not installed yet. Open install.php first.']);
$CFG = require __DIR__ . '/config.php';
date_default_timezone_set($CFG['timezone'] ?? 'Asia/Kolkata');

const SESSION_HOURS = 12;
const SUPER_EMAIL = 'admin@insomniacs.in';   // becomes Super Admin automatically if there is none yet
const LOG_KEEP = 2000;
// column header in a project → field name the dashboard uses
const COLUMNS = [
  'sr' => 'Sr. No.', 'name' => 'Deliverables', 'concept' => 'Concept', 'bucket' => 'Content Bucket',
  'script' => 'Script Status', 'scriptDoc' => 'Script', 'recce' => 'Recce', 'talent' => 'Model/Influencer Status',
  'shootDate' => 'Shoot date', 'shoot' => 'Shoot Status', 'editor' => 'Editor Assigned',
  'firstCut' => 'First Cut Status', 'live' => 'Live Link', 'feedback' => 'Feedback',
];
const PAY_COLUMNS = ['milestone' => 'Payment Milestone', 'stage' => 'Linked Stage', 'percent' => 'Payment %',
                     'received' => 'Payment Received', 'receivedDate' => 'Received Date'];
const TL_COLUMNS = ['stage' => 'Timeline Stage', 'date' => 'Planned Date', 'done' => 'Completed Date'];

final class UserError extends Exception {}
function out(array $a): void { echo json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

function db(): PDO {
  static $pdo = null; global $CFG;
  if ($pdo) return $pdo;
  if (($CFG['driver'] ?? 'mysql') === 'sqlite') {
    $pdo = new PDO('sqlite:' . $CFG['sqlite_path']);
  } else {
    $pdo = new PDO('mysql:host=' . $CFG['db_host'] . ';dbname=' . $CFG['db_name'] . ';charset=utf8mb4', $CFG['db_user'], $CFG['db_pass']);
  }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function now(): string { return date('Y-m-d H:i:s'); }
function norm($e): string { return strtolower(trim((string)$e)); }
function valid_email(string $e): bool { return (bool)preg_match('/^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$/', $e); }
function iso($dt): string { return $dt ? date('c', strtotime($dt)) : ''; }

/* ------------------------------------------------------------------ entry */
$raw = file_get_contents('php://input');
$b = json_decode($raw ?: '{}', true);
if (!is_array($b)) $b = [];
try {
  out(route($b));
} catch (UserError $e) {
  out(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
  error_log('Command Deck API: ' . $e->getMessage());
  out(['ok' => false, 'error' => 'Server error. Please try again.']);
}

function route(array $b): array {
  switch ($b['action'] ?? '') {
    case 'login': return login($b);
    case 'data': { $u = auth($b); return $u['ok'] ? with_data(['ok' => true, 'user' => pub($u['user'])], $u['user']) : $u; }
    case 'logout': if (!empty($b['token'])) q('DELETE FROM cd_sessions WHERE token_hash = ?', [hash('sha256', (string)$b['token'])]); return ['ok' => true];
    case 'changeMyPassword': return change_my_password($b);
    case 'listUsers': return superadmin($b, function () { return ['ok' => true, 'users' => users_list()]; });
    case 'addUser': return superadmin($b, function () use ($b) { return add_user($b); });
    case 'setRole': return superadmin($b, function ($me) use ($b) { return set_role($b, $me); });
    case 'resetPassword': return superadmin($b, function () use ($b) { return reset_password($b); });
    case 'removeUser': return superadmin($b, function ($me) use ($b) { return remove_user($b, $me); });
    case 'loginLog': return superadmin($b, function () use ($b) { return ['ok' => true, 'log' => read_log((int)($b['limit'] ?? 300))]; });
    case 'listPrompts': return superadmin($b, function () { return ['ok' => true, 'prompts' => list_prompts()]; });
    case 'addPrompt': return superadmin($b, function () use ($b) { return add_prompt($b); });
    case 'removePrompt': return superadmin($b, function () use ($b) { return remove_prompt($b); });
    case 'gridTabs': return admin($b, function () { return ['ok' => true, 'tabs' => tab_names()]; });
    case 'gridGet': return admin($b, function ($me) use ($b) { return grid_get($b, $me); });
    case 'gridSave': return admin($b, function ($me) use ($b) { return grid_save($b, $me); });
    case 'gridDeleteRow': return superadmin($b, function () use ($b) { return grid_delete_row($b); });
    case 'gridNewProject': return admin($b, function () use ($b) { return grid_new_project($b); });
    case 'gridColumns': return superadmin($b, function () use ($b) { return grid_columns($b); });
    case 'gridSetColumns': return superadmin($b, function () use ($b) { return grid_set_columns($b); });
    case 'projectRename': return admin($b, function () use ($b) { return project_rename($b); });
    case 'projectDelete': return superadmin($b, function () use ($b) { return project_delete($b); });
    case 'importData': return superadmin($b, function () use ($b) { return import_data($b); });
    default: return ['ok' => false, 'error' => 'Unknown request.'];
  }
}

/* ------------------------------------------------------------------ auth */
function legacy_hash(string $pw, string $salt): string {     // the old Apps Script hash, so moved users keep their password
  $h = $salt . ':' . $pw;
  for ($i = 0; $i < 300; $i++) $h = base64_encode(hash('sha256', $h . $salt, true));
  return $h;
}
function login(array $b): array {
  $email = norm($b['email'] ?? ''); $pw = (string)($b['password'] ?? '');
  $u = q('SELECT * FROM cd_users WHERE email = ?', [$email])->fetch();
  $ok = false;
  if ($u && (int)$u['active'] === 1) {
    if (!empty($u['legacy_salt'])) {
      $ok = hash_equals((string)$u['pass_hash'], legacy_hash($pw, (string)$u['legacy_salt']));
      if ($ok) q('UPDATE cd_users SET pass_hash = ?, legacy_salt = NULL WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
    } else {
      $ok = password_verify($pw, (string)$u['pass_hash']);
    }
  }
  if (!$ok) {
    log_login($email, $u['name'] ?? '', !$u ? 'Failed · unknown email' : ((int)$u['active'] !== 1 ? 'Failed · inactive' : 'Failed · wrong password'));
    sleep(1);
    return ['ok' => false, 'error' => 'Email ID or password is incorrect.'];
  }
  log_login($email, $u['name'], 'Success');
  if ($email === SUPER_EMAIL && $u['role'] !== 'superadmin' && !q("SELECT id FROM cd_users WHERE role = 'superadmin' AND active = 1")->fetch()) {
    q("UPDATE cd_users SET role = 'superadmin' WHERE id = ?", [$u['id']]); $u['role'] = 'superadmin';
  }
  $token = bin2hex(random_bytes(32));
  q('DELETE FROM cd_sessions WHERE expires_at < ?', [now()]);
  q('INSERT INTO cd_sessions (token_hash, user_id, expires_at) VALUES (?, ?, ?)', [hash('sha256', $token), $u['id'], date('Y-m-d H:i:s', time() + SESSION_HOURS * 3600)]);
  return with_data(['ok' => true, 'token' => $token, 'user' => pub($u)], $u);
}
function auth(array $b): array {
  $t = (string)($b['token'] ?? '');
  if ($t === '') return ['ok' => false, 'expired' => true, 'error' => 'Your session has ended. Please sign in again.'];
  $u = q('SELECT u.* FROM cd_sessions s JOIN cd_users u ON u.id = s.user_id WHERE s.token_hash = ? AND s.expires_at > ?', [hash('sha256', $t), now()])->fetch();
  if (!$u) return ['ok' => false, 'expired' => true, 'error' => 'Your session has ended. Please sign in again.'];
  if ((int)$u['active'] !== 1) return ['ok' => false, 'expired' => true, 'error' => 'Your account is no longer active.'];
  return ['ok' => true, 'user' => $u];
}
function is_super(array $u): bool { return $u['role'] === 'superadmin'; }
function is_admin(array $u): bool { return $u['role'] === 'admin' || $u['role'] === 'superadmin'; }
function clean_role($r): string { return in_array($r, ['superadmin', 'admin'], true) ? $r : 'user'; }
function superadmin(array $b, callable $fn): array { return admin($b, $fn, true); }
function admin(array $b, callable $fn, bool $super = false): array {
  $a = auth($b);
  if (!$a['ok']) return $a;
  if (!is_admin($a['user'])) return ['ok' => false, 'error' => 'Only admins can do this.'];
  if ($super && !is_super($a['user'])) return ['ok' => false, 'error' => 'Only the Super Admin can do this.'];
  $pdo = db(); $pdo->beginTransaction();
  try { $r = $fn($a['user']); $pdo->commit(); return $r; }
  catch (Throwable $e) { $pdo->rollBack(); throw $e; }
}
function pub(array $u): array { return ['email' => $u['email'], 'name' => $u['name'], 'role' => $u['role'], 'created' => iso($u['created_at'] ?? '')]; }

/* ------------------------------------------------------------------ users */
function users_list(): array {
  $last = [];
  foreach (q("SELECT email, MAX(logged_at) AS t FROM cd_login_log WHERE result = 'Success' GROUP BY email")->fetchAll() as $r) $last[$r['email']] = iso($r['t']);
  return array_map(function ($u) use ($last) { $p = pub($u); $p['lastLogin'] = $last[$u['email']] ?? ''; return $p; },
                   q('SELECT * FROM cd_users ORDER BY name')->fetchAll());
}
function add_user(array $b): array {
  $email = norm($b['email'] ?? ''); $name = trim((string)($b['name'] ?? '')); $role = clean_role($b['role'] ?? '');
  if ($name === '') return ['ok' => false, 'error' => "Enter the user's name."];
  if (!valid_email($email)) return ['ok' => false, 'error' => 'Enter a valid email ID.'];
  if (strlen((string)($b['password'] ?? '')) < 6) return ['ok' => false, 'error' => 'Password must be at least 6 characters.'];
  if (q('SELECT id FROM cd_users WHERE email = ?', [$email])->fetch()) return ['ok' => false, 'error' => 'A user with this email ID already exists.'];
  q('INSERT INTO cd_users (email, name, role, pass_hash, active, created_at) VALUES (?, ?, ?, ?, 1, ?)', [$email, $name, $role, password_hash((string)$b['password'], PASSWORD_DEFAULT), now()]);
  return ['ok' => true, 'users' => users_list()];
}
function set_role(array $b, array $me): array {
  $email = norm($b['email'] ?? ''); $role = clean_role($b['role'] ?? '');
  if ($email === $me['email']) return ['ok' => false, 'error' => 'You cannot change your own role.'];
  $u = q('SELECT * FROM cd_users WHERE email = ?', [$email])->fetch();
  if (!$u) return ['ok' => false, 'error' => 'User not found.'];
  if ($u['role'] === 'superadmin' && $role !== 'superadmin' && (int)q("SELECT COUNT(*) c FROM cd_users WHERE role = 'superadmin' AND active = 1")->fetch()['c'] <= 1)
    return ['ok' => false, 'error' => 'At least one Super Admin is needed.'];
  q('UPDATE cd_users SET role = ? WHERE id = ?', [$role, $u['id']]);
  return ['ok' => true, 'users' => users_list()];
}
function reset_password(array $b): array {
  $pw = (string)($b['password'] ?? '');
  if (strlen($pw) < 6) return ['ok' => false, 'error' => 'New password must be at least 6 characters.'];
  $s = q('UPDATE cd_users SET pass_hash = ?, legacy_salt = NULL WHERE email = ?', [password_hash($pw, PASSWORD_DEFAULT), norm($b['email'] ?? '')]);
  if (!$s->rowCount()) return ['ok' => false, 'error' => 'User not found.'];
  return ['ok' => true, 'users' => users_list()];
}
function remove_user(array $b, array $me): array {
  $email = norm($b['email'] ?? '');
  if ($email === $me['email']) return ['ok' => false, 'error' => 'You cannot remove yourself.'];
  $u = q('SELECT id, role FROM cd_users WHERE email = ?', [$email])->fetch();
  if (!$u) return ['ok' => false, 'error' => 'User not found.'];
  if ($u['role'] === 'superadmin' && (int)q("SELECT COUNT(*) c FROM cd_users WHERE role = 'superadmin' AND active = 1")->fetch()['c'] <= 1)
    return ['ok' => false, 'error' => 'At least one Super Admin is needed.'];
  q('DELETE FROM cd_sessions WHERE user_id = ?', [$u['id']]);
  q('DELETE FROM cd_users WHERE id = ?', [$u['id']]);
  return ['ok' => true, 'users' => users_list()];
}
function change_my_password(array $b): array {
  $a = auth($b); if (!$a['ok']) return $a; $u = $a['user'];
  $old = (string)($b['oldPassword'] ?? '');
  $ok = !empty($u['legacy_salt']) ? hash_equals((string)$u['pass_hash'], legacy_hash($old, (string)$u['legacy_salt'])) : password_verify($old, (string)$u['pass_hash']);
  if (!$ok) { sleep(1); return ['ok' => false, 'error' => 'Current password is incorrect.']; }
  if (strlen((string)($b['newPassword'] ?? '')) < 6) return ['ok' => false, 'error' => 'New password must be at least 6 characters.'];
  q('UPDATE cd_users SET pass_hash = ?, legacy_salt = NULL WHERE id = ?', [password_hash((string)$b['newPassword'], PASSWORD_DEFAULT), $u['id']]);
  return ['ok' => true];
}

/* ------------------------------------------------------------------ login log */
function log_login(string $email, string $name, string $result): void {
  try {
    q('INSERT INTO cd_login_log (logged_at, email, name, result) VALUES (?, ?, ?, ?)', [now(), $email !== '' ? $email : '(blank)', $name, $result]);
    $n = (int)q('SELECT COUNT(*) c FROM cd_login_log')->fetch()['c'];
    if ($n > LOG_KEEP + 100) {
      $cut = q('SELECT id FROM cd_login_log ORDER BY id DESC LIMIT 1 OFFSET ' . LOG_KEEP)->fetch();
      if ($cut) q('DELETE FROM cd_login_log WHERE id <= ?', [$cut['id']]);
    }
  } catch (Throwable $e) { /* never block sign-in */ }
}
function read_log(int $limit): array {
  $limit = max(1, min(2000, $limit));
  return array_map(function ($r) { return ['time' => iso($r['logged_at']), 'email' => $r['email'], 'name' => $r['name'], 'result' => $r['result']]; },
                   q('SELECT * FROM cd_login_log ORDER BY logged_at DESC, id DESC LIMIT ' . $limit)->fetchAll());
}

/* ------------------------------------------------------------------ VIRA messages */
function list_prompts(): array {
  return array_map(function ($p) { return ['row' => (int)$p['id'], 'email' => $p['email'], 'message' => $p['message'], 'days' => $p['days']]; },
                   q('SELECT * FROM cd_prompts ORDER BY id')->fetchAll());
}
function add_prompt(array $b): array {
  $email = trim((string)($b['email'] ?? '')); $msg = trim((string)($b['message'] ?? '')); $days = trim((string)($b['days'] ?? '')) ?: 'Daily';
  if ($msg === '') return ['ok' => false, 'error' => 'Write the message VIRA should show.'];
  if ($email === '') return ['ok' => false, 'error' => 'Choose who should see this message.'];
  q('INSERT INTO cd_prompts (email, message, days) VALUES (?, ?, ?)', [strtoupper($email) === 'ALL' ? 'ALL' : norm($email), mb_substr($msg, 0, 300), $days]);
  return ['ok' => true, 'prompts' => list_prompts()];
}
function remove_prompt(array $b): array {
  q('DELETE FROM cd_prompts WHERE id = ?', [(int)($b['row'] ?? 0)]);
  return ['ok' => true, 'prompts' => list_prompts()];
}
function prompts_for(string $email): array {
  $today = strtolower(date('D')); $out = [];
  foreach (q('SELECT * FROM cd_prompts ORDER BY id')->fetchAll() as $p) {
    $who = strtolower(trim($p['email'])); $days = strtolower(trim((string)$p['days']));
    if ($who !== 'all' && $who !== norm($email)) continue;
    if ($days === '' || $days === 'daily' || $days === 'every day') { $out[] = $p['message']; continue; }
    foreach (preg_split('/[ ,\/]+/', $days) as $d) if (substr($d, 0, 3) === $today) { $out[] = $p['message']; break; }
  }
  return $out;
}

/* ------------------------------------------------------------------ projects: storage helpers */
function tab_names(): array { return array_column(q('SELECT name FROM cd_projects ORDER BY position, id')->fetchAll(), 'name'); }
function project_by_name($name): array {
  $p = q('SELECT * FROM cd_projects WHERE name = ?', [(string)$name])->fetch();
  if (!$p) throw new UserError('Project "' . $name . '" not found. Reload and try again.');
  return $p;
}
function cols_of(int $pid): array { return q('SELECT * FROM cd_columns WHERE project_id = ? ORDER BY position, id', [$pid])->fetchAll(); }
function rows_of(int $pid): array {
  return array_map(function ($r) { $r['data'] = json_decode((string)$r['data'], true) ?: []; return $r; },
                   q('SELECT * FROM cd_rows WHERE project_id = ? ORDER BY position, id', [$pid])->fetchAll());
}
function grid_cols(int $pid, array $me): array {   // admins do not see payment columns
  $cols = cols_of($pid);
  if (is_super($me)) return $cols;
  $hide = array_map('strtolower', array_values(PAY_COLUMNS));
  return array_values(array_filter($cols, function ($c) use ($hide) { return !in_array(strtolower(trim($c['name'])), $hide, true); }));
}
function cell(array $row, array $col): string { return (string)($row['data'][(string)$col['id']] ?? ''); }

/* ------------------------------------------------------------------ dashboard data */
function with_data(array $out, array $user): array {
  $projects = []; $payments = [];
  foreach (q('SELECT * FROM cd_projects ORDER BY position, id')->fetchAll() as $p) {
    $cols = cols_of((int)$p['id']); $rows = rows_of((int)$p['id']);
    $byName = []; foreach ($cols as $c) { $k = trim($c['name']); if (!isset($byName[$k])) $byName[$k] = $c; }
    $get = function ($row, $name) use ($byName) { return isset($byName[$name]) ? trim(cell($row, $byName[$name])) : ''; };
    $items = []; $pay = []; $tl = [];
    foreach ($rows as $row) {
      if ($get($row, 'Deliverables') !== '') {
        $o = []; foreach (COLUMNS as $k => $h) $o[$k] = $get($row, $h);
        $o['name'] = preg_replace('/\s+/', ' ', $o['name']);
        $items[] = $o;
      }
      $m = $get($row, PAY_COLUMNS['milestone']);
      if ($m !== '' && !preg_match('/^total/i', $m)) {
        $pc = $get($row, PAY_COLUMNS['percent']);
        $num = (float)preg_replace('/[^0-9.\-]/', '', $pc);
        if (strpos($pc, '%') !== false) $num = $num / 100;
        $pay[] = ['project' => $p['name'], 'milestone' => $m, 'stage' => $get($row, PAY_COLUMNS['stage']), 'percent' => $num,
                  'received' => (bool)preg_match('/^(yes|y|received|paid|done|true)$/i', $get($row, PAY_COLUMNS['received'])),
                  'receivedDate' => $get($row, PAY_COLUMNS['receivedDate'])];
      }
      $st = $get($row, TL_COLUMNS['stage']);
      if ($st !== '' && !preg_match('/^total/i', $st)) $tl[] = ['stage' => $st, 'date' => $get($row, TL_COLUMNS['date']), 'completed' => $get($row, TL_COLUMNS['done'])];
    }
    if (!$items && !$pay) continue;
    $projects[] = ['name' => $p['name'], 'hasFeedback' => isset($byName['Feedback']), 'rows' => $items, 'timeline' => $tl];
    if ($pay) $payments[] = ['project' => $p['name'], 'milestones' => $pay];
  }
  $out['projects'] = $projects;
  $out['prompts'] = prompts_for($user['email']);
  if (is_super($user)) $out['payments'] = $payments;   // payments only go to the Super Admin
  $out['sheetName'] = 'Command Deck database';
  $out['updatedAt'] = date('c');
  return $out;
}

/* ------------------------------------------------------------------ data entry grid */
function grid_get(array $b, array $me): array {
  $p = project_by_name($b['tab'] ?? ''); $cols = grid_cols((int)$p['id'], $me); $rows = rows_of((int)$p['id']);
  $values = array_map(function ($r) use ($cols) { return array_map(function ($c) use ($r) { return cell($r, $c); }, $cols); }, $rows);
  return ['ok' => true, 'tab' => $p['name'], 'headerRow' => 0, 'headers' => array_column($cols, 'name'), 'values' => $values,
          'formulas' => [], 'dates' => [], 'locked' => [],
          'lists' => array_map(function ($c) { $l = json_decode((string)$c['list_values'], true); return is_array($l) && $l ? array_values($l) : null; }, $cols)];
}
function grid_save(array $b, array $me): array {
  $p = project_by_name($b['tab'] ?? ''); $pid = (int)$p['id']; $cols = grid_cols($pid, $me); $rows = rows_of($pid);
  $changes = array_slice(is_array($b['changes'] ?? null) ? $b['changes'] : [], 0, 2000);
  $max = 0; foreach ($changes as $ch) $max = max($max, (int)($ch['r'] ?? 0));
  for ($i = count($rows); $i < min($max, 5000); $i++) {
    q('INSERT INTO cd_rows (project_id, position, data, updated_at) VALUES (?, ?, ?, ?)', [$pid, $i + 1, '{}', now()]);
    $rows[] = ['id' => (int)db()->lastInsertId(), 'position' => $i + 1, 'data' => []];
  }
  $saved = []; $conflicts = []; $touched = [];
  foreach ($changes as $ch) {
    $r = (int)($ch['r'] ?? 0); $c = (int)($ch['c'] ?? 0);
    if ($r < 1 || $c < 1 || $r > count($rows) || $c > count($cols)) continue;
    $row = &$rows[$r - 1]; $col = $cols[$c - 1];
    $cur = cell($row, $col);
    if (array_key_exists('old', $ch) && (string)$ch['old'] !== $cur) { $conflicts[] = ['r' => $r, 'c' => $c, 'current' => $cur, 'reason' => 'changed']; unset($row); continue; }
    $v = mb_substr((string)($ch['value'] ?? ''), 0, 5000);
    if ($v === '') unset($row['data'][(string)$col['id']]); else $row['data'][(string)$col['id']] = $v;
    $touched[$r - 1] = true;
    $saved[] = ['r' => $r, 'c' => $c, 'value' => $v, 'iso' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : ''];
    unset($row);
  }
  foreach (array_keys($touched) as $i)
    q('UPDATE cd_rows SET data = ?, updated_at = ? WHERE id = ?', [json_encode((object)$rows[$i]['data'], JSON_UNESCAPED_UNICODE), now(), $rows[$i]['id']]);
  return ['ok' => true, 'saved' => $saved, 'conflicts' => $conflicts];
}
function grid_delete_row(array $b): array {
  $p = project_by_name($b['tab'] ?? ''); $pid = (int)$p['id']; $cols = cols_of($pid); $rows = rows_of($pid);
  $r = (int)($b['r'] ?? 0);
  if ($r < 1 || $r > count($rows)) return ['ok' => false, 'error' => 'Row not found. Reload and try again.'];
  $now = implode("\u{1}", array_map(function ($c) use ($rows, $r) { return cell($rows[$r - 1], $c); }, $cols));
  if (isset($b['check']) && (string)$b['check'] !== $now) return ['ok' => false, 'error' => 'This row was changed by someone else. Reload and try again.'];
  q('DELETE FROM cd_rows WHERE id = ?', [$rows[$r - 1]['id']]);
  q('UPDATE cd_rows SET position = position - 1 WHERE project_id = ? AND position > ?', [$pid, $rows[$r - 1]['position']]);
  return ['ok' => true];
}
function grid_new_project(array $b): array {
  $name = trim((string)($b['name'] ?? ''));
  if ($name === '') return ['ok' => false, 'error' => 'Enter the project name.'];
  if (q('SELECT id FROM cd_projects WHERE name = ?', [$name])->fetch()) return ['ok' => false, 'error' => 'A project with this name already exists.'];
  $pos = (int)(q('SELECT COALESCE(MAX(position), 0) m FROM cd_projects')->fetch()['m']) + 1;
  q('INSERT INTO cd_projects (name, position, created_at) VALUES (?, ?, ?)', [$name, $pos, now()]);
  $pid = (int)db()->lastInsertId();
  $src = !empty($b['copyFrom']) ? q('SELECT id FROM cd_projects WHERE name = ?', [(string)$b['copyFrom']])->fetch() : null;
  $cols = $src ? cols_of((int)$src['id']) : array_map(function ($h, $i) { return ['name' => $h, 'position' => $i + 1, 'list_values' => null]; }, array_values(COLUMNS), array_keys(array_values(COLUMNS)));
  foreach ($cols as $c) q('INSERT INTO cd_columns (project_id, position, name, list_values) VALUES (?, ?, ?, ?)', [$pid, $c['position'], $c['name'], $c['list_values']]);
  return ['ok' => true, 'tab' => $name, 'tabs' => tab_names()];
}
function grid_columns(array $b): array {
  $p = project_by_name($b['tab'] ?? '');
  return ['ok' => true, 'columns' => array_map(function ($c) { $l = json_decode((string)$c['list_values'], true); return ['id' => (int)$c['id'], 'name' => $c['name'], 'list' => is_array($l) ? $l : []]; }, cols_of((int)$p['id']))];
}
function grid_set_columns(array $b): array {
  $p = project_by_name($b['tab'] ?? ''); $pid = (int)$p['id'];
  $want = is_array($b['columns'] ?? null) ? $b['columns'] : [];
  $have = []; foreach (cols_of($pid) as $c) $have[(int)$c['id']] = $c;
  $keep = []; $pos = 0;
  foreach ($want as $w) {
    $name = trim((string)($w['name'] ?? '')); if ($name === '') continue;
    $list = array_values(array_filter(array_map('trim', is_array($w['list'] ?? null) ? $w['list'] : []), 'strlen'));
    $lv = $list ? json_encode($list, JSON_UNESCAPED_UNICODE) : null; $pos++;
    $id = (int)($w['id'] ?? 0);
    if ($id && isset($have[$id])) { q('UPDATE cd_columns SET name = ?, list_values = ?, position = ? WHERE id = ?', [$name, $lv, $pos, $id]); $keep[$id] = true; }
    else q('INSERT INTO cd_columns (project_id, position, name, list_values) VALUES (?, ?, ?, ?)', [$pid, $pos, $name, $lv]);
  }
  $gone = array_diff(array_keys($have), array_keys($keep));
  if ($gone) {
    foreach (rows_of($pid) as $r) {
      $d = $r['data']; $changed = false;
      foreach ($gone as $g) if (array_key_exists((string)$g, $d)) { unset($d[(string)$g]); $changed = true; }
      if ($changed) q('UPDATE cd_rows SET data = ? WHERE id = ?', [json_encode((object)$d, JSON_UNESCAPED_UNICODE), $r['id']]);
    }
    foreach ($gone as $g) q('DELETE FROM cd_columns WHERE id = ?', [$g]);
  }
  return grid_columns($b);
}
function project_rename(array $b): array {
  $p = project_by_name($b['tab'] ?? ''); $name = trim((string)($b['name'] ?? ''));
  if ($name === '') return ['ok' => false, 'error' => 'Enter the new name.'];
  if ($name !== $p['name'] && q('SELECT id FROM cd_projects WHERE name = ?', [$name])->fetch()) return ['ok' => false, 'error' => 'A project with this name already exists.'];
  q('UPDATE cd_projects SET name = ? WHERE id = ?', [$name, $p['id']]);
  return ['ok' => true, 'tab' => $name, 'tabs' => tab_names()];
}
function project_delete(array $b): array {
  $p = project_by_name($b['tab'] ?? '');
  if (trim((string)($b['confirm'] ?? '')) !== $p['name']) return ['ok' => false, 'error' => 'Type the project name exactly to delete it.'];
  q('DELETE FROM cd_rows WHERE project_id = ?', [$p['id']]); q('DELETE FROM cd_columns WHERE project_id = ?', [$p['id']]); q('DELETE FROM cd_projects WHERE id = ?', [$p['id']]);
  return ['ok' => true, 'tabs' => tab_names()];
}

/* ------------------------------------------------------------------ one-time import from the old Google Sheet */
function import_data(array $b): array {
  $d = $b['payload'] ?? null;
  if (!is_array($d) || !isset($d['tabs']) || !is_array($d['tabs'])) return ['ok' => false, 'error' => 'Nothing to import.'];
  if (($b['confirm'] ?? '') !== 'REPLACE') return ['ok' => false, 'error' => 'Import not confirmed.'];
  q('DELETE FROM cd_rows'); q('DELETE FROM cd_columns'); q('DELETE FROM cd_projects');
  $np = 0; $nr = 0;
  foreach ($d['tabs'] as $i => $t) {
    $name = trim((string)($t['name'] ?? '')); if ($name === '' || $name[0] === '_') continue;
    q('INSERT INTO cd_projects (name, position, created_at) VALUES (?, ?, ?)', [$name, $i + 1, now()]); $pid = (int)db()->lastInsertId(); $np++;
    $headers = is_array($t['headers'] ?? null) ? $t['headers'] : []; $lists = is_array($t['lists'] ?? null) ? $t['lists'] : [];
    $ids = [];
    foreach ($headers as $j => $h) {
      $h = trim((string)$h); if ($h === '') { $ids[$j] = null; continue; }
      $l = isset($lists[$j]) && is_array($lists[$j]) && $lists[$j] ? json_encode(array_values($lists[$j]), JSON_UNESCAPED_UNICODE) : null;
      q('INSERT INTO cd_columns (project_id, position, name, list_values) VALUES (?, ?, ?, ?)', [$pid, $j + 1, $h, $l]);
      $ids[$j] = (int)db()->lastInsertId();
    }
    $pos = 0;
    foreach ((array)($t['values'] ?? []) as $row) {
      $data = [];
      foreach ((array)$row as $j => $v) { $v = trim((string)$v); if ($v !== '' && !empty($ids[$j])) $data[(string)$ids[$j]] = $v; }
      if (!$data) continue;
      q('INSERT INTO cd_rows (project_id, position, data, updated_at) VALUES (?, ?, ?, ?)', [$pid, ++$pos, json_encode((object)$data, JSON_UNESCAPED_UNICODE), now()]); $nr++;
    }
  }
  $nu = 0;
  foreach ((array)($d['users'] ?? []) as $u) {
    $email = norm($u['email'] ?? ''); if (!valid_email($email) || q('SELECT id FROM cd_users WHERE email = ?', [$email])->fetch()) continue;
    q('INSERT INTO cd_users (email, name, role, pass_hash, legacy_salt, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
      [$email, (string)($u['name'] ?? ''), clean_role($u['role'] ?? ''), (string)($u['hash'] ?? ''), (string)($u['salt'] ?? ''), empty($u['active']) ? 0 : 1, !empty($u['created']) && strtotime((string)$u['created']) ? date('Y-m-d H:i:s', strtotime((string)$u['created'])) : now()]);
    $nu++;
  }
  if (!empty($d['prompts'])) { q('DELETE FROM cd_prompts'); foreach ((array)$d['prompts'] as $p) if (trim((string)($p['message'] ?? '')) !== '') q('INSERT INTO cd_prompts (email, message, days) VALUES (?, ?, ?)', [(string)$p['email'], (string)$p['message'], (string)($p['days'] ?? 'Daily')]); }
  foreach (array_reverse((array)($d['log'] ?? [])) as $l) {
    $t = strtotime((string)($l['time'] ?? '')); if (!$t) continue;
    q('INSERT INTO cd_login_log (logged_at, email, name, result) VALUES (?, ?, ?, ?)', [date('Y-m-d H:i:s', $t), (string)$l['email'], (string)$l['name'], (string)$l['result']]);
  }
  return ['ok' => true, 'projects' => $np, 'rows' => $nr, 'users' => $nu, 'prompts' => count((array)($d['prompts'] ?? []))];
}
