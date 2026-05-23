<?php
/**
 * 그누보드 운영지킴이 - 디스패처
 *
 * run_event('guardian_after_capture', $data) 훅과 매칭 엔진을
 * 잇는 얇은 진입점. 활성화 / 비상정지 1차 체크만 수행하고 매칭 엔진에 위임.
 *
 * 또한 방문자 트리거(cf_guardian_summary_mode='visitor') 모드의 shutdown
 * 콜백 함수 guardian_visitor_trigger_summary() 도 본 파일에 둔다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * guardian_after_capture 훅 콜백.
 *
 * 절대 사이트를 죽이지 않는다 — 모든 예외를 흡수.
 *
 * @param  array $error_data 캡처 데이터
 * @return void
 */
function guardian_dispatch_after_capture($error_data)
{
    global $config;

    // 비활성 / 비상 정지 시 즉시 종료 — 매칭 엔진 호출 없음
    if (empty($config['cf_guardian_use'])) return;
    if (!empty($config['cf_guardian_emergency_stop'])) return;

    // 매칭 엔진 호출
    if (!is_array($error_data)) return;

    try {
        if (function_exists('guardian_dispatch_error')) {
            guardian_dispatch_error($error_data);
        }
    } catch (Exception $e) {
        // 디스패처 자체 오류는 조용히 무시 — 사이트 보호 최우선
    }
}

/**
 * 방문자 트리거 — shutdown 콜백.
 *
 * extend.php 가 1% 확률로 register_shutdown_function 등록해 둔 함수.
 * 사용자 응답이 끝난 뒤 백그라운드처럼 실행된다.
 *
 * @return void
 */
function guardian_visitor_trigger_summary()
{
    // 사용자 응답을 즉시 끊어 백그라운드처럼 동작
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
    } else {
        // 전통 환경 폴백
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();
    }

    // 백그라운드에서 무거워질 수 있으므로 시간/abort 보호
    @ignore_user_abort(true);
    @set_time_limit(300);

    try {
        if (function_exists('guardian_process_daily_summaries')) {
            guardian_process_daily_summaries();
        }
        if (function_exists('guardian_process_weekly_summaries')) {
            guardian_process_weekly_summaries();
        }
        if (function_exists('guardian_cleanup_old_data')) {
            guardian_cleanup_old_data();
        }
    } catch (Exception $e) {
        // 조용히 무시 — 백그라운드라 사용자 영향 없음
    }
}
