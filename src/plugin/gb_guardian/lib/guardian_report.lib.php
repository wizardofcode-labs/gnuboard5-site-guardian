<?php
/**
 * GB Guardian 공용 구조화 오류 보고 API.
 * 설치/활성화 전에도 호출 가능하며 외부 API나 PHP 출력은 발생시키지 않는다.
 * PHP 5.6+ 호환. error_log는 PHP 오류 핸들러를 호출하지 않으므로 이중 캡처가 없다.
 */
if (!defined('_GNUBOARD_')) exit;
require_once __DIR__ . '/guardian_timing.lib.php';

/** 허용된 짧은 분류값과 게시판 참조만 수집한다. 임의의 요청/인증 데이터는 복제하지 않는다. */
function guardian_report_context(array $context)
{
    $clean = array('source' => '', 'failure' => '', 'bo_table' => '', 'wr_id' => 0);
    foreach (array('source', 'failure') as $key) {
        if (isset($context[$key]) && is_scalar($context[$key])) {
            $clean[$key] = guardian_truncate(guardian_mask_pii((string)$context[$key]), 64);
        }
    }
    if (isset($context['bo_table']) && is_string($context['bo_table'])
        && preg_match('/^[A-Za-z0-9_]{1,50}$/D', $context['bo_table'])) {
        $clean['bo_table'] = $context['bo_table'];
    }
    if (isset($context['wr_id']) && is_scalar($context['wr_id'])
        && preg_match('/^[1-9][0-9]*$/D', (string)$context['wr_id'])) {
        $clean['wr_id'] = max(0, (int)$context['wr_id']);
    }
    return $clean;
}

/**
 * @param string $error_code 설명 문구와 독립된 고정 식별자 (예: order_processing_failed)
 * @param string $message 사람이 읽는 설명. 변경해도 그룹 키는 바뀌지 않는다.
 * @param array $context source/failure는 그룹 구분, bo_table/wr_id는 발생별 참조.
 * @param int $error_level E_USER_WARNING / E_USER_NOTICE / E_USER_DEPRECATED / E_USER_ERROR
 * @param string $file 호출부 __FILE__. 생략하면 호출 위치를 자동 확인한다.
 * @param int $line 호출부 __LINE__. 생략하면 호출 위치를 자동 확인한다.
 * @return bool 그룹과 요청된 상세를 기록했으면 true. 기록 실패/PHP 로그만 남긴 경우 false.
 * 상세 저장 실패 시에도 이미 기록한 그룹과 알림은 유지하고 실패 참조를 PHP 로그에 남긴다.
 * E_USER_ERROR도 보고 등급일 뿐 실행을 종료하지 않는다. 종료 여부는 호출자가 결정한다.
 */
function guardian_report_error($error_code, $message, array $context = array(), $error_level = E_USER_WARNING, $file = '', $line = 0)
{
    global $config;
    $timing_started = guardian_timing_start();
    try {
        // 함수 정의와 마스킹만 로드하며, 비활성 상태에서 DB 조회는 하지 않는다.
        require_once __DIR__ . '/guardian_db.lib.php';
        $levels = array(E_USER_WARNING => 'WARNING', E_USER_NOTICE => 'NOTICE',
            E_USER_DEPRECATED => 'DEPRECATED', E_USER_ERROR => 'ERROR');
        $error_level = (int)$error_level;
        $valid = is_string($error_code) && preg_match('/^[A-Za-z0-9_.:-]{1,100}$/D', $error_code)
            && isset($levels[$error_level]) && is_scalar($message);
        $code = $valid ? $error_code : 'invalid_report';
        $level = isset($levels[$error_level]) ? $levels[$error_level] : 'WARNING';
        $message = is_scalar($message) ? (string)$message : '오류 보고 인자가 올바르지 않습니다.';
        $context = guardian_report_context($context);
        guardian_timing_note_error($code, $context);
        if ($file === '' || (int)$line <= 0) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
            if ($file === '') $file = isset($caller[0]['file']) ? $caller[0]['file'] : '';
            if ((int)$line <= 0) $line = isset($caller[0]['line']) ? (int)$caller[0]['line'] : 0;
        }

        // 활성 여부/수집 필터/DB 성공 여부와 무관하게 PHP 로그에는 발생마다 한 번 기록.
        // trigger_error를 함께 호출하지 않는다. JSON 응답에 경고 출력도 섞이지 않는다.
        $log = '[GB Guardian][' . $level . '][' . $code . '] '
            . guardian_truncate($message, 2000) . ' context=' . json_encode($context)
            . ' in ' . guardian_mask_path(guardian_truncate((string)$file, 500)) . ':' . max(0, (int)$line);
        @error_log(str_replace(array("\r", "\n", "\0"), ' ', guardian_mask_pii($log)));
        if (!$valid || empty($config['cf_guardian_use']) || !function_exists('guardian_capture')
            || !function_exists('guardian_should_collect') || !guardian_should_collect($error_level)) return false;

        return guardian_capture(array(
            'level' => $level, 'error_code' => $code, 'message' => $message,
            'context' => $context, 'file' => (string)$file, 'line' => max(0, (int)$line),
            'trace' => function_exists('guardian_get_trace') ? guardian_get_trace(1) : '',
            'timing_reported' => true,
        )) > 0;
    } catch (Exception $e) {
        return false;
    } catch (Throwable $e) {
        // PHP 7+ 엔진 오류. PHP 5.6에서는 이 catch가 사용되지 않는다.
        return false;
    } finally {
        guardian_timing_add('report_ms', $timing_started);
    }
}
