<?php
/**
 * 그누보드 운영지킴이 - 오류 핸들러 라이브러리
 *
 * 책임: 4가지 핸들러 + 통합 캡처 함수
 *   - guardian_on_error()      : E_NOTICE / E_WARNING / E_DEPRECATED 등
 *   - guardian_on_exception()  : 처리되지 않은 예외
 *   - guardian_on_shutdown()   : Fatal Error (E_ERROR 계열)
 *   - guardian_on_sql_error()  : DB 쿼리 오류
 *   - guardian_capture()       : 위 4 핸들러가 공통으로 호출하는 통합 진입점
 *
 * 절대 원칙:
 *   - 모든 핸들러는 try-catch 로 보호되어 핸들러 자체 오류로 사이트가 죽지 않는다.
 *   - 자기 참조(gb_guardian 자체에서 발생한 오류)는 무시 — 무한 루프 차단.
 *   - guardian_capture() 는 정적 재진입 가드를 둬서 어떤 경우에도 재귀 안 됨.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

// =====================================================================
// 핸들러 1 — Warning / Notice / Deprecated 등 일반 PHP 에러
// =====================================================================
/**
 * PHP 에러 핸들러 (set_error_handler 콜백).
 *
 * 동작:
 *   1. @ 연산자로 억제된 오류는 무시 (사용자 의도 존중).
 *   2. 수집 대상 등급(cf_guardian_collect_levels) 외 등급은 무시.
 *   3. 자기 참조 오류는 무시 (무한 루프 차단).
 *   4. 캡처 후 기존(체이닝) 핸들러로 위임 — 기존 동작 유지.
 *
 * @param  int    $errno   PHP 에러 상수 (E_WARNING 등)
 * @param  string $errstr  에러 메시지
 * @param  string $errfile 발생 파일 절대경로
 * @param  int    $errline 발생 라인
 * @return mixed           기존 핸들러 결과 또는 false (PHP 기본 폴백)
 */
function guardian_on_error($errno, $errstr, $errfile, $errline)
{
    // 1. @ 억제 존중 (3중 검사)
    //
    // 기존 (error_reporting() & $errno) 비트 AND 검사는 php.ini 의
    // error_reporting 비트가 꺼진 등급(PHP 기본 E_DEPRECATED, 일부 호스팅의
    // E_NOTICE) 을 운영지킴이가 잡지 못하게 하던 부작용이 있었다. 운영지킴이는
    // 자체 수집 등급 정책(cf_guardian_collect_levels) 을 가지므로 ini 비활성
    // 등급이라도 잡아야 한다.
    //
    // 한편 단순 `error_reporting() === 0` 검사만으로는 일부 PHP 8 환경 또는
    // 그누보드 / 다른 미들웨어가 일시적으로 error_reporting 을 변경한 경우
    // @ 억제를 놓칠 수 있다. 다음 3가지 검사를 단계적으로 적용한다:
    //
    //   1) error_reporting() === 0
    //      PHP 5/6/7 표준. PHP 8.x 도 비fatal 오류는 동일하게 0 설정.
    //   2) error_reporting() 이 PHP 8 의 fatal-only 마스크와 일치
    //      (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR |
    //       E_RECOVERABLE_ERROR | E_PARSE)
    //      일부 PHP 8 환경에서 이 값으로 설정되는 케이스.
    //   3) 폴백 검사 — ini 의 error_reporting 보다 현재 값이 작고
    //      `$errno` 비트가 현재 값에 없음 → @ 또는 동등한 일시 변경
    //      그누보드/미들웨어가 어떻게 변경하든 잡아낸다.
    $er_now = error_reporting();
    $errno_int = (int)$errno;

    // 검사 1
    if ($er_now === 0) {
        return guardian_chain_error_handler($errno, $errstr, $errfile, $errline);
    }
    // 검사 2 (PHP 8)
    if (PHP_MAJOR_VERSION >= 8) {
        $fatal_only = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR
                    | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;
        if ($er_now === $fatal_only) {
            return guardian_chain_error_handler($errno, $errstr, $errfile, $errline);
        }
    }
    // 검사 3 (폴백): ini 보다 현재 값이 줄어들었고 $errno 비트가 빠져있다면 @ 억제
    $er_ini = function_exists('ini_get') ? (int)ini_get('error_reporting') : 0;
    if ($er_ini > 0 && $er_now < $er_ini && !($er_now & $errno_int)) {
        return guardian_chain_error_handler($errno, $errstr, $errfile, $errline);
    }

    // 2. 등급 필터 — 운영지킴이 자체 수집 등급 정책
    if (!guardian_should_collect($errno)) {
        return guardian_chain_error_handler($errno, $errstr, $errfile, $errline);
    }
    // 3. 자기 참조 차단
    if (strpos((string)$errfile, 'gb_guardian') !== false) {
        return guardian_chain_error_handler($errno, $errstr, $errfile, $errline);
    }
    // 4. 캡처 (실패해도 사이트 동작에 영향 없도록 try-catch)
    try {
        guardian_capture(array(
            'level'   => guardian_errno_to_level($errno),
            'message' => (string)$errstr,
            'file'    => (string)$errfile,
            'line'    => (int)$errline,
            'trace'   => guardian_get_trace(2),
        ));
    } catch (Exception $e) {
        // 의도적 무시 — PHP 5.6 호환 위해 Throwable 미사용
    }

    return guardian_chain_error_handler($errno, $errstr, $errfile, $errline);
}

// =====================================================================
// 핸들러 2 — 처리되지 않은 Exception
// =====================================================================
/**
 * 미처리 예외 핸들러 (set_exception_handler 콜백).
 *
 * 시그니처는 mixed 로 두지만 실제로는 PHP 5.6: Exception, PHP 7+: Throwable.
 *
 * @param  mixed $exception Exception 또는 Throwable
 * @return void
 */
function guardian_on_exception($exception)
{
    if (!is_object($exception)) {
        // 비정상 호출 — 그냥 종료
        return;
    }

    $file = method_exists($exception, 'getFile')    ? $exception->getFile()    : '';
    $line = method_exists($exception, 'getLine')    ? (int)$exception->getLine() : 0;
    $msg  = method_exists($exception, 'getMessage') ? $exception->getMessage() : '';
    $trace= method_exists($exception, 'getTraceAsString') ? $exception->getTraceAsString() : '';

    // 자기 참조 차단
    if (strpos((string)$file, 'gb_guardian') === false) {
        try {
            guardian_capture(array(
                'level'   => 'EXCEPTION',
                'message' => get_class($exception) . ': ' . (string)$msg,
                'file'    => (string)$file,
                'line'    => $line,
                'trace'   => (string)$trace,
            ));
        } catch (Exception $e) {
            // 의도적 무시
        }
    }

    // 기존 핸들러 체이닝
    if (!empty($GLOBALS['guardian_prev_exception_handler'])
        && is_callable($GLOBALS['guardian_prev_exception_handler'])) {
        try {
            call_user_func($GLOBALS['guardian_prev_exception_handler'], $exception);
        } catch (Exception $e) {
            // 의도적 무시
        }
    }
}

// =====================================================================
// 핸들러 3 — Fatal Error (shutdown 함수)
// =====================================================================
/**
 * shutdown 함수 — Fatal Error 만 캡처한다.
 *
 * 중요 제약:
 *   - Out Of Memory 로 죽었다면 DB 연결도 못 할 수 있다.
 *   - shutdown 시점엔 debug_backtrace 가 비어 있을 수 있다.
 *   - 따라서 무거운 작업 절대 금지, try-catch 로 조용히 끝낸다.
 *
 * @return void
 */
function guardian_on_shutdown()
{
    $error = error_get_last();
    if (!$error || !isset($error['type'])) {
        return;
    }

    static $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($error['type'], $fatal_types, true)) {
        return;
    }

    // 자기 참조 차단
    $file = isset($error['file']) ? (string)$error['file'] : '';
    if (strpos($file, 'gb_guardian') !== false) {
        return;
    }

    try {
        guardian_capture(array(
            'level'   => 'FATAL',
            'message' => isset($error['message']) ? (string)$error['message'] : '',
            'file'    => $file,
            'line'    => isset($error['line']) ? (int)$error['line'] : 0,
            'trace'   => '',
        ));
    } catch (Exception $e) {
        // 의도적 무시
    }
}

// =====================================================================
// 핸들러 4 — SQL 오류 (그누보드 sql_error 이벤트 훅)
// =====================================================================
/**
 * 그누보드 SQL 오류 훅. 그누보드 코어가 sql_error 이벤트를 트리거할 때만
 * 호출된다 (구버전에선 트리거되지 않을 수 있음 — 정상).
 *
 * @param  string $sql       문제의 SQL
 * @param  string $error_msg DB 에러 메시지
 * @return void
 */
function guardian_on_sql_error($sql, $error_msg)
{
    try {
        // sql_query 내부 프레임을 건너뛰기 위해 skip 을 더 둔다
        $trace = guardian_get_trace(3);

        guardian_capture(array(
            'level'   => 'DB',
            'message' => (string)$error_msg,
            'file'    => 'DB Query',
            'line'    => 0,
            'trace'   => $trace . "\n\nSQL:\n" . guardian_mask_pii((string)$sql),
        ));
    } catch (Exception $e) {
        // 의도적 무시
    }
}

// =====================================================================
// 통합 캡처 함수
// =====================================================================
/**
 * 4가지 핸들러가 공통으로 호출하는 캡처 진입점.
 *
 * 단계:
 *   1. 재진입 가드 — guardian_capture() 안에서 발생한 오류는 무시
 *   2. 컨텍스트 머지 (URL / IP / user_id 자동 채움)
 *   3. 마스킹 적용
 *   4. 길이 컷
 *   5. error_hash 생성
 *   6. 디바운싱 체크 → 디바운싱 중이면 카운트만 +1
 *   7. 그렇지 않으면 INSERT
 *   8. run_event('guardian_after_capture', $data) — 알림 트리거 자리
 *
 * @param  array $data 핸들러가 채워서 보낸 부분 데이터
 * @return int 저장한 오류 그룹 ID. 그룹 또는 발생 상세 기록에 실패했으면 0.
 */
function guardian_capture(array $data)
{
    // ---- 1. 재진입 가드 ----
    // guardian_capture 안에서 발생한 오류가 다시 핸들러를 호출해
    // guardian_capture 로 돌아오는 무한 루프를 차단한다.
    static $in_progress = false;
    if ($in_progress) {
        return 0;
    }
    $in_progress = true;
    $capture_started = guardian_timing_start();
    $log_id = 0;
    $group_lock = '';
    $occurrence_saved = true;

    try {
        // ---- 2. 데이터 정규화 ----
        $defaults = array(
            'level'          => 'NOTICE',
            'message'        => '',
            'file'           => '',
            'line'           => 0,
            'trace'          => '',
            'request_url'    => guardian_get_request_url(),
            'request_method' => isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'CLI',
            'user_id'        => guardian_get_current_user(),
            'user_ip'        => guardian_get_user_ip(),
        );
        $data = array_merge($defaults, $data);

        // 공용 API의 구조화 데이터만 발생별 게시판 참조로 사용한다.
        $occurrence = null;
        if (!empty($data['error_code']) && isset($data['context']) && is_array($data['context'])) {
            $context = guardian_report_context($data['context']);
            $data['context'] = $context;
            $data['message'] = '[' . $data['error_code'] . '] ' . $data['message']
                . ' source=' . $context['source'] . ' failure=' . $context['failure'];
            if ($context['wr_id'] > 0 && $context['bo_table'] !== '') {
                if (guardian_db_occurrence_ready()) {
                    $occurrence = $context;
                } else {
                    // 설치 전에는 기존 방식처럼 모든 wr_id를 그룹 메시지에도 보존.
                    $data['message'] .= ' wr_id=' . $context['wr_id'] . ' bo_table=' . $context['bo_table'];
                    unset($data['error_code']);
                    $occurrence_saved = false;
                }
            }
        }

        if (empty($data['timing_reported'])) {
            guardian_timing_note_error(isset($data['error_code']) ? $data['error_code'] : 'php_error',
                isset($data['context']) && is_array($data['context']) ? $data['context'] : array());
        }
        // ---- 3. 마스킹 ----
        $data['message'] = guardian_mask_pii((string)$data['message']);
        $data['trace']   = guardian_mask_pii((string)$data['trace']);
        $data['trace']   = guardian_mask_path($data['trace']);
        $data['file']    = guardian_mask_path((string)$data['file']);

        // ---- 4. 길이 컷 (DB 컬럼 보호) ----
        $data['message']     = guardian_truncate($data['message'],     65000);
        $data['trace']       = guardian_truncate($data['trace'],       16000000);
        $data['file']        = guardian_truncate($data['file'],        500);
        $data['request_url'] = guardian_truncate($data['request_url'], 500);

        // ---- 5. 해시 생성 (그룹화 키) ----
        //
        // 일반 PHP 오류: level + message + file (★ line 제외)
        // 구조화 보고: level + error_code + file + source + failure
        //
        // line 을 해시에 포함하면 같은 변수가 인접한 4개 라인에서 사용된 경우
        // (Undefined variable $X 가 line 162, 163, 164, 167) 4건이 각각
        // 별개 오류로 처리되어 4건의 알림이 폭주한다. 같은 파일의 같은
        // 메시지는 거의 항상 같은 근본 원인이므로 한 건으로 묶어 cooldown
        // 디바운싱이 정상 동작하도록 한다 (★ SMS 비용 폭탄 방지).
        //
        // line 값 자체는 캡처된 첫 행에 그대로 저장되며, occurrence_count
        // 만 누적 증가한다. 관리자는 admin 로그 화면에서 해당 행을 통해
        // 첫 발생 라인을 확인할 수 있다.
        // 명시적 식별자가 있으면 설명 문구는 해시에서 제외한다.
        $group_key = !empty($data['error_code'])
            ? json_encode(array($data['level'], $data['error_code'], $data['file'],
                $data['context']['source'], $data['context']['failure']))
            : $data['level'] . '|' . $data['message'] . '|' . $data['file'];
        $data['error_hash'] = hash('sha256', $group_key);

        // 서로 다른 PHP 요청도 같은 해시의 조회/생성/횟수/상세 기록을 직렬화한다.
        // 잠금을 얻지 못하면 보호 없이 기록하거나 알림을 보내지 않는다.
        $group_lock = guardian_db_acquire_named_lock('capture', array($data['error_hash']));
        if ($group_lock === '') {
            @error_log('[GB Guardian] 오류 그룹 잠금 획득 실패 hash=' . $data['error_hash']);
            return 0;
        }

        // ---- 6/7. 디바운싱 또는 INSERT ----
        // INSERT 시 새 log_id 를, 디바운싱 시 기존 최신 log_id 를 받아
        // $data 에 채운다. 이렇게 해야 디스패처의 매칭 추적 로그 /
        // 요약 큐 / notified 플래그 갱신 등이 정확한 log_id 를 참조한다.
        $log_id = guardian_db_get_cooldown_log_id($data['error_hash']);
        if ($log_id > 0) {
            guardian_db_increment_count($data['error_hash'], $log_id);
        } else {
            $log_id = (int)guardian_db_insert($data);
        }
        $data['log_id'] = $log_id;
        if ($occurrence !== null && $log_id > 0) {
            $occurrence_saved = guardian_db_insert_occurrence($log_id, $occurrence);
            if (!$occurrence_saved) {
                // 그룹과 알림은 유지하고, 저장하지 못한 참조를 PHP 로그에 명시한다.
                @error_log('[GB Guardian] 발생 상세 저장 실패 log_id=' . $log_id
                    . ' context=' . json_encode(guardian_report_context($occurrence)));
            }
        }
        guardian_db_release_named_lock($group_lock);
        $group_lock = '';
        // DB 저장 실패는 PHP 로그에만 남긴다. 잘못된 그룹 ID로 발송하지 않는다.
        if ($log_id <= 0) return 0;

        // ---- 8. 외부 확장점 (알림 규칙 매칭) ----
        // 그누보드 5.6.x 의 훅 트리거 함수는 run_event() 다 (do_action 은 워드프레스).
        // run_event 는 hook.lib.php 가 로드된 후 항상 존재한다 — function_exists 가드는
        // 안전상 유지.
        if (function_exists('run_event')) {
            run_event('guardian_after_capture', $data);
        }
    } catch (Exception $e) {
        // 의도적 무시 — 캡처 도중 오류는 사이트에 영향 주지 않는다
    } catch (Throwable $e) {
        // PHP 7+ 엔진 오류에서도 재진입 가드를 해제한다.
    } finally {
        guardian_db_release_named_lock($group_lock);
        $in_progress = false;
        guardian_timing_add('capture_ms', $capture_started);
    }
    return $occurrence_saved ? $log_id : 0;
}
