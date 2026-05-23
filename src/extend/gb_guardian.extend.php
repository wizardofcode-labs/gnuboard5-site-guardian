<?php
/**
 * 그누보드 운영지킴이 (Gnuboard5 Site Guardian) - 진입점
 *
 * PHP 오류를 자동으로 감지해 DB 로그에 기록하는 플러그인.
 * 본 파일은 그누보드 코어가 extend 자동 로드 시 가장 먼저 호출하는 부트스트랩이다.
 *
 * 로드 시나리오 (3가지):
 *   A. install.php 미실행 — cf_guardian_use 컬럼 자체 없음 → 즉시 return.
 *   B. 활성 (cf_guardian_use=1) — 일반 페이지 / admin 모두 풀 로드.
 *   C. 비활성 (cf_guardian_use=0) — 일반 페이지: 0건 로드. admin: guardian.lib.php
 *      만 로드해 메뉴 등록 (사용자가 환경설정에서 활성화할 수 있도록).
 *
 * 안전 원칙:
 *   - 일반 사용자 페이지의 비활성 상태에서는 require_once 조차 실행하지 않는다.
 *   - admin 영역의 비활성 상태에서는 가장 가벼운 1개 lib 만 로드 (메뉴 표시 위해).
 *   - 핸들러 등록과 무거운 발송 lib 로드는 cf_guardian_use=1 일 때만.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package    gb_guardian
 * @version    1.0.0
 * @author     K3SOFT / WizardOfCode
 */
if (!defined('_GNUBOARD_')) exit;

// =====================================================================
// 1. 상수 / 테이블명 — 항상 정의
// =====================================================================
// cleanup.php 실행 후 g5_config 컬럼이 사라진 환경에서도 호환 레이어가 동작
// 하려면 guardian_config 테이블 키가 미리 정의돼 있어야 한다. install.php
// 미실행 환경에서도 본 정의 자체는 비용이 거의 없으므로 무조건 등록한다.
if (!defined('GUARDIAN_VERSION')) {
    define('GUARDIAN_VERSION', '1.0.0');
    define('GUARDIAN_PATH',     G5_PLUGIN_PATH . '/gb_guardian');
    define('GUARDIAN_LIB_PATH', GUARDIAN_PATH . '/lib');
    define('GUARDIAN_URL',      G5_PLUGIN_URL  . '/gb_guardian');
}
$g5['guardian_log_table']             = G5_TABLE_PREFIX . 'guardian_log';
$g5['guardian_rule_table']            = G5_TABLE_PREFIX . 'guardian_rule';
$g5['guardian_recipient_table']       = G5_TABLE_PREFIX . 'guardian_recipient';
$g5['guardian_notify_log_table']      = G5_TABLE_PREFIX . 'guardian_notify_log';
$g5['guardian_summary_queue_table']   = G5_TABLE_PREFIX . 'guardian_summary_queue';
$g5['guardian_rule_match_log_table']  = G5_TABLE_PREFIX . 'guardian_rule_match_log';
$g5['guardian_config_table']          = G5_TABLE_PREFIX . 'guardian_config';

// =====================================================================
// 2. guardian_config 호환 레이어 (cf_guardian_* 키 주입)
// =====================================================================
// 호환 레이어는 cf_guardian_use 체크보다 먼저 실행돼야 한다. 그렇지 않으면
// cleanup.php 가 g5_config 의 cf_guardian_* 컬럼을 모두 삭제한 환경에서
// $config[cf_guardian_use] 자체가 없어 아래 array_key_exists 가 FALSE 를
// 반환 → 조기 종료 → 플러그인 전체가 비활성 상태가 된다.
//
// 호환 레이어는 다음 동작을 한다.
//   - guardian_config 테이블이 있으면 cfg_key='use' 등을 \$config[cf_guardian_*]
//     로 주입 (이미 \$config 에 있으면 덮어쓰지 않음)
//   - guardian_config 테이블이 없으면 (install.php 미실행 시점) 빈 배열만
//     반환하므로 \$config 변경 없음
$_guardian_config_lib = G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_config.lib.php';
if (@is_readable($_guardian_config_lib)) {
    require_once $_guardian_config_lib;
    if (function_exists('guardian_config_inject_to_global_config')) {
        guardian_config_inject_to_global_config();
    }
}

// =====================================================================
// 3. 시나리오 A — 설치 자체가 안된 환경
// =====================================================================
// 호환 레이어 실행 후에도 \$config[cf_guardian_use] 가 없으면:
//   - g5_config 에 컬럼 없음 (install.php 미실행)
//   - guardian_config 테이블에도 'use' 키 없음 (마이그레이션 미실행)
// → install.php 미실행 환경. 즉시 종료해 흔적을 0건으로 유지한다.
//
// 사용자는 직접 /plugin/gb_guardian/install.php URL 을 호출해 설치한다.
if (!array_key_exists('cf_guardian_use', $config)) {
    return;
}

// =====================================================================
// 4. admin 영역 — 비활성 상태에서도 메뉴는 등록 (닭-달걀 문제 해결)
// =====================================================================
// G5_IS_ADMIN 은 adm/_common.php 가 common.php 보다 먼저 정의하므로
// extend 로드 시점에 이미 적용돼 있다. 일반 사용자 페이지에는 없다.
//
// admin 영역에서는 비활성 상태라도:
//   - guardian.lib.php (메뉴 함수 정의) 1개만 로드 (5KB 미만, 함수 정의만)
//   - admin_menu 훅에 메뉴 콜백 등록
// 이렇게 해야 사용자가 좌측 메뉴 → 운영지킴이 환경설정 → cf_guardian_use 활성화
// 라는 정상 흐름으로 도달할 수 있다.
//
// admin 메뉴는 admin.head.php 가 그릴 때만 콜백을 호출하므로 일반 페이지에
// 영향이 0이다.
if (defined('G5_IS_ADMIN') && G5_IS_ADMIN) {
    require_once GUARDIAN_LIB_PATH . '/guardian.lib.php';
    // admin 영역에서는 비활성 상태에서도 환경설정 페이지가 brand /
    // partner 박스를 렌더링해야 하므로 함수 정의 lib 만 미리 로드한다 (실제
    // 호출은 페이지가 명시 호출).
    require_once GUARDIAN_LIB_PATH . '/guardian_brand.lib.php';
    require_once GUARDIAN_LIB_PATH . '/guardian_partner.lib.php';
    if (function_exists('add_replace')) {
        // ★ 두 훅 모두 등록 필수
        // - admin_amenu : 그룹 매핑(`$amenu`) 에 키 '700' 추가 — 메뉴가 화면에 그려지려면 필요
        // - admin_menu  : 항목 배열(`$menu['menu700']`) 에 메뉴들 추가
        // admin_amenu 가 빠지면 admin.head.php 의 foreach ($amenu) 루프가
        // 700 그룹을 순회하지 않아 menu700 항목이 모두 무시된다.
        add_replace('admin_amenu', 'guardian_admin_amenu', G5_HOOK_DEFAULT_PRIORITY, 1);
        add_replace('admin_menu',  'guardian_admin_menu',  G5_HOOK_DEFAULT_PRIORITY, 1);
    }
}

// =====================================================================
// 5. 시나리오 C — 비활성 상태면 여기서 종료 (핸들러/발송 lib 로드 X)
// =====================================================================
if (empty($config['cf_guardian_use'])) {
    return;
}

// =====================================================================
// 6. 시나리오 B — 활성 상태: 풀 라이브러리 로드 + 핸들러 등록
// =====================================================================
// 캡처 엔진 (admin 영역에서는 guardian.lib.php 가 위에서 이미 로드됐지만
// require_once 라 두 번째 호출은 무시된다)
require_once GUARDIAN_LIB_PATH . '/guardian.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_db.lib.php';
require_once GUARDIAN_LIB_PATH . '/error_handler.lib.php';

// 알림 발송 인프라 (보호 + 어댑터). 운영지킴이가 활성 상태면
// 어떤 페이지에서든 run_event('guardian_after_capture') 훅으로 발송이
// 호출될 수 있으므로 미리 로드해둔다. 알리고 등 무거운 클래스 자체는
// 어댑터 함수가 처음 호출될 때 lazy-load.
require_once GUARDIAN_LIB_PATH . '/guardian_protector.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_template.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_mailer.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_aligo_sms.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_aligo_kakao.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_notifier.lib.php';

// 알림 규칙 엔진 + 요약 발송. dispatcher 가 guardian_after_capture
// 훅에 콜백을 등록하므로 캡처가 일어나기 전에 미리 로드돼야 한다.
require_once GUARDIAN_LIB_PATH . '/guardian_rule_engine.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_summary.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_dispatcher.lib.php';

// 브랜드 / 시리즈 메타데이터 (메일 푸터, 미해결 알림 박스 등에 사용)
require_once GUARDIAN_LIB_PATH . '/guardian_brand.lib.php';
require_once GUARDIAN_LIB_PATH . '/guardian_partner.lib.php';

// =====================================================================
// 7. 핸들러 등록 (활성 상태에서만)
// =====================================================================
guardian_register_handlers();

// =====================================================================
// 8. 그누보드 SQL 오류 훅 (있으면 등록 — 구버전 호환)
// =====================================================================
if (function_exists('add_event')) {
    add_event('sql_error', 'guardian_on_sql_error', G5_HOOK_DEFAULT_PRIORITY, 2);

    // 오류 캡처 후 매칭 엔진 호출
    add_event('guardian_after_capture', 'guardian_dispatch_after_capture', G5_HOOK_DEFAULT_PRIORITY, 1);
}
// 메뉴 등록은 위 4번 블록(admin 영역) 에서 이미 처리됨 — 여기 다시 호출하지 않는다.

// =====================================================================
// 9. 방문자 트리거 — cron 사용 불가 환경 대체
// =====================================================================
// cf_guardian_summary_mode === 'visitor' 일 때만 동작. 1% 확률 + 마지막
// 실행 시각 5분 가드로 부하 회피. fastcgi_finish_request 로 사용자 응답에
// 영향 없게 처리. 관리자 영역과 CLI 는 트리거 대상에서 제외.
if (PHP_SAPI !== 'cli'
    && (!defined('G5_IS_ADMIN') || !G5_IS_ADMIN)
    && isset($config['cf_guardian_summary_mode'])
    && $config['cf_guardian_summary_mode'] === 'visitor') {

    if (mt_rand(1, 100) === 1) {
        $last_run = !empty($config['cf_guardian_summary_last_daily'])
            ? strtotime((string)$config['cf_guardian_summary_last_daily'])
            : 0;

        if (time() - (int)$last_run > 300) {
            register_shutdown_function('guardian_visitor_trigger_summary');
        }
    }
}
