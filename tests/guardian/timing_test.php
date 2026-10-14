<?php
// 실제 Guardian 함수를 쓰되 DB/외부 발송은 대체한다. 각 요청은 별도 PHP 프로세스.
if (PHP_SAPI !== 'cli') exit;
$root = getenv('GUARDIAN_TEST_ROOT') ?: dirname(__DIR__, 2);
$mode = isset($argv[1]) ? $argv[1] : '';
if ($mode !== '') {
    define('_GNUBOARD_', true);
    $config = array('cf_guardian_use'=>$mode==='disabled' ? 0 : 1, 'cf_guardian_email_daily_limit'=>100);
    $g5 = array('guardian_log_table'=>'fixture_log', 'guardian_occurrence_table'=>'fixture_occurrence',
        'guardian_notify_log_table'=>'fixture_notify', 'guardian_recipient_table'=>'fixture_recipient');
    $queryCount=0; $lastId=0; $logId=0; $sendCalls=0;
    $phpLog=tempnam(sys_get_temp_dir(),'guardian-timing-');
    ini_set('error_log',$phpLog);
    $_SERVER['REQUEST_TIME_FLOAT']=microtime(true)-0.05;
    if ($mode==='fallback') unset($_SERVER['REQUEST_TIME_FLOAT']);
    function sql_escape_string($v) { return addslashes($v); }
    function sql_query($sql,$error=true) {
        global $queryCount,$lastId,$logId;
        $queryCount++;
        if (strpos($sql,'INSERT INTO')!==false) {
            $lastId++;
            if (strpos($sql,'`fixture_log`')!==false) $logId=$lastId;
        }
        return true;
    }
    function sql_fetch($sql,$error=true) {
        global $queryCount,$mode,$logId,$sendCalls;
        $queryCount++;
        if (strpos($sql,'DATABASE()')!==false) return array('db_name'=>'timing_fixture');
        if (strpos($sql,'GET_LOCK(')!==false) {
            $notify='gbg:'.substr(hash('sha256',serialize(array('timing_fixture','fixture_log','notify_reservation',array()))),0,60);
            $isNotify=strpos($sql,$notify)!==false;
            usleep($isNotify ? 30000 : 20000);
            return array('acquired'=>$mode==='lock_fail' ? 0 : 1);
        }
        if (strpos($sql,'SELECT log_id')!==false) return $logId ? array('log_id'=>$logId) : array();
        if (strpos($sql,'SELECT recipient_id')!==false) return array('recipient_id'=>1,'active'=>1,'email'=>'fixture@example.invalid');
        if (strpos($sql,'COUNT(*)')!==false) return array('cnt'=>0);
        if (strpos($sql,'SELECT notify_id')!==false) return $mode==='cooldown' || $sendCalls>0 ? array('notify_id'=>1) : array();
        return array();
    }
    function sql_insert_id() { global $lastId; return $lastId; }
    function sql_free_result($r) {}
    function guardian_render_template($channel,array $data) { return array('subject'=>'fixture','body'=>'fixture'); }
    function guardian_send_email($address,$subject,$body) {
        global $mode,$sendCalls;
        $sendCalls++; usleep(40000);
        if ($mode==='send_exception') throw new RuntimeException('fixture send failed');
        if ($mode==='send_engine') throw new TypeError('fixture send engine failed');
        return array('success'=>true,'reason'=>'');
    }
    function run_event($event,$data=null) {
        if ($event==='guardian_after_capture') guardian_notify($data,'email',1);
    }
    $lib=$root.'/src/plugin/gb_guardian/lib';
    require $lib.'/guardian_timing.lib.php';
    require $lib.'/guardian_report.lib.php';
    require $lib.'/guardian_db.lib.php';
    require $lib.'/guardian.lib.php';
    require $lib.'/error_handler.lib.php';
    require $lib.'/guardian_protector.lib.php';
    require $lib.'/guardian_notifier.lib.php';
    ob_start();
    if ($mode==='normal') {
        guardian_db_release_named_lock(guardian_db_acquire_named_lock('capture'));
    } elseif ($mode==='native') {
        guardian_capture(array('level'=>'WARNING','message'=>'fixture','file'=>'/fixture/api.php'));
    } else {
        for ($i=0;$i<($mode==='multiple' ? 7 : 1);$i++) {
            guardian_report_error('service_no_response','body-secret',array('source'=>'n0','failure'=>'transport_error',
                'bo_table'=>'diagnostic_logs','wr_id'=>42+$i,'authorization'=>'auth-secret'),E_USER_WARNING,'/fixture/api.php',10);
        }
    }
    $output=ob_get_clean();
    usleep(10000); // 오류 처리 후 응답 조립 등 나머지 PHP 처리 시간.
    $emitResult=function () use ($phpLog,$queryCount,$sendCalls,$output) {
        $content=is_file($phpLog) ? file_get_contents($phpLog) : '';
        preg_match_all('/\[GB Guardian timing\] ([^\r\n]+)/',$content,$matches);
        $timing=count($matches[1]) ? json_decode($matches[1][0],true) : null;
        @unlink($phpLog);
        echo json_encode(array('timing'=>$timing,'lines'=>count($matches[1]),'queries'=>$queryCount,
            'send_calls'=>$sendCalls,'output'=>$output)),"\n";
    };
    if ($mode==='automatic') {
        // 실제 종료 콜백 순서에서 시각 표시와 로그 기록이 실행되는지 확인한다.
        register_shutdown_function($emitResult);
        exit;
    }
    guardian_timing_mark_shutdown();
    usleep(30000); // 방문자 정리 등 종료 후 작업을 별도로 구분해야 한다.
    guardian_timing_flush();
    guardian_timing_flush(); // 중복 기록 방지.
    $emitResult();
    exit;
}
$checks=0;
function check($ok,$message) { global $checks; $checks++; if (!$ok) throw new RuntimeException($message); }
foreach (array('normal','send','cooldown','lock_fail','send_exception','send_engine','disabled','fallback','multiple','native','automatic') as $case) {
    $p=proc_open(array(PHP_BINARY,__FILE__,$case),array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    if (!is_resource($p)) throw new RuntimeException('worker spawn failed');
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);
    fclose($pipes[1]);fclose($pipes[2]);$rc=proc_close($p);
    $result=json_decode($out,true);
    check($rc===0 && $err==='' && is_array($result),$case.': 실제 함수 실행 정상 '.$err);
    check($result['output']==='',$case.': 응답 출력 없음');
    if ($case==='normal') {
        check($result['lines']===0 && $result['timing']===null,'정상 요청은 시간 로그 없음');
        continue;
    }
    $t=$result['timing'];
    check($result['lines']===1 && is_array($t),$case.': 요청당 한 줄만 기록');
    check($t['error_count']===($case==='multiple' ? 7 : 1),$case.': report/capture 이중 집계 없음');
    check(count($t['references'])===($case==='multiple' ? 5 : 1),$case.': 참조 최대 5건');
    check($t['request_elapsed_ms']+0.01 >= $t['report_ms'] && $t['request_elapsed_ms']+0.01 >= $t['capture_ms'],$case.': 전체 시간에 오류 처리 포함');
    check($t['shutdown_before_log_ms']>=($case==='automatic' ? 0 : 25)
        && $t['end_point']==='shutdown_begin',$case.': 종료 후 작업 시간 분리');
    check($t['start_source']===($case==='fallback' ? 'first_error' : 'REQUEST_TIME_FLOAT'),$case.': 시작 기준 명시');
    check(strpos(json_encode($t),'body-secret')===false && strpos(json_encode($t),'auth-secret')===false,$case.': 본문/인증 정보 제외');
    if ($case==='disabled') {
        check($result['queries']===0 && $t['capture_ms']===0 && $t['notify_send_attempts']===0,'비활성은 추가 DB/발송 없음');
    } else {
        check($t['group_lock_ms']>=15,$case.': 그룹 잠금 쿼리 시간 기록');
        if ($case==='lock_fail') {
            check($t['notify_lock_ms']===0 && $t['notify_send_attempts']===0,'잠금 실패도 기록하고 발송하지 않음');
        } else {
            check($t['notify_lock_ms']>=25,$case.': 예약 잠금 시간 기록');
            $attempts=$case==='cooldown' ? 0 : 1;
            check($t['notify_send_attempts']===$attempts && $result['send_calls']===$attempts,$case.': 실제 어댑터 호출만 집계');
            check($attempts ? $t['notify_send_ms']>=35 : $t['notify_send_ms']===0,$case.': 발송/예외/쿨다운 시간 구분');
        }
    }
}
echo "PASS timing: {$checks} (DB/외부 서비스 대체)\n";
