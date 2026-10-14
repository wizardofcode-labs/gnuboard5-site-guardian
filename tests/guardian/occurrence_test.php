<?php
// 전용 임시 소켓만 사용한다. 운영 설정/DB/메일·SMS API는 로드하지 않는다.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/test_bootstrap.php';
define('_GNUBOARD_', true);
define('G5_BBS_URL', 'https://fixture.invalid/bbs');
$root = getenv('GUARDIAN_TEST_ROOT') ?: dirname(__DIR__, 2);
$lib = $root . '/src/plugin/gb_guardian/lib';
$install = $root . '/src/plugin/gb_guardian/install.php';
$pdo = new PDO('mysql:unix_socket=' . guardian_test_socket() . ';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('CREATE DATABASE IF NOT EXISTS guardian_occurrence_fixture CHARACTER SET utf8mb4');
$pdo->exec('USE guardian_occurrence_fixture');
$missing = in_array('--missing', $argv, true);
$g5 = array('guardian_log_table' => 'fixture_log', 'guardian_occurrence_table' => 'fixture_occurrence',
    'guardian_notify_log_table' => 'fixture_notify', 'guardian_config_table' => 'fixture_config');
$config = array('cf_guardian_use' => 1, 'cf_guardian_default_cooldown_min' => 30, 'cf_guardian_log_keep_days' => 30);
$phpLog = tempnam(sys_get_temp_dir(), 'guardian-report-');
ini_set('error_log', $phpLog);
register_shutdown_function(function () use ($phpLog) {
    if (function_exists('guardian_timing_flush')) guardian_timing_flush();
    @unlink($phpLog);
});
$checks = 0;
$events = array();
$sends = 0;
$rejectInsert = false;
$rejectOccurrence = '';

function check($condition, $label) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($label);
}
function sql_escape_string($value) { global $pdo; return substr($pdo->quote((string)$value), 1, -1); }
function sql_query($sql, $error = true) {
    global $pdo, $rejectInsert, $rejectOccurrence;
    if ($rejectInsert && strpos($sql, 'INSERT INTO `fixture_log`') !== false) { $rejectInsert = false; return false; }
    if (strpos($sql, 'INSERT INTO `fixture_occurrence`') !== false) {
        if ($rejectOccurrence === 'false') return false;
        if ($rejectOccurrence === 'exception') throw new RuntimeException('fixture detail failure');
        if ($rejectOccurrence === 'engine') throw new TypeError('fixture detail engine failure');
    }
    try { return $pdo->query($sql); }
    catch (PDOException $e) {
        // 상세 테이블 설치 전 존재 확인 실패만 허용. 다른 SQL 오류는 테스트 실패.
        if (strpos($sql, 'SELECT occurrence_id FROM `fixture_occurrence` LIMIT 0') !== false) return false;
        throw $e;
    }
}
function sql_fetch($sql, $error = true) { $r = sql_query($sql, $error); return $r ? $r->fetch(PDO::FETCH_ASSOC) : array(); }
function sql_fetch_array($r) { return $r->fetch(PDO::FETCH_ASSOC); }
function sql_free_result($r) { $r->closeCursor(); }
function sql_insert_id() { global $pdo; return (int)$pdo->lastInsertId(); }
function run_event($event, $data) {
    global $events, $sends;
    $events[] = $data;
    if (guardian_check_notify_cooldown($data['error_hash'], 'email', 'fixture@example.invalid', 30)) {
        $sends++;
        sql_query("INSERT INTO fixture_notify (error_hash, channel, recipient, status, sent_dt) VALUES ('" . sql_escape_string($data['error_hash']) . "', 'email', 'fixture@example.invalid', 'success', NOW())");
    }
}

// 실제 설치 DDL을 사용해 키/문자셋까지 확인한다.
$source = file_get_contents($install);
$charset = 'utf8mb4'; $tbl_log = 'fixture_log'; $tbl_occurrence = 'fixture_occurrence';
$start = strpos($source, '$sql_log =');
eval(substr($source, $start, strpos($source, '// --- 2.2', $start) - $start));
$start = strpos($source, '$sql_occurrence =');
eval(substr($source, $start, strpos($source, '$tables =', $start) - $start));
$tbl_guardian_config = 'fixture_config';
$start = strpos($source, '$sql_guardian_config =');
eval(substr($source, $start, strpos($source, '";', $start) + 2 - $start));
foreach (array('fixture_occurrence', 'fixture_log', 'fixture_notify', 'fixture_config') as $table) $pdo->exec('DROP TABLE IF EXISTS `' . $table . '`');
$pdo->exec($sql_guardian_config);
$pdo->exec($sql_log);
if (!$missing) { $pdo->exec($sql_occurrence); $pdo->exec($sql_occurrence); }
$pdo->exec("CREATE TABLE fixture_notify (notify_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, error_hash VARCHAR(64), channel VARCHAR(20), recipient VARCHAR(100), status VARCHAR(20), sent_dt DATETIME)");
require $lib . '/guardian_db.lib.php';
require $lib . '/guardian_config.lib.php';
require $lib . '/guardian_report.lib.php';
require $lib . '/guardian.lib.php';
require $lib . '/error_handler.lib.php';
require $lib . '/guardian_protector.lib.php';
require $lib . '/guardian_summary.lib.php';

function capture_report($id, $source = 'gateway', $failure = 'transport_error', $file = '/fixture/external.php') {
    guardian_report_error('service_no_response', '요청에 대한 정상 응답을 받지 못했습니다.', array(
        'wr_id' => $id, 'bo_table' => 'diagnostic_logs', 'source' => $source, 'failure' => $failure,
    ), E_USER_WARNING, $file, 667);
}
capture_report(526340);
capture_report(526341);
if ($missing) {
    check((int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_log')['n'] === 2, '설치 전에는 기존 wr_id별 기록 유지');
    check(strpos($events[0]['message'], 'wr_id=526340') !== false, '설치 전 상세값 유실 방지');
    check(guardian_db_get_occurrences(1)['available'] === false, '설치 전 상세 화면 안내 상태');
    $context = array('wr_id' => 900001, 'bo_table' => 'diagnostic_logs', 'source' => 'gateway', 'failure' => 'transport_error');
    check(!guardian_report_error('service_no_response', '설치 전 구조화 보고', $context, E_USER_WARNING, '/fixture/report.php', 10), '상세 테이블 미설치 시 false 반환');
    $context['wr_id'] = 900002;
    guardian_report_error('service_no_response', '설치 전 구조화 보고', $context, E_USER_WARNING, '/fixture/report.php', 10);
    check((int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_log')['n'] === 4, '설치 전 구조화 보고도 발생별 wr_id 보존');
    $log = file_get_contents($phpLog);
    check(strpos($log, '900001') !== false && strpos($log, '900002') !== false, '설치 전 PHP 로그에도 각 wr_id 기록');
    echo "PASS missing: {$checks}\n";
    exit;
}
check(count($events) === 2 && $events[0]['log_id'] === $events[1]['log_id'], 'wr_id만 다르면 같은 그룹');
check($sends === 1, '같은 그룹의 두 번째 알림은 실제 쿨다운 SQL로 차단');
check((int)sql_fetch('SELECT occurrence_count FROM fixture_log')['occurrence_count'] === 2, '그룹 횟수 누적');
$group = $events[0]['log_id'];
$page = guardian_db_get_occurrences($group);
check(array_column($page['items'], 'wr_id') === array(526341, 526340), '발생별 wr_id 최신순 보존');
check(strpos($page['items'][0]['log_url'], 'bo_table=diagnostic_logs&wr_id=526341') !== false, '기존 게시판 로그 링크');
check(strpos($events[0]['message'], 'wr_id=') === false && strpos($events[0]['message'], 'failure=transport_error') !== false, '그룹 메시지는 상세값 제외/실패코드 유지');
capture_report(526342, 'secondary');
capture_report(526343, 'gateway', 'http_error');
check($events[2]['log_id'] !== $group && $events[3]['log_id'] !== $group && $events[2]['log_id'] !== $events[3]['log_id'], 'source/failure 변경은 별도 그룹');
check($sends === 3, '다른 source/failure는 별도 알림');
capture_report(526344, 'gateway', 'transport_error', '/fixture/other_service.php');
check($events[4]['log_id'] !== $group, '다른 파일은 별도 그룹');
sql_query("UPDATE fixture_log SET created_dt = DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE log_id = {$group}");
sql_query('UPDATE fixture_notify SET sent_dt = DATE_SUB(NOW(), INTERVAL 31 MINUTE)');
capture_report(526345);
$newGroup = end($events)['log_id'];
check($newGroup !== $group && end($events)['error_hash'] === $events[0]['error_hash'], '쿨다운 만료는 같은 해시의 새 구간');
check(guardian_db_get_occurrences($newGroup)['items'][0]['wr_id'] === 526345, '새 구간 상세 연결');
check((int)sql_fetch("SELECT COUNT(*) AS n FROM fixture_occurrence WHERE log_id = {$group}")['n'] === 2, '지난 구간 상세는 원래 그룹 유지');
$sentBefore = $sends;
capture_report(526346);
check(end($events)['log_id'] === $newGroup && $sends === $sentBefore, '새 구간에서도 중복 알림 차단');

// SQL INSERT 실패 시 이전 lastInsertId에 상세가 연결되지 않는다.
$rejectInsert = true;
$before = (int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_occurrence')['n'];
$beforeEvents = count($events);
capture_report(526347, 'n3', 'api_error');
check(count($events) === $beforeEvents && (int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_occurrence')['n'] === $before, '그룹 저장 실패의 상세 오연결 및 발송 방지');

// 50건 경계를 넘는 모든 내역을 중복 없이 조회.
for ($i = 0; $i < 55; $i++) capture_report(600000 + $i);
$first = guardian_db_get_occurrences($newGroup);
$second = guardian_db_get_occurrences($newGroup, $first['next_before_id']);
check(count($first['items']) === 50 && count($second['items']) === 7 && $second['next_before_id'] === 0, '커서 페이지로 57건 모두 조회');
check(count(array_unique(array_merge(array_column($first['items'], 'wr_id'), array_column($second['items'], 'wr_id')))) === 57, '페이지 간 중복 없음');

// 미해결 상세 만료, 해결 그룹 만료, 고아 상세 정리; 게시판 원본은 유지.
$pdo->exec('CREATE TABLE IF NOT EXISTS fixture_original (wr_id BIGINT PRIMARY KEY)');
$pdo->exec('REPLACE INTO fixture_original VALUES (526340)');
sql_query("UPDATE fixture_occurrence SET occurred_dt = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE wr_id = 526340");
sql_query("UPDATE fixture_log SET resolved = 1, created_dt = DATE_SUB(NOW(), INTERVAL 31 DAY) WHERE log_id = " . $events[2]['log_id']);
guardian_db_insert_occurrence(999999, array('wr_id' => 700000, 'bo_table' => 'diagnostic_logs', 'source' => 'gateway', 'failure' => 'transport_error'));
guardian_cleanup_old_data();
check((int)sql_fetch("SELECT COUNT(*) AS n FROM fixture_log WHERE log_id = {$group}")['n'] === 1, '미해결 그룹은 기간 경과 후 유지');
check((int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_occurrence WHERE wr_id IN (526340, 526342, 700000)')['n'] === 0, '만료 상세/삭제 부모 상세/고아 상세 정리');
check((int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_original')['n'] === 1, '기존 게시판 원본 자료 보존');
check((int)sql_fetch('SELECT occurrence_count FROM fixture_log WHERE log_id = ' . $group)['occurrence_count'] === 2, '상세 만료 후 누적 횟수 유지');

// 한 번에 1000건만 제거하고 다음 실행에서 계속 정리.
for ($i = 0; $i < 1001; $i++) $pdo->exec("INSERT INTO fixture_occurrence (log_id, wr_id, source, failure, occurred_dt) VALUES ({$newGroup}, " . (800000 + $i) . ", 'gateway', 'transport_error', DATE_SUB(NOW(), INTERVAL 31 DAY))");
guardian_cleanup_old_data();
check((int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_occurrence WHERE wr_id >= 800000')['n'] === 1, '정리 실행당 1000건 제한');
guardian_cleanup_old_data();
check((int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_occurrence WHERE wr_id >= 800000')['n'] === 0, '다음 정리 실행에서 잔여 삭제');
check(guardian_db_delete_logs(array($newGroup, $newGroup, -1)) === true, '수동 그룹 삭제 성공');
check((int)sql_fetch('SELECT COUNT(*) AS n FROM fixture_occurrence WHERE log_id = ' . $newGroup)['n'] === 0, '수동 삭제 시 연결 상세 제거');
check(guardian_db_delete_logs(array(0, -1)) === false, '잘못된 ID로 전체 삭제하지 않음');

// 실제 수집 핸들러를 등록해도 공용 API는 trigger_error를 호출하지 않아 중복 캡처가 없다.
$handlerCalls = 0;
set_error_handler(function ($errno, $message, $file, $line) use (&$handlerCalls) {
    $handlerCalls++;
    return guardian_on_error($errno, $message, $file, $line);
});
$context = array('wr_id' => 900010, 'bo_table' => 'diagnostic_logs', 'source' => 'gateway', 'failure' => 'transport_error');
$startEvents = count($events); $startSends = $sends;
check(guardian_report_error('service_no_response', '첫 설명', $context, E_USER_WARNING, '/fixture/service.php', 100), '구조화 API 기록 성공');
$context['wr_id'] = 900011;
check(guardian_report_error('service_no_response', '문구를 수정한 설명', $context, E_USER_WARNING, '/fixture/service.php', 200), '설명 수정 후에도 보고 성공');
$structuredGroup = $events[$startEvents]['log_id'];
check(count($events) === $startEvents + 2 && $handlerCalls === 0, '각 보고는 한 번만 캡처되고 PHP 오류 핸들러 재호출 없음');
check(end($events)['log_id'] === $structuredGroup && $sends === $startSends + 1, '문구/줄번호/wr_id 변경에도 같은 그룹·알림 쿨다운');
check((int)sql_fetch('SELECT occurrence_count FROM fixture_log WHERE log_id = ' . $structuredGroup)['occurrence_count'] === 2, '공용 API 횟수 이중 누적 없음');
check(count(guardian_db_get_occurrences($structuredGroup)['items']) === 2, '공용 API 발생 상세 두 건');
guardian_report_error('other_error', '첫 설명', $context, E_USER_WARNING, '/fixture/service.php', 100);
check(end($events)['log_id'] !== $structuredGroup, '오류 식별자 변경은 별도 그룹');
$context['source'] = 'secondary';
guardian_report_error('service_no_response', '첫 설명', $context, E_USER_WARNING, '/fixture/service.php', 100);
check(end($events)['log_id'] !== $structuredGroup, '공용 API source 변경은 별도 그룹');
$sourceGroup = end($events)['log_id'];
$context['failure'] = 'http_error';
guardian_report_error('service_no_response', '첫 설명', $context, E_USER_WARNING, '/fixture/service.php', 100);
check(end($events)['log_id'] !== $sourceGroup, '공용 API failure 변경은 별도 그룹');

$context['bo_table'] = 'orders_log';
$context['wr_id'] = 900021;
guardian_report_error('order_process_failed', '다른 서비스의 오류', $context, E_USER_WARNING, '/fixture/orders.php', 30);
$generic = guardian_db_get_occurrences(end($events)['log_id']);
check(strpos($generic['items'][0]['log_url'], 'bo_table=orders_log') !== false, '다른 게시판에서도 공용 모듈 참조 사용');
$log = file_get_contents($phpLog);
check(substr_count($log, '900010') === 1 && substr_count($log, '900011') === 4, 'PHP 로그는 보고 호출별 한 번씩 기록');

// 상세 저장 실패는 false로 보고하되 그룹/횟수/알림 쿨다운은 유지한다.
// 신규/기존 그룹과 false 반환, 예외 및 엔진 오류를 검사한다.
$failureEvents = count($events); $failureSends = $sends;
$failureHandlerCalls = $handlerCalls;
$context = array('wr_id' => 910000, 'bo_table' => 'diagnostic_logs', 'source' => 'gateway', 'failure' => 'transport_error');
$failedGroup = 0; $failedCount = 0;
foreach (array('false', 'exception', 'engine') as $mode) {
    $rejectOccurrence = $mode;
    for ($i = 0; $i < 2; $i++) {
        $context['wr_id']++;
        ob_start();
        $reported = guardian_report_error('detail_write_failure', '상세 실패 시험', $context, E_USER_WARNING, '/fixture/detail.php', 10);
        $output = ob_get_clean();
        $failedCount++;
        $id = end($events)['log_id'];
        if (!$failedGroup) $failedGroup = $id;
        check(!$reported && $output === '' && $handlerCalls === $failureHandlerCalls, $mode . ': 실패 반환, 응답 출력 및 추가 경고 없음');
        check($id === $failedGroup && count($events) === $failureEvents + $failedCount, $mode . ': 그룹과 알림 훅 유지');
        check((int)sql_fetch('SELECT occurrence_count FROM fixture_log WHERE log_id = ' . $id)['occurrence_count'] === $failedCount, $mode . ': 횟수 누적 유지');
        check(count(guardian_db_get_occurrences($id)['items']) === 0 && $sends === $failureSends + 1, $mode . ': 상세 실패에도 알림은 한 번');
        $failureLog = file_get_contents($phpLog);
        preg_match_all('/발생 상세 저장 실패 log_id=' . $id . ' context=([^\r\n]+)/u', $failureLog, $matches);
        check(count($matches[1]) === $failedCount, $mode . ': 상세 실패를 PHP 로그에 명시');
        check(json_decode(end($matches[1]), true) === array('source' => 'gateway', 'failure' => 'transport_error',
            'bo_table' => 'diagnostic_logs', 'wr_id' => $context['wr_id']), $mode . ': 실패 참조와 분류값 보존');
        $lockName = 'gbg:' . substr(hash('sha256', serialize(array('guardian_occurrence_fixture', 'fixture_log', 'capture',
            array(end($events)['error_hash'])))), 0, 60);
        check(sql_fetch("SELECT IS_USED_LOCK('{$lockName}') AS owner")['owner'] === null, $mode . ': 실패 후 DB 그룹 잠금 해제');
    }
}
$rejectOccurrence = '';
// 실제 SQL 실패(PDOException)도 가짜 반환과 동일하게 처리한다.
$pdo->exec("CREATE TRIGGER fixture_detail_fail BEFORE INSERT ON fixture_occurrence FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture detail write rejected'");
try {
    $context['wr_id']++;
    check(!guardian_report_error('detail_write_failure', '상세 실패 시험', $context, E_USER_WARNING, '/fixture/detail.php', 10), '실제 상세 INSERT 거절도 실패 반환');
    check(end($events)['log_id'] === $failedGroup && $sends === $failureSends + 1, '실제 SQL 실패에도 그룹과 알림 쿨다운 유지');
} finally {
    $pdo->exec('DROP TRIGGER fixture_detail_fail');
}
$failureLog = file_get_contents($phpLog);
check(substr_count($failureLog, '발생 상세 저장 실패 log_id=' . $failedGroup) === 7, '실패마다 정확히 한 번 상세 저장 실패 기록');
// 장애 해제 후 같은 요청에서 재진입 가드와 DB 잠금이 해제되어 있어야 한다.
$context['wr_id']++;
check(guardian_report_error('detail_write_failure', '복구 시험', $context, E_USER_WARNING, '/fixture/detail.php', 10), '장애 해제 후 상세 저장 성공');
check(end($events)['log_id'] === $failedGroup && (int)sql_fetch('SELECT occurrence_count FROM fixture_log WHERE log_id = ' . $failedGroup)['occurrence_count'] === 8, '복구 후 동일 그룹 횟수 유지');
check(array_column(guardian_db_get_occurrences($failedGroup)['items'], 'wr_id') === array($context['wr_id']) && $sends === $failureSends + 1, '복구 상세 저장 및 중복 알림 방지');
check(guardian_db_insert_occurrence(0, $context) === false, '잘못된 그룹 ID는 저장 실패 반환');

// 일반 PHP 메시지의 숫자·게시판 참조는 해석하지 않는다.
$nativeStart = count($events);
guardian_on_error(E_USER_WARNING, '오류 wr_id=1 source=gateway failure=transport_error', '/fixture/native.php', 10);
guardian_on_error(E_USER_WARNING, '오류 wr_id=2 source=gateway failure=transport_error', '/fixture/native.php', 20);
check(count($events) === $nativeStart + 2 && $events[$nativeStart]['log_id'] !== end($events)['log_id'], '일반 PHP 오류의 글 ID 문자열을 임의로 그룹화하지 않음');
$nativeGroup = end($events)['log_id'];
guardian_on_error(E_USER_WARNING, '오류 wr_id=2 source=gateway failure=transport_error', '/fixture/native.php', 30);
check(end($events)['log_id'] === $nativeGroup && count(guardian_db_get_occurrences($nativeGroup)['items']) === 0, '일반 PHP 메시지의 기존 그룹화 유지·구조화 상세 추출 없음');
check(guardian_report_error('service_without_board', '게시판 없이 보고', array('source' => 'gateway'), E_USER_WARNING, '/fixture/no_board.php', 10), '게시판 참조 없는 공통 오류 보고 성공');
check(count(guardian_db_get_occurrences(end($events)['log_id'])['items']) === 0, '게시판 참조 없는 오류는 그룹만 기록');
restore_error_handler();
echo "PASS: {$checks}\n";
