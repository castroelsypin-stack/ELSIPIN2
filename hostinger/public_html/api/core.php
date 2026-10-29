<?php
/* GOLDEN TOUCH · núcleo del servidor PHP (base de datos MySQL, sesiones, permisos) */
declare(strict_types=1);

class UserErr extends Exception {}
function fail(string $m): void { throw new UserErr($m); }

$CFG = require __DIR__ . '/config.php';
date_default_timezone_set($CFG['timezone'] ?? 'America/Bogota');
const SESSION_DAYS = 30;
const MAXDOC = 6 * 1024 * 1024;

/* ---------- utilidades ---------- */
function cfg(string $k, $d = null) { global $CFG; return $CFG[$k] ?? $d; }
function nowms(): int { return (int)floor(microtime(true) * 1000); }
function jenc($x): string { return json_encode($x, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR); }
function jdec(?string $s) { return $s === null || $s === '' ? null : json_decode($s, false); }
function plain($x): bool { return $x instanceof stdClass; }
function g($o, string $k, $d = null) { if (is_object($o)) return $o->$k ?? $d; if (is_array($o)) return $o[$k] ?? $d; return $d; }
function jsnum($v): float { if ($v === null || $v === '' || $v === false) return 0.0; if ($v === true) return 1.0; return is_numeric($v) ? (float)$v : 0.0; }
function jsround($x): int { return (int)floor($x + 0.5); }
function num($x) { return is_float($x) && floor($x) == $x && abs($x) < 1e15 ? (int)$x : $x; }
function str($v): string { return $v === null ? '' : (is_scalar($v) ? (string)$v : ''); }
function uid6(): string { $s = ''; $c = '0123456789abcdefghijklmnopqrstuvwxyz'; for ($i = 0; $i < 6; $i++) $s .= $c[random_int(0, 35)]; return $s; }
function trunc(string $s, int $n): string { return mb_substr($s, 0, $n, 'UTF-8'); }
const MAIL_RX = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';

/* ---------- base de datos ---------- */
function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;
  $dsn = cfg('db_dsn');
  if ($dsn) { $pdo = new PDO($dsn, cfg('db_user'), cfg('db_pass')); }
  else { $pdo = new PDO('mysql:host=' . cfg('db_host', 'localhost') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4', cfg('db_user'), cfg('db_pass')); }
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
  if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') { $pdo->exec("SET time_zone='+00:00'"); }
  return $pdo;
}
function isMy(): bool { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function one(string $sql, array $p = []) { $r = q($sql, $p)->fetch(PDO::FETCH_ASSOC); return $r ?: null; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(PDO::FETCH_ASSOC); }

function installSchema(): void {
  $pdo = db();
  if (isMy()) {
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_docs(col VARCHAR(32) NOT NULL, id VARCHAR(64) NOT NULL, data LONGTEXT, rev BIGINT NOT NULL, deleted TINYINT NOT NULL DEFAULT 0, seq BIGINT NOT NULL DEFAULT 0, PRIMARY KEY(col,id), KEY docs_rev(rev), KEY docs_seq(col,seq))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_meta(k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT)$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_secrets(kind VARCHAR(16) NOT NULL, id VARCHAR(64) NOT NULL, hash VARCHAR(255) NOT NULL, PRIMARY KEY(kind,id))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_sessions(sid CHAR(64) NOT NULL PRIMARY KEY, kind VARCHAR(16) NOT NULL, uid VARCHAR(64) NOT NULL, exp BIGINT NOT NULL, KEY ses_exp(exp))$t");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_rl(k VARCHAR(120) NOT NULL PRIMARY KEY, t BIGINT NOT NULL, n INT NOT NULL)$t");
  } else {
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_docs(col TEXT NOT NULL, id TEXT NOT NULL, data TEXT, rev INTEGER NOT NULL, deleted INTEGER NOT NULL DEFAULT 0, seq INTEGER NOT NULL DEFAULT 0, PRIMARY KEY(col,id))");
    $pdo->exec("CREATE INDEX IF NOT EXISTS docs_rev ON gt_docs(rev)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_meta(k TEXT PRIMARY KEY, v TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_secrets(kind TEXT NOT NULL, id TEXT NOT NULL, hash TEXT NOT NULL, PRIMARY KEY(kind,id))");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_sessions(sid TEXT PRIMARY KEY, kind TEXT NOT NULL, uid TEXT NOT NULL, exp INTEGER NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS gt_rl(k TEXT PRIMARY KEY, t INTEGER NOT NULL, n INTEGER NOT NULL)");
  }
  if (!one("SELECT v FROM gt_meta WHERE k='rev'")) { try { q("INSERT INTO gt_meta(k,v) VALUES('rev','0')"); } catch (Throwable $e) {} }
}

/* ---------- transacciones y revisiones (un solo escritor a la vez) ---------- */
$GLOBALS['REV'] = 0; $GLOBALS['SEQ'] = 0; $GLOBALS['IN_TX'] = false;
function tx(callable $fn) {
  $pdo = db();
  if ($GLOBALS['IN_TX']) return $fn();
  if (isMy()) { $pdo->beginTransaction(); $r = one("SELECT v FROM gt_meta WHERE k='rev' FOR UPDATE"); }
  else { $pdo->exec('BEGIN IMMEDIATE'); $r = one("SELECT v FROM gt_meta WHERE k='rev'"); }
  $GLOBALS['IN_TX'] = true;
  try {
    $GLOBALS['REV'] = (int)($r['v'] ?? 0);
    $s = one("SELECT v FROM gt_meta WHERE k='seq'"); $GLOBALS['SEQ'] = (int)($s['v'] ?? 0);
    $res = $fn();
    $GLOBALS['IN_TX'] = false;
    isMy() ? $pdo->commit() : $pdo->exec('COMMIT');
    return $res;
  } catch (Throwable $e) {
    $GLOBALS['IN_TX'] = false;
    try { isMy() ? $pdo->rollBack() : $pdo->exec('ROLLBACK'); } catch (Throwable $x) {}
    throw $e;
  }
}
function meta(string $k) { $r = one('SELECT v FROM gt_meta WHERE k=?', [$k]); return $r ? $r['v'] : null; }
function setMeta(string $k, string $v): void {
  if (one('SELECT 1 AS x FROM gt_meta WHERE k=?', [$k])) q('UPDATE gt_meta SET v=? WHERE k=?', [$v, $k]);
  else q('INSERT INTO gt_meta(k,v) VALUES(?,?)', [$k, $v]);
}
function nextRev(): int { $GLOBALS['REV']++; setMeta('rev', (string)$GLOBALS['REV']); return $GLOBALS['REV']; }
function curRev(): int { return (int)(meta('rev') ?? 0); }

function getDoc(string $col, string $id) {
  $r = one('SELECT data,deleted FROM gt_docs WHERE col=? AND id=?', [$col, $id]);
  return $r && !(int)$r['deleted'] ? jdec($r['data']) : null;
}
function putDoc(string $col, string $id, $obj): void {
  $rev = nextRev(); $data = jenc($obj);
  $n = q('UPDATE gt_docs SET data=?, rev=?, deleted=0 WHERE col=? AND id=?', [$data, $rev, $col, $id])->rowCount();
  if ($n === 0 && !one('SELECT 1 AS x FROM gt_docs WHERE col=? AND id=?', [$col, $id])) {
    $GLOBALS['SEQ']++; setMeta('seq', (string)$GLOBALS['SEQ']);
    q('INSERT INTO gt_docs(col,id,data,rev,deleted,seq) VALUES(?,?,?,?,0,?)', [$col, $id, $data, $rev, $GLOBALS['SEQ']]);
  }
}
function delDoc(string $col, string $id): void {
  if (one('SELECT 1 AS x FROM gt_docs WHERE col=? AND id=?', [$col, $id])) q('UPDATE gt_docs SET data=NULL, deleted=1, rev=? WHERE col=? AND id=?', [nextRev(), $col, $id]);
}
function listDocs(string $col): array {
  return array_map(fn($r) => jdec($r['data']), all('SELECT data FROM gt_docs WHERE col=? AND deleted=0 ORDER BY seq', [$col]));
}

/* ---------- contraseñas y sesiones ---------- */
function setSecret(string $kind, string $id, string $h): void {
  $hash = password_hash($h, PASSWORD_DEFAULT);
  if (one('SELECT 1 AS x FROM gt_secrets WHERE kind=? AND id=?', [$kind, $id])) q('UPDATE gt_secrets SET hash=? WHERE kind=? AND id=?', [$hash, $kind, $id]);
  else q('INSERT INTO gt_secrets(kind,id,hash) VALUES(?,?,?)', [$kind, $id, $hash]);
  unset($GLOBALS['SECS']);
}
function delSecret(string $kind, string $id): void { q('DELETE FROM gt_secrets WHERE kind=? AND id=?', [$kind, $id]); unset($GLOBALS['SECS']); }
function checkSecret(string $kind, string $id, string $h): bool {
  $r = one('SELECT hash FROM gt_secrets WHERE kind=? AND id=?', [$kind, $id]);
  return $r && $h !== '' && password_verify($h, $r['hash']);
}
function hasSecret(string $kind, string $id): bool {
  if (!isset($GLOBALS['SECS'])) { $GLOBALS['SECS'] = []; foreach (all('SELECT kind,id FROM gt_secrets') as $r) $GLOBALS['SECS'][$r['kind'] . '|' . $r['id']] = 1; }
  return isset($GLOBALS['SECS'][$kind . '|' . $id]);
}
function newSession(string $kind, string $uid): string {
  $sid = bin2hex(random_bytes(32));
  q('INSERT INTO gt_sessions(sid,kind,uid,exp) VALUES(?,?,?,?)', [$sid, $kind, $uid, nowms() + SESSION_DAYS * 864000 * 100]);
  return $sid;
}
function secureCookie(): bool {
  return !empty(cfg('cookie_secure')) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function setSidCookie(string $sid, bool $clear = false): void {
  setcookie('gt_sid', $clear ? '' : $sid, ['expires' => $clear ? 1 : time() + SESSION_DAYS * 86400, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => secureCookie()]);
}
function actorOf(): array {
  $sid = $_COOKIE['gt_sid'] ?? ''; $anon = ['kind' => 'anon'];
  if (!is_string($sid) || !preg_match('/^[a-f0-9]{64}$/', $sid)) return $anon;
  $s = one('SELECT kind,uid,exp FROM gt_sessions WHERE sid=?', [$sid]);
  if (!$s || (int)$s['exp'] < nowms()) { if ($s) q('DELETE FROM gt_sessions WHERE sid=?', [$sid]); return $anon; }
  if ($s['kind'] === 'user') { $u = getDoc('users', $s['uid']); if (!$u || !g($u, 'active')) return $anon; return ['kind' => 'user', 'id' => $u->id, 'role' => $u->role, 'doc' => $u, 'sid' => $sid]; }
  if ($s['kind'] === 'customer') { $k = getDoc('customers', $s['uid']); if (!$k) return $anon; return ['kind' => 'customer', 'id' => $k->id, 'doc' => $k, 'sid' => $sid]; }
  return $anon;
}
function isStaff(array $a): bool { return $a['kind'] === 'user' && in_array($a['role'], ['admin', 'gerente'], true); }
function isAdmin(array $a): bool { return $a['kind'] === 'user' && $a['role'] === 'admin'; }
function meOf(array $a) { return $a['kind'] === 'user' ? ['kind' => 'user', 'id' => $a['id'], 'role' => $a['role']] : ($a['kind'] === 'customer' ? ['kind' => 'customer', 'id' => $a['id']] : ['kind' => 'anon']); }

/* ---------- límite de intentos ---------- */
function clientIp(): string { return substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 60); }
function rate(string $key, int $max, int $windowMs = 60000): bool {
  $k = substr('r|' . $key . '|' . clientIp(), 0, 120); $now = nowms();
  $r = one('SELECT t,n FROM gt_rl WHERE k=?', [$k]);
  if (!$r || $now - (int)$r['t'] > $windowMs) { if ($r) q('UPDATE gt_rl SET t=?,n=1 WHERE k=?', [$now, $k]); else q('INSERT INTO gt_rl(k,t,n) VALUES(?,?,1)', [$k, $now]); return false; }
  q('UPDATE gt_rl SET n=n+1 WHERE k=?', [$k]); return (int)$r['n'] + 1 > $max;
}
function throttled(): bool { $r = one('SELECT t,n FROM gt_rl WHERE k=?', ['f|' . clientIp()]); return $r && nowms() - (int)$r['t'] < 600000 && (int)$r['n'] >= 10; }
function noteFail(): void {
  $k = 'f|' . clientIp(); $now = nowms(); $r = one('SELECT t,n FROM gt_rl WHERE k=?', [$k]);
  if (!$r) q('INSERT INTO gt_rl(k,t,n) VALUES(?,?,1)', [$k, $now]);
  elseif ($now - (int)$r['t'] > 600000) q('UPDATE gt_rl SET t=?,n=1 WHERE k=?', [$now, $k]);
  else q('UPDATE gt_rl SET n=n+1 WHERE k=?', [$k]);
}

/* ---------- HTTP ---------- */
function send(int $code, $obj, array $headers = []): void {
  $body = jenc($obj);
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
  foreach ($headers as $k => $v) header("$k: $v");
  if (strlen($body) > 1024 && stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false && function_exists('gzencode')) { header('Content-Encoding: gzip'); header('Vary: Accept-Encoding'); $body = gzencode($body, 5); }
  header('Content-Length: ' . strlen($body));
  echo $body; exit;
}
function readBody(): ?stdClass {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return new stdClass();
  $b = json_decode($raw, false);
  if (!($b instanceof stdClass)) send(400, ['error' => 'JSON no válido']);
  return $b;
}
