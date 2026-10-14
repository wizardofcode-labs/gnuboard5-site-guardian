<?php
// php tests/guardian/concurrency_test.php — 전용 임시 MariaDB 소켓에서 실행.
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/test_bootstrap.php';
$root = getenv('GUARDIAN_TEST_ROOT') ?: dirname(__DIR__, 2);
$setup = new PDO('mysql:unix_socket=' . guardian_test_socket() . ';charset=utf8mb4', 'root', '', array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));
$setup->exec('CREATE DATABASE IF NOT EXISTS guardian_concurrency_fixture CHARACTER SET utf8mb4');
require __DIR__ . '/concurrency_fixture.php';
$checks = 0;
function check($ok, $message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
$ddl = file_get_contents($root . '/src/plugin/gb_guardian/install.php');
$charset='utf8mb4'; $tbl_log='fixture_log'; $tbl_occurrence='fixture_occurrence';
$tbl_notify_log='fixture_notify'; $tbl_rule='fixture_rule'; $tbl_recipient='fixture_recipient';
foreach (array('log','occurrence','notify_log','rule','recipient') as $kind) {
    $start=strpos($ddl, '$sql_' . $kind . ' =');
    eval(substr($ddl, $start, strpos($ddl, '";', $start) + 2 - $start));
    $pdo->exec(${'sql_'.$kind});
}
// install.php의 별도 재실행 안전 마이그레이션도 fixture에 반영한다.
if (!$pdo->query("SHOW COLUMNS FROM fixture_rule LIKE 'priority'")->fetch()) $pdo->exec('ALTER TABLE fixture_rule ADD priority INT(11) NOT NULL DEFAULT 50 AFTER mode');
if (!$pdo->query("SHOW COLUMNS FROM fixture_rule LIKE 'dedup_scope'")->fetch()) $pdo->exec("ALTER TABLE fixture_rule ADD dedup_scope VARCHAR(20) NOT NULL DEFAULT 'rule'");
$msg=array();
$probeStart=strpos($ddl,'$lock_probe =');
if ($probeStart !== false) {
    $probeEnd=strpos($ddl,'$msg[] = "mbstring', $probeStart);
    eval(substr($ddl,$probeStart,$probeEnd-$probeStart));
    check(count($msg)===1 && strpos($msg[0],'[OK]')===0, 'install.php DB 잠금 환경 점검 성공');
    check($pdo->query("SELECT IS_USED_LOCK('{$lock_probe}')")->fetchColumn()===null, '설치 점검 잠금 해제');
}
$pdo->exec('CREATE TABLE IF NOT EXISTS fixture_deliveries (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, channel VARCHAR(20), recipient VARCHAR(200), reservation_lock_free INT)');
function reset_fixture($channels = array('email'), $scope = 'rule', $recipients = '1') {
    global $pdo;
    foreach (array('fixture_occurrence','fixture_log','fixture_notify','fixture_rule','fixture_recipient','fixture_deliveries') as $table) $pdo->exec('DELETE FROM ' . $table);
    $pdo->exec("INSERT INTO fixture_recipient (recipient_id,name,email,mobile,active,created_dt) VALUES
        (1,'first','one@example.invalid','01000000001',1,NOW()),
        (2,'second','two@example.invalid','01000000002',1,NOW()),
        (3,'same address','one@example.invalid','01000000003',1,NOW())");
    foreach ($channels as $i=>$channel) {
        $stmt=$pdo->prepare("INSERT INTO fixture_rule (rule_id,rule_name,rule_active,error_levels,mode,channel,recipient_ids,cooldown_min,dedup_scope,created_dt) VALUES (?,'fixture',1,'WARNING','instant',?,?,30,?,NOW())");
        $stmt->execute(array($i+1,$channel,$recipients,$scope));
    }
}
function count_rows($table, $where = '1=1') { global $pdo; return (int)$pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $where)->fetchColumn(); }
function parallel_reports($cases, $fail = '') {
    $gate=sys_get_temp_dir() . '/guardian-parallel-' . bin2hex(random_bytes(6)); mkdir($gate);
    $children=array(); $results=array();
    try {
        foreach ($cases as $i=>$case) {
            $id=$i+1;
            $p=proc_open(array(PHP_BINARY,__DIR__.'/concurrency_fixture.php','worker',$case,(string)$id,$gate,$fail),
                array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
            if (!is_resource($p)) throw new RuntimeException('worker spawn failed');
            fclose($pipes[0]); $children[]=array($p,$pipes);
        }
        $deadline=microtime(true)+10;
        do {
            $ready=true;
            foreach ($cases as $i=>$case) if (!file_exists($gate.'/ready-'.($i+1))) $ready=false;
            if (!$ready) usleep(1000);
            if (microtime(true)>$deadline) throw new RuntimeException('parent gate timeout');
        } while (!$ready);
        file_put_contents($gate.'/go','go');
        foreach ($children as $child) {
            $out=stream_get_contents($child[1][1]); $err=stream_get_contents($child[1][2]);
            fclose($child[1][1]); fclose($child[1][2]);
            $rc=proc_close($child[0]);
            check($rc===0, 'worker execution: '.$err);
            $data=json_decode(trim($out),true); check(is_array($data), 'worker JSON response');
            $results[]=$data;
        }
        $children=array();
    } finally {
        foreach ($children as $child) { if (is_resource($child[0])) { proc_terminate($child[0]); proc_close($child[0]); } }
        foreach (glob($gate.'/*') as $p) unlink($p); rmdir($gate);
    }
    return $results;
}
foreach (array('new','existing','expired') as $case) {
    reset_fixture();
    if ($case !== 'new') {
        $id=guardian_db_insert(array('level'=>'WARNING','error_hash'=>fixture_hash($case),'message'=>'fixture','file'=>'/fixture/parallel.php'));
        if ($case === 'expired') {
            $pdo->exec('UPDATE fixture_log SET created_dt=DATE_SUB(NOW(),INTERVAL 31 MINUTE)');
            guardian_db_insert_occurrence($id,array('wr_id'=>777,'bo_table'=>'diagnostic_logs','source'=>'gateway','failure'=>'transport_error'));
            guardian_log_notify_result(fixture_hash($case),'email','one@example.invalid','success');
            $pdo->exec('UPDATE fixture_notify SET sent_dt=DATE_SUB(NOW(),INTERVAL 31 MINUTE)');
        }
    }
    $results=parallel_reports(array_fill(0,8,$case));
    check(count(array_filter(array_column($results,'recorded')))===8, $case.': 8개 요청 모두 기록');
    check(count_rows('fixture_log')===($case==='expired'?2:1), $case.': 그룹 중복 없음');
    $latest=$pdo->query('SELECT log_id,occurrence_count FROM fixture_log ORDER BY log_id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    check((int)$latest['occurrence_count']===($case==='existing'?9:8), $case.': 모든 횟수 보존');
    check(count_rows('fixture_occurrence','log_id='.(int)$latest['log_id'])===8, $case.': 발생 상세 8개');
    check(count_rows('fixture_deliveries')===1, $case.': 실제 발송 경로 1회');
    check(count_rows('fixture_notify',"status='success'")===($case==='expired'?2:1), $case.': 예약 행이 성공으로 갱신');
    check(count_rows('fixture_deliveries','reservation_lock_free<>1')===0, $case.': 외부 발송 시 DB 잠금 없음');
    if ($case==='expired') check(count_rows('fixture_occurrence','wr_id=777')===1, '이전 구간 상세 보존');
}
reset_fixture(); parallel_reports(array('different_a','different_b'));
check(count_rows('fixture_log')===2 && count_rows('fixture_deliveries')===2, '다른 오류는 각각 그룹/발송');
reset_fixture(); parallel_reports(array('limit_a','limit_b'),'daily_limit');
check(count_rows('fixture_log')===2 && count_rows('fixture_deliveries')===1 && count_rows('fixture_notify')===1, '동시 다른 오류도 일일 한도 1건을 초과하지 않음');
reset_fixture(array('email','sms','kakao')); parallel_reports(array_fill(0,8,'channels'));
check(count_rows('fixture_deliveries')===3 && count_rows('fixture_notify',"status='success'")===3, '규칙별 범위: 채널별 1회');
reset_fixture(array('email','sms','kakao'),'global'); parallel_reports(array_fill(0,8,'global'));
check(count_rows('fixture_deliveries')===1, '전역 범위: 다른 채널/규칙도 예약을 공유');
reset_fixture(array('email'),'rule','1,2'); parallel_reports(array_fill(0,4,'recipients'));
check(count_rows('fixture_deliveries')===2, '다른 수신자는 각각 발송');
reset_fixture(array('email'),'rule','1,3'); parallel_reports(array_fill(0,4,'same_address'));
check(count_rows('fixture_deliveries')===1, '다른 수신자 ID의 동일 주소 중복 방지');

reset_fixture(); parallel_reports(array('retry'),'route'); parallel_reports(array('retry'));
check(count_rows('fixture_deliveries')===2 && count_rows('fixture_notify',"status='failed'")===1 && count_rows('fixture_notify',"status='success'")===1, '확정 실패 후 재시도 허용');
reset_fixture(); parallel_reports(array('hook'),'hook'); parallel_reports(array('hook'));
check(count_rows('fixture_deliveries')===1 && count_rows('fixture_notify',"status='failed'")===1, '발송 전 훅 예외 시 예약/락 정리');
reset_fixture(); parallel_reports(array('reserve'),'reserve');
check(count_rows('fixture_deliveries')===0 && count_rows('fixture_notify')===0, '예약 INSERT 실패 시 발송 금지');
reset_fixture(); $result=parallel_reports(array('notify_lock'),'notify_lock');
check($result[0]['recorded'] && count_rows('fixture_occurrence')===1 && count_rows('fixture_deliveries')===0, '발송 예약 잠금 실패 시 그룹 상세 유지/발송 금지');
reset_fixture(); parallel_reports(array('finalize'),'finalize'); parallel_reports(array('finalize'));
check(count_rows('fixture_deliveries')===1 && count_rows('fixture_notify',"status='pending'")===1, '결과 기록 실패 시 예약 유지/재발송 억제');
$pdo->exec('UPDATE fixture_notify SET sent_dt=DATE_SUB(NOW(),INTERVAL 11 MINUTE)');
parallel_reports(array('finalize'));
check(count_rows('fixture_notify',"status='unknown'")===1 && count_rows('fixture_notify',"status='success'")===1, '중단된 예약은 만료 후 결과 미확인으로 전환');
reset_fixture(); $result=parallel_reports(array('lock'),'lock');
check(!$result[0]['recorded'] && count_rows('fixture_log')===0 && count_rows('fixture_deliveries')===0, 'DB 잠금 실패 시 보호 없는 기록/발송 금지');
reset_fixture(); $result=parallel_reports(array('group'),'group');
check(!$result[0]['recorded'] && count_rows('fixture_occurrence')===0 && count_rows('fixture_deliveries')===0, '그룹 INSERT 실패 시 상세/발송 금지');
reset_fixture();
$held=guardian_db_acquire_named_lock('capture',array(fixture_hash('held')),0);
check($held !== '', '다른 DB 연결의 그룹 잠금 선점');
try {
    $result=parallel_reports(array('held'));
    check(!$result[0]['recorded'] && count_rows('fixture_log')===0, '실제 DB 잠금 5초 대기 만료 시 보호 없이 기록하지 않음');
} finally { guardian_db_release_named_lock($held); }
$result=parallel_reports(array('held'));
check($result[0]['recorded'] && count_rows('fixture_deliveries')===1, '잠금 해제 후 정상 캡처/발송 회복');
reset_fixture();
$unit=array('error_hash'=>fixture_hash('unit'),'level'=>'WARNING','message'=>'fixture');
$fixtureFailure='hook'; $first=guardian_notify($unit,'email',1);
$fixtureFailure=''; $second=guardian_notify($unit,'email',1);
check(!$first['success'] && $second['success'], '같은 PHP 요청에서 훅 예외 후 재진입 락 해제');
reset_fixture();
$fixtureFailure='engine_hook'; $first=guardian_notify($unit,'email',1);
$fixtureFailure=''; $second=guardian_notify($unit,'email',1);
check(!$first['success'] && $second['success'], '같은 PHP 요청에서 엔진 오류 후 재진입 락 해제');
reset_fixture();
guardian_log_notify_result(fixture_hash('zero'),'email','one@example.invalid','pending');
check(!guardian_check_notify_cooldown(fixture_hash('zero'),'email','one@example.invalid',0), '성공 쿨다운 0이어도 진행 중 예약은 보호');
$config['cf_guardian_email_daily_limit']=1;
check(!guardian_notify(array('error_hash'=>fixture_hash('limit'),'level'=>'WARNING','message'=>'fixture'),'email',2)['success'], 'pending 예약도 일일 한도에 포함');
reset_fixture();
guardian_log_notify_result(fixture_hash('delete'),'email','one@example.invalid','pending');
$pendingId=sql_insert_id();
guardian_log_notify_result(fixture_hash('delete'),'email','two@example.invalid','success');
$ids_str=$pendingId . ',' . sql_insert_id();
$action=file_get_contents($root . '/src/adm/guardian_notify_log_action.php');
$deleteStart=strpos($action,'@sql_query(',strpos($action,"if (\$act === 'delete')"));
$deleteSql=substr($action,$deleteStart,strpos($action,');',$deleteStart)+2-$deleteStart);
eval($deleteSql);
check(count_rows('fixture_notify',"status='pending'")===1 && count_rows('fixture_notify',"status='success'")===0, '관리자 삭제에서도 진행 중 예약 보호');
$pdo->exec('UPDATE fixture_notify SET sent_dt=DATE_SUB(NOW(),INTERVAL 11 MINUTE)');
eval($deleteSql);
check(count_rows('fixture_notify')===0, '만료된 예약은 관리자 삭제 가능');
guardian_log_notify_result(fixture_hash('view'),'email','one@example.invalid','pending');
$expiredId=sql_insert_id();
$pdo->exec('UPDATE fixture_notify SET sent_dt=DATE_SUB(NOW(),INTERVAL 11 MINUTE) WHERE notify_id=' . $expiredId);
guardian_log_notify_result(fixture_hash('view'),'email','two@example.invalid','pending');
$view=file_get_contents($root . '/src/adm/guardian_notify_log.php');
$whereStart=strpos($view,"\$where = array('1=1');");
$whereBlock=substr($view,$whereStart,strpos($view,'// ---- 카운트 + 데이터',$whereStart)-$whereStart);
$selectStart=strpos($view,'$result = @sql_query(');
$selectBlock=substr($view,$selectStart,strpos($view,');',$selectStart)+2-$selectStart);
$f_channel=''; $f_period=0; $f_stx=''; $f_sfl='recipient'; $from=0; $rows=30;
$f_status='pending'; eval($whereBlock); eval($selectBlock);
$visible=$result->fetchAll(PDO::FETCH_ASSOC);
check(count($visible)===1 && $visible[0]['display_status']==='pending', '관리자 예약 필터는 유효한 진행 중 예약만 표시');
$f_status='unknown'; eval($whereBlock); eval($selectBlock);
$visible=$result->fetchAll(PDO::FETCH_ASSOC);
check(count($visible)===1 && $visible[0]['display_status']==='unknown', '만료 예약은 다음 시도 전에도 관리자 화면에서 결과 미확인 표시');
// 보호 조회의 SQL 실패는 이력 없음과 구분하여 발송을 차단한다.
foreach (array('daily_read', 'cooldown_read', 'global_read') as $readFailure) {
    reset_fixture();
    $fixtureFailure = $readFailure;
    $options = array('dedup_scope' => $readFailure === 'global_read' ? 'global' : 'rule');
    $result = guardian_notify(array('error_hash' => fixture_hash($readFailure), 'level' => 'WARNING', 'message' => 'fixture'), 'email', 1, $options);
    check(!$result['success'] && count_rows('fixture_deliveries') === 0 && count_rows('fixture_notify') === 0, $readFailure . ': 보호 조회 실패 시 예약/외부 발송 금지');
    $fixtureFailure = '';
    $result = guardian_notify(array('error_hash' => fixture_hash($readFailure), 'level' => 'WARNING', 'message' => 'fixture'), 'email', 1, $options);
    check($result['success'] && count_rows('fixture_deliveries') === 1, $readFailure . ': DB 복구 후 락 해제 및 발송 회복');
}
echo "PASS concurrency: {$checks} checks (실제 PHP 다중 프로세스, 외부 발송 없음)\n";
