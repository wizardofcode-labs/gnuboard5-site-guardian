<?php
// 임시 소켓/fixture DB만 사용한다. 운영 DB 및 외부 발송 접근 없음.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/test_bootstrap.php';
define('_GNUBOARD_', true);
$root = getenv('GUARDIAN_TEST_ROOT') ?: dirname(__DIR__, 2);
$pdo = new PDO('mysql:unix_socket=' . guardian_test_socket() . ';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
$pdo->exec('CREATE DATABASE IF NOT EXISTS guardian_performance_fixture CHARACTER SET utf8mb4');
$pdo->exec('USE guardian_performance_fixture');
$g5 = array('guardian_log_table'=>'fixture_log', 'guardian_occurrence_table'=>'fixture_occurrence',
    'guardian_notify_log_table'=>'fixture_notify', 'guardian_config_table'=>'fixture_config');
$config = array('cf_guardian_email_daily_limit'=>4);
$checks = 0; $queries = array(); $failDelete = false; $failConfig = false;
function check($ok, $message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
function sql_escape_string($value) { global $pdo; return substr($pdo->quote((string)$value), 1, -1); }
function sql_query($sql, $error = true) {
    global $pdo, $queries, $failDelete, $failConfig;
    $queries[] = $sql;
    if ($failDelete && strpos($sql, 'DELETE o FROM `fixture_occurrence`') !== false) return false;
    if ($failConfig && strpos($sql, 'INSERT INTO `fixture_config`') !== false) return false;
    return $pdo->query($sql);
}
function sql_fetch($sql, $error = true) { $r = sql_query($sql, $error); return $r ? ($r->fetch(PDO::FETCH_ASSOC) ?: array()) : array(); }
function sql_fetch_array($r) { return $r->fetch(PDO::FETCH_ASSOC); }
function sql_free_result($r) { $r->closeCursor(); }
function sql_insert_id() { global $pdo; return (int)$pdo->lastInsertId(); }
$ddl = file_get_contents($root . '/src/plugin/gb_guardian/install.php');
$charset='utf8mb4'; $tbl_log='fixture_log'; $tbl_occurrence='fixture_occurrence';
$tbl_notify_log='fixture_notify'; $tbl_guardian_config='fixture_config';
foreach (array('log','occurrence','notify_log','guardian_config') as $kind) {
    $start=strpos($ddl, '$sql_' . $kind . ' =');
    eval(substr($ddl, $start, strpos($ddl, '";', $start) + 2 - $start));
    $pdo->exec('DROP TABLE IF EXISTS `' . ${'tbl_'.$kind} . '`');
    $pdo->exec(${'sql_'.$kind});
}
require $root . '/src/plugin/gb_guardian/lib/guardian_db.lib.php';
require $root . '/src/plugin/gb_guardian/lib/guardian_config.lib.php';
require $root . '/src/plugin/gb_guardian/lib/guardian_protector.lib.php';
require $root . '/src/plugin/gb_guardian/lib/guardian_summary.lib.php';

// 실제 설치 마이그레이션의 신규/기존/재실행/동등 인덱스/이름 충돌을 검증한다.
check((bool)$pdo->query("SHOW INDEX FROM fixture_notify WHERE Key_name='idx_channel_sent'")->fetch(), '신규 설치 DDL에 집계 인덱스 포함');
$start = strpos($ddl, '$notify_indexes =');
$indexMigration = substr($ddl, $start, strpos($ddl, '// --- 발송 이력 인덱스 마이그레이션 끝 ---', $start) - $start);
$pdo->exec('ALTER TABLE fixture_notify DROP INDEX idx_channel_sent');
$msg = array(); eval($indexMigration);
check(strpos(end($msg), '[MIGRATE]') === 0 && (bool)$pdo->query("SHOW INDEX FROM fixture_notify WHERE Key_name='idx_channel_sent'")->fetch(), '기존 설치 인덱스 자동 추가');
$msg = array(); eval($indexMigration);
check(strpos(end($msg), '[SKIP]') === 0, '인덱스 마이그레이션 재실행 안전');
$pdo->exec('ALTER TABLE fixture_notify DROP INDEX idx_channel_sent, ADD INDEX equivalent (channel, sent_dt, status)');
$msg = array(); eval($indexMigration);
check(strpos(end($msg), '[SKIP]') === 0 && !$pdo->query("SHOW INDEX FROM fixture_notify WHERE Key_name='idx_channel_sent'")->fetch(), '다른 이름의 동등 인덱스 유지');
$pdo->exec('ALTER TABLE fixture_notify DROP INDEX equivalent, ADD INDEX idx_channel_sent (status)');
$msg = array(); eval($indexMigration);
check(strpos(end($msg), '[FAIL]') === 0, '동일 이름의 다른 인덱스 충돌 안내');
$pdo->exec('ALTER TABLE fixture_notify DROP INDEX idx_channel_sent');
$msg = array(); eval($indexMigration);

// 과거 이력 20,000행 + 오늘 네 상태/다른 채널/자정 경계 데이터.
$pdo->beginTransaction();
$insert = $pdo->prepare("INSERT INTO fixture_notify (channel,status,sent_dt) VALUES ('email','success',DATE_SUB(CURDATE(), INTERVAL 30 DAY))");
for ($i=0; $i<20000; $i++) $insert->execute();
$pdo->commit();
$pdo->exec("INSERT INTO fixture_notify (channel,status,sent_dt) VALUES
    ('email','success',CURDATE()), ('email','failed',DATE_ADD(CURDATE(),INTERVAL 1 SECOND)),
    ('email','pending',DATE_ADD(CURDATE(),INTERVAL 2 SECOND)), ('email','unknown',DATE_ADD(CURDATE(),INTERVAL 3 SECOND)),
    ('sms','success',CURDATE()), ('email','success',DATE_SUB(CURDATE(),INTERVAL 1 SECOND)),
    ('email','success',DATE_ADD(CURDATE(),INTERVAL 1 DAY))");
$analyze=$pdo->query('ANALYZE TABLE fixture_notify');
$analyze->fetchAll(); $analyze->closeCursor();
check(guardian_today_sent_count('email') === 4 && guardian_today_sent_count('sms') === 1, '일일 집계: 네 상태 포함, 채널/자정 경계 유지');
check(!guardian_check_daily_limit('email'), '한도와 같은 횟수면 차단');
$config['cf_guardian_email_daily_limit']=5;
check(guardian_check_daily_limit('email'), '한도 미만이면 허용');
$dailyQuery = end($queries);
$oldQuery = "SELECT COUNT(*) FROM fixture_notify WHERE channel='email' AND DATE(sent_dt)=CURDATE()";
check((int)$pdo->query($oldQuery)->fetchColumn() === 4, '수정 전후 집계 결과 동일');
$newPlan = $pdo->query('EXPLAIN ' . $dailyQuery)->fetch(PDO::FETCH_ASSOC);
$oldPlan = $pdo->query('EXPLAIN ' . str_replace('FROM fixture_notify',
    'FROM fixture_notify IGNORE INDEX (idx_channel_sent)', $oldQuery))->fetch(PDO::FETCH_ASSOC);
check($newPlan['type'] === 'range' && $newPlan['key'] === 'idx_channel_sent' && strpos($newPlan['Extra'],'Using index') !== false, '새 집계는 날짜 범위와 커버링 인덱스 사용');
check((int)$newPlan['rows'] < (int)$oldPlan['rows'], '새 집계의 예상 검사 행 수 감소');
echo '집계 EXPLAIN (기존 인덱스/새 인덱스): old=', $oldPlan['type'], '/', $oldPlan['rows'], ' new=', $newPlan['type'], '/', $newPlan['rows'], "\n";

$parent = guardian_db_insert(array('level'=>'WARNING','error_hash'=>str_repeat('a',64),'message'=>'fixture'));
$pdo->beginTransaction();
$insert=$pdo->prepare("INSERT INTO fixture_occurrence (log_id,wr_id,source,failure,occurred_dt) VALUES (?,?,'gateway','transport_error',NOW())");
for ($i=1; $i<=2505; $i++) $insert->execute(array($i<=2500 ? $parent : 999999,$i));
$pdo->commit();
function count_details() { global $pdo; return (int)$pdo->query('SELECT COUNT(*) FROM fixture_occurrence')->fetchColumn(); }
function scan_state() { return guardian_config_get('_occurrence_orphan_scan', array()); }
for ($batch=1; $batch<=3; $batch++) {
    $beforeQueries=count($queries);
    check(guardian_cleanup_orphan_occurrences(), '고아 정리 배치 성공 ' . $batch);
    $batchQueries=array_slice($queries,$beforeQueries);
    $candidateQuery=''; $deleteQuery='';
    foreach ($batchQueries as $q) {
        if (strpos($q,'SELECT occurrence_id FROM `fixture_occurrence`') !== false && strpos($q,'ORDER BY') !== false) $candidateQuery=$q;
        if (strpos($q,'DELETE o FROM `fixture_occurrence`') !== false) $deleteQuery=$q;
    }
    check(strpos($candidateQuery,'LIMIT 1000') !== false && strpos($candidateQuery,'occurrence_id >') !== false, '후보 검사도 최대 1000행');
    preg_match('/IN \(([^)]+)\)/', $deleteQuery, $ids);
    check(isset($ids[1]) && count(explode(',',$ids[1])) <= 1000, '삭제 검사 대상도 최대 1000 ID');
    $plan=$pdo->query('EXPLAIN ' . $candidateQuery)->fetch(PDO::FETCH_ASSOC);
    check($plan['type']==='range' && $plan['key']==='PRIMARY', '후보 조회는 기본키 범위 사용');
    $deletePlan=$pdo->query('EXPLAIN ' . $deleteQuery)->fetchAll(PDO::FETCH_ASSOC);
    $plans=array();
    foreach ($deletePlan as $entry) $plans[(string)$entry['table']]=$entry;
    check(isset($plans['o']) && in_array($plans['o']['type'],array('range','eq_ref'),true)
        && $plans['o']['key']==='PRIMARY', '삭제도 지정 ID의 기본키 조회 사용');
    check(isset($plans['l']) && $plans['l']['type']==='eq_ref' && $plans['l']['key']==='PRIMARY', '부모 존재 확인도 기본키 단건 조회');
    check(count_details()===($batch<3 ? 2505 : 2500), '앞의 정상 상세를 유지하고 뒤의 고아까지 진행');
}
check(scan_state()===array('cursor'=>0,'upper'=>0), '순회 완료 시 처음으로 복귀');
$scanStart=count($queries);
check(guardian_cleanup_orphan_occurrences(2), '작은 배치로 다음 순회 시작');
$state=scan_state();
$pdo->exec("INSERT INTO fixture_occurrence (log_id,wr_id,occurred_dt) VALUES (999999,3000,NOW())");
check(scan_state()['upper']===$state['upper'], '순회 중 추가된 상세는 다음 순회 대상으로 유지');
$failDelete=true;
check(!guardian_cleanup_orphan_occurrences(2) && scan_state()===$state, '삭제 실패 시 진행 위치 유지');
$failDelete=false; $failConfig=true;
check(!guardian_cleanup_orphan_occurrences(2) && scan_state()===$state, '진행 위치 저장 실패도 재시도 가능');
$failConfig=false;
check(guardian_cleanup_orphan_occurrences(2), '실패 복구 후 다음 범위 검사');
// 요청 시작 때의 설정 캐시와 DB 진행 위치가 다르면 잠금 안에서 최신 DB 값을 사용한다.
$latest=array('cursor'=>100,'upper'=>2500);
$pdo->exec("UPDATE fixture_config SET cfg_value='" . json_encode($latest) . "' WHERE cfg_key='_occurrence_orphan_scan'");
check(guardian_cleanup_orphan_occurrences(2) && scan_state()['cursor']===102, '설정 캐시보다 최신 DB 진행 위치를 사용');
$pdo->exec('DELETE FROM fixture_log WHERE log_id=' . $parent);
for ($i=0; $i<6; $i++) check(guardian_cleanup_orphan_occurrences(), '부모 삭제 후 재순회 ' . $i);
check(count_details()===0, '이미 검사한 범위의 뒤늦은 고아와 신규 고아도 정리');
check(guardian_cleanup_orphan_occurrences(), '빈 테이블도 정상 처리');

// 다른 연결의 정리 잠금은 기다리지 않고 건너뛴다.
$other=new PDO('mysql:unix_socket=' . guardian_test_socket() . ';dbname=guardian_performance_fixture','root','');
$lock='gbg:' . substr(hash('sha256',serialize(array('guardian_performance_fixture','fixture_log','occurrence_cleanup',array()))),0,60);
$other->query("SELECT GET_LOCK('{$lock}',0)");
try {
    $started=microtime(true);
    check(!guardian_cleanup_orphan_occurrences(), '동시 정리는 건너뜀');
    check(microtime(true)-$started<1, '정리 잠금은 5초 대기하지 않음');
} finally { $other->query("SELECT RELEASE_LOCK('{$lock}')"); }
check(guardian_cleanup_orphan_occurrences(), '동시 정리 잠금 해제 후 회복');
echo "PASS performance: {$checks}\n";
