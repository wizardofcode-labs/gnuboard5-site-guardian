<?php
// 전용 임시 DB와 가짜 어댑터만 사용한다. 운영 설정/메일/SMS API 접근 없음.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/test_bootstrap.php';
define('_GNUBOARD_', true);
define('G5_BBS_URL', 'https://fixture.invalid/bbs');
$root = getenv('GUARDIAN_TEST_ROOT') ?: dirname(__DIR__, 2);
$lib = $root . '/src/plugin/gb_guardian/lib';
$pdo = new PDO('mysql:unix_socket=' . guardian_test_socket() . ';dbname=guardian_concurrency_fixture;charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$g5 = array('guardian_log_table'=>'fixture_log', 'guardian_occurrence_table'=>'fixture_occurrence',
    'guardian_notify_log_table'=>'fixture_notify', 'guardian_rule_table'=>'fixture_rule', 'guardian_recipient_table'=>'fixture_recipient');
$config = array('cf_guardian_use'=>1, 'cf_guardian_default_cooldown_min'=>30,
    'cf_guardian_aligo_enabled'=>1, 'cf_guardian_sms_silent_enabled'=>0,
    'cf_guardian_email_daily_limit'=>100, 'cf_guardian_sms_daily_limit'=>100, 'cf_guardian_kakao_daily_limit'=>100);
$fixtureFailure = ''; $fixtureCase = ''; $fixtureDelay = false; $fixtureLockCalls = 0;
class AligoSMS {}
class KakaoAlimtalk {}
function sql_escape_string($value) { global $pdo; return substr($pdo->quote((string)$value), 1, -1); }
function sql_query($sql, $error = true) {
    global $pdo, $fixtureFailure, $fixtureDelay;
    if ($fixtureFailure === 'reserve' && strpos($sql, 'INSERT INTO `fixture_notify`') !== false) return false;
    if ($fixtureFailure === 'finalize' && strpos($sql, 'UPDATE `fixture_notify`') !== false && strpos($sql, "status = 'unknown'") === false) return false;
    if ($fixtureFailure === 'group' && strpos($sql, 'INSERT INTO `fixture_log`') !== false) return false;
    $r = $pdo->query($sql);
    // 이전 코드에서 경쟁이 발생한 조회→쓰기와 쿨다운→발송 구간을 충분히 겹치게 한다.
    if ($fixtureDelay && (strpos($sql, 'SELECT log_id FROM `fixture_log`') !== false || strpos($sql, 'SELECT notify_id') !== false)) usleep(100000);
    return $r;
}
function sql_fetch($sql, $error = true) {
    global $fixtureFailure, $fixtureLockCalls;
    if ($fixtureFailure === 'lock' && strpos($sql, 'GET_LOCK(') !== false) return array('acquired'=>0);
    if (strpos($sql, 'GET_LOCK(') !== false) {
        $fixtureLockCalls++;
        if ($fixtureFailure === 'notify_lock' && $fixtureLockCalls > 1) return array('acquired'=>0);
    }
    $r = sql_query($sql, $error); return $r ? ($r->fetch(PDO::FETCH_ASSOC) ?: array()) : array();
}
function sql_fetch_array($r) { return $r->fetch(PDO::FETCH_ASSOC); }
function sql_free_result($r) { $r->closeCursor(); }
function sql_insert_id() { global $pdo; return (int)$pdo->lastInsertId(); }
function guardian_render_template($channel, array $data) { return array('subject'=>'fixture', 'body'=>'fixture'); }
function fixture_delivery($channel, $recipient) {
    global $pdo, $fixtureFailure;
    $name = 'gbg:' . substr(hash('sha256', serialize(array('guardian_concurrency_fixture', 'fixture_log', 'notify_reservation', array()))), 0, 60);
    $owner = $pdo->query("SELECT IS_USED_LOCK('{$name}')")->fetchColumn();
    $connection = (int)$pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
    $free = $owner === null || (int)$owner !== $connection ? 1 : 0;
    $stmt = $pdo->prepare('INSERT INTO fixture_deliveries (channel, recipient, reservation_lock_free) VALUES (?, ?, ?)');
    $stmt->execute(array($channel, $recipient, $free));
    usleep(250000);
    return array('success'=>$fixtureFailure !== 'route', 'reason'=>$fixtureFailure === 'route' ? 'fixture failure' : '');
}
function guardian_send_email($to, $subject, $body) { return fixture_delivery('email', $to); }
function guardian_send_sms($to, $body) { return fixture_delivery('sms', $to); }
function guardian_send_kakao($to, $body, $data) { return fixture_delivery('kakao', $to); }
function run_event($event, ...$args) {
    global $fixtureFailure;
    if ($event === 'guardian_after_capture') guardian_dispatch_error($args[0]);
    if ($event === 'guardian_before_notify' && $fixtureFailure === 'hook') throw new RuntimeException('fixture hook exception');
    if ($event === 'guardian_before_notify' && $fixtureFailure === 'engine_hook') throw new TypeError('fixture engine exception');
}
foreach (array('guardian_db.lib.php','guardian_report.lib.php','guardian.lib.php','error_handler.lib.php',
    'guardian_protector.lib.php','guardian_notifier.lib.php','guardian_rule_engine.lib.php') as $name) require $lib . '/' . $name;
function fixture_hash($case) { return hash('sha256', json_encode(array('WARNING', 'parallel_' . $case, '/fixture/parallel.php', 'gateway', 'transport_error'))); }
function fixture_report($case, $id) {
    return guardian_report_error('parallel_' . $case, '동시 요청 fixture', array('wr_id'=>$id,
        'bo_table'=>'diagnostic_logs', 'source'=>'gateway', 'failure'=>'transport_error'), E_USER_WARNING, '/fixture/parallel.php', 10);
}
if (isset($argv[1]) && $argv[1] === 'worker') {
    $fixtureCase = $argv[2]; $id = (int)$argv[3]; $gate = $argv[4];
    $fixtureFailure = isset($argv[5]) ? $argv[5] : '';
    if ($fixtureFailure === 'daily_limit') $config['cf_guardian_email_daily_limit'] = 1;
    $fixtureDelay = true;
    ini_set('error_log', $gate . '/php-' . $id . '.log');
    file_put_contents($gate . '/ready-' . $id, 'ready');
    $deadline = microtime(true) + 10;
    while (!file_exists($gate . '/go')) {
        if (microtime(true) > $deadline) throw new RuntimeException('worker gate timeout');
        usleep(1000);
    }
    echo json_encode(array('id'=>$id, 'recorded'=>fixture_report($fixtureCase, $id))), "\n";
}
