<?php
/**
 * Brikkin Command Deck — daily deadline reminders.
 * Run once a day from cPanel → Cron Jobs:
 *   /usr/local/bin/php /home2/barte3ae/public_html/commanddeck/api/reminders.php
 * Emails every User about timeline stages due tomorrow or overdue (each stage only once).
 * Opening it in a browser does nothing unless ?key= matches 'cron_key' in config.php.
 */
declare(strict_types=1);
define('CD_LIB', true);
if (PHP_SAPI !== 'cli') {
  $cfgPeek = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
  if (empty($cfgPeek['cron_key']) || !hash_equals((string)$cfgPeek['cron_key'], (string)($_GET['key'] ?? ''))) { http_response_code(403); exit("Forbidden\n"); }
}
require __DIR__ . '/index.php';
ensure_brand_column(); ensure_assign_table(); ensure_edit_log(); ensure_reminder_tables();
try {
  $r = run_reminders(false);
  $line = date('Y-m-d H:i') . ' reminders: ' . (isset($r['skipped']) ? $r['skipped'] : "{$r['sent']} sent, {$r['failed']} failed, {$r['items']} stages, {$r['users']} users");
} catch (Throwable $e) { $line = date('Y-m-d H:i') . ' reminders error: ' . $e->getMessage(); }
if (PHP_SAPI === 'cli') echo $line . "\n"; else { header('Content-Type: text/plain'); echo $line . "\n"; }
