<?php
// 운영 DB/외부 API 없이 공용 API와 비활성 부트스트랩을 확인한다.
if (PHP_SAPI !== 'cli') exit;
define('_GNUBOARD_', true);
$root = getenv('GUARDIAN_TEST_ROOT') ?: dirname(__DIR__, 2);
define('G5_PLUGIN_PATH', $root . '/src/plugin');
define('G5_PLUGIN_URL', 'https://fixture.invalid/plugin');
define('G5_TABLE_PREFIX', 'fixture_');
$mode = isset($argv[1]) ? $argv[1] : 'active';
$config = array('cf_guardian_use' => $mode === 'disabled' ? 0 : 1);
if ($mode === 'uninstalled') $config = array();
if ($mode === 'filtered') $config['cf_guardian_collect_levels'] = 'ERROR';
$dbCalls = 0; $captures = array(); $captureFailure = ''; $checks = 0;
function check($condition, $label) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($label);
}
function sql_escape_string($value) { return addslashes($value); }
function sql_fetch($sql, $error = false) { global $dbCalls; $dbCalls++; return array(); }
function sql_query($sql, $error = false) { throw new RuntimeException('공용 보고 API는 비활성 상태에서 SQL을 실행하면 안 됩니다.'); }
function guardian_capture(array $data) {
    global $captures, $captureFailure;
    $captures[] = $data;
    if ($captureFailure === 'exception') throw new RuntimeException('fixture capture failure');
    if ($captureFailure === 'engine') throw new TypeError('fixture engine failure');
    return $captureFailure === 'database' ? 0 : 17;
}
if ($mode === 'disabled' || $mode === 'uninstalled') {
    require $root . '/src/extend/gb_guardian.extend.php';
} else {
    require G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_report.lib.php';
    require G5_PLUGIN_PATH . '/gb_guardian/lib/guardian.lib.php';
}
check(function_exists('guardian_report_error'), '비활성/미설치 여부와 무관하게 공용 API 로드');
$dbCalls = 0;
$phpLog = tempnam(sys_get_temp_dir(), 'guardian-report-unit-');
ini_set('error_log', $phpLog);
register_shutdown_function(function () use ($phpLog) { guardian_timing_flush(); @unlink($phpLog); });
$warnings = 0;
set_error_handler(function () use (&$warnings) { $warnings++; return true; });
$context = array('wr_id' => 526341, 'bo_table' => 'diagnostic_logs', 'source' => 'gateway', 'failure' => 'transport_error');
ob_start();
$reported = guardian_report_error('service_no_response', 'API 오류 설명', $context, E_USER_WARNING, '/fixture/report.php', 123);
$output = ob_get_clean();
check($output === '' && $warnings === 0, 'PHP 경고 이벤트/응답 출력 없이 보고');
check($dbCalls === 0, '비활성 보고에서 DB 조회 없음');
$log = file_get_contents($phpLog);
check(substr_count($log, '[GB Guardian]') === 1 && strpos($log, '526341') !== false, 'PHP 로그에 정확히 한 번 wr_id 기록');
check(strpos($log, 'report.php:123') !== false && strpos($log, 'transport_error') !== false, '호출 위치와 분류정보 보존');
if ($mode !== 'active') {
    check($reported === false && count($captures) === 0, '비활성/미설치/수집 제외 시 Guardian 캡처 없음');
    restore_error_handler();
    echo "PASS {$mode}: {$checks}\n";
    exit;
}
check($reported === true && count($captures) === 1, '활성 API는 Guardian에 한 번만 구조화 보고');
check($captures[0]['error_code'] === 'service_no_response' && $captures[0]['context']['wr_id'] === 526341, '오류 식별자와 상세 배열 전달');
$autoLine = __LINE__ + 1;
guardian_report_error('auto_location', '자동 호출 위치');
check($captures[1]['file'] === __FILE__ && $captures[1]['line'] === $autoLine, '호출 위치 자동 확인');
$captureFailure = 'database';
check(guardian_report_error('db_failed', 'DB 기록 실패') === false, 'DB 기록 실패는 false 반환');
$captureFailure = 'exception';
check(guardian_report_error('capture_failed', '기록 중 예외') === false, '캡처 예외를 호출부에 전파하지 않음');
$captureFailure = 'engine';
check(guardian_report_error('engine_failed', '기록 중 엔진 오류') === false, '캡처 엔진 오류를 호출부에 전파하지 않음');
$captureFailure = '';
$before = count($captures);
check(guardian_report_error('invalid code', '잘못된 식별자') === false && count($captures) === $before, '잘못된 식별자는 PHP 로그만 기록');
check(guardian_report_error('invalid_level', '잘못된 등급', array(), E_WARNING) === false, '지원하지 않는 등급은 PHP 로그만 기록');
guardian_report_error('clean_log', "message\nFAKE LINE user@example.com", array('source' => 'private@example.com', 'authorization' => 'secret-token', 'bo_table' => '../invalid'), E_USER_WARNING, '/fixture/report.php', 124);
$log = file_get_contents($phpLog);
check(strpos($log, 'user@example.com') === false && strpos($log, 'private@example.com') === false, 'PHP 로그 개인정보 마스킹');
check(strpos($log, 'secret-token') === false && strpos($log, "\nFAKE LINE") === false, '임의 인증 필드 제외 및 로그 줄 삽입 방지');
check(end($captures)['context']['bo_table'] === '', '부적절한 게시판 식별자 제외');
check(substr_count($log, '[GB Guardian]') === 8, '예외/DB 실패/입력 오류도 각 보고당 PHP 로그 한 줄');
restore_error_handler();
echo "PASS active: {$checks}\n";
