<?php
/**
 * 그누보드 운영지킴이 - 환경설정 저장 처리
 *
 * 입력값 정제 + g5_config UPDATE.
 * 모든 입력은 화이트리스트 / 캐스팅 / 클램프 처리한다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700900";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'w');
check_admin_token();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    alert('잘못된 접근입니다.');
}

// ---- 1. 수집 등급 — 화이트리스트 검증 ----
$valid_levels = array('FATAL', 'ERROR', 'WARNING', 'EXCEPTION', 'DB', 'NOTICE', 'DEPRECATED');
$collect_input = isset($_POST['collect_levels']) && is_array($_POST['collect_levels'])
    ? $_POST['collect_levels']
    : array();

$collect_clean = array();
foreach ($collect_input as $lv) {
    $lv = strtoupper(trim((string)$lv));
    if (in_array($lv, $valid_levels, true) && !in_array($lv, $collect_clean, true)) {
        $collect_clean[] = $lv;
    }
}
// 빈 배열이면 안전 기본값
if (empty($collect_clean)) {
    $collect_clean = array('FATAL', 'ERROR', 'EXCEPTION');
}
$collect_str = implode(',', $collect_clean);

// ---- 2. 숫자 필드 — 캐스팅 + 클램프 ----
$keep_days = isset($_POST['keep_days']) ? (int)$_POST['keep_days'] : 30;
if ($keep_days < 1)   $keep_days = 1;
if ($keep_days > 365) $keep_days = 365;

$cooldown = isset($_POST['cooldown_min']) ? (int)$_POST['cooldown_min'] : 30;
if ($cooldown < 1)    $cooldown = 1;
if ($cooldown > 1440) $cooldown = 1440;

// ---- 3. 토글 ----
$use   = !empty($_POST['guardian_use']) ? 1 : 0;
$aligo = !empty($_POST['aligo_enabled']) ? 1 : 0;

// =====================================================================
// 알림 발송 보호 설정
// =====================================================================
$emergency_stop     = !empty($_POST['emergency_stop'])     ? 1 : 0;
$sms_silent_enabled = !empty($_POST['sms_silent_enabled']) ? 1 : 0;

$email_daily_limit = isset($_POST['email_daily_limit']) ? (int)$_POST['email_daily_limit'] : 500;
if ($email_daily_limit < 0)     $email_daily_limit = 0;
if ($email_daily_limit > 10000) $email_daily_limit = 10000;

$sms_daily_limit = isset($_POST['sms_daily_limit']) ? (int)$_POST['sms_daily_limit'] : 50;
if ($sms_daily_limit < 0)    $sms_daily_limit = 0;
if ($sms_daily_limit > 1000) $sms_daily_limit = 1000;

$kakao_daily_limit = isset($_POST['kakao_daily_limit']) ? (int)$_POST['kakao_daily_limit'] : 100;
if ($kakao_daily_limit < 0)    $kakao_daily_limit = 0;
if ($kakao_daily_limit > 2000) $kakao_daily_limit = 2000;

$sms_silent_start = isset($_POST['sms_silent_start']) ? (int)$_POST['sms_silent_start'] : 22;
if ($sms_silent_start < 0 || $sms_silent_start > 23) $sms_silent_start = 22;

$sms_silent_end = isset($_POST['sms_silent_end']) ? (int)$_POST['sms_silent_end'] : 8;
if ($sms_silent_end < 0 || $sms_silent_end > 23) $sms_silent_end = 8;

// (cf_guardian_default_cooldown_notify 는 제거됨 — 매칭 엔진/테스트 발송 모두
//  cooldown_min 옵션을 명시 전달하므로 폴백 컬럼이 사용되지 않는 죽은 컬럼이었음.
//  쿨다운은 알림 규칙 등록 화면의 "동일 오류 쿨다운" 으로 규칙별 설정.)
// (메일 발송 모드 컬럼 cf_guardian_mailer_mode 는 제거됨 — 그누보드 mailer() 자동 분기)

// 요약 발송 트리거 모드 (visitor | cron)
$valid_summary_modes = array('visitor', 'cron');
$summary_mode = isset($_POST['summary_mode']) ? (string)$_POST['summary_mode'] : 'visitor';
if (!in_array($summary_mode, $valid_summary_modes, true)) $summary_mode = 'visitor';

// 매칭 추적 로깅 ON/OFF + 보관 기간 (1~365일)
$match_logging   = !empty($_POST['match_logging']) ? 1 : 0;
$match_keep_days = isset($_POST['match_keep_days']) ? (int)$_POST['match_keep_days'] : 7;
if ($match_keep_days < 1)   $match_keep_days = 1;
if ($match_keep_days > 365) $match_keep_days = 365;

// 카톡 템플릿 코드 / 강조 표기 — 길이 클램프
$kakao_tpl_code = isset($_POST['kakao_tpl_code']) ? trim((string)$_POST['kakao_tpl_code']) : '';
if (function_exists('mb_substr')) {
    $kakao_tpl_code = mb_substr($kakao_tpl_code, 0, 50, 'UTF-8');
} else {
    $kakao_tpl_code = substr($kakao_tpl_code, 0, 50);
}

$kakao_emphasize = isset($_POST['kakao_emphasize']) ? trim((string)$_POST['kakao_emphasize']) : '';
if (function_exists('mb_substr')) {
    $kakao_emphasize = mb_substr($kakao_emphasize, 0, 50, 'UTF-8');
} else {
    $kakao_emphasize = substr($kakao_emphasize, 0, 50);
}

// 노출 ON/OFF 옵션 4개 (guardian_config 전용, g5_config 에는 안 들어감)
$brand_footer_enabled     = !empty($_POST['brand_footer_enabled'])     ? 1 : 0;
$unresolved_alert_enabled = !empty($_POST['unresolved_alert_enabled']) ? 1 : 0;
$series_section_enabled   = !empty($_POST['series_section_enabled'])   ? 1 : 0;
$kakao_channel_enabled    = !empty($_POST['kakao_channel_enabled'])    ? 1 : 0;

// cron 인증 토큰 — 영숫자/하이픈/언더스코어만 허용, 8~64자.
// 빈 값 / 너무 짧은 값 / 부적합 문자가 있으면 빈 문자열로 정규화 →
// guardian_config_set 시 빈 문자열로 저장되고, 다음 환경설정 진입 시
// guardian_get_or_create_cron_secret() 가 자동 재생성.
$cron_secret_input = isset($_POST['cron_secret']) ? trim((string)$_POST['cron_secret']) : '';
if ($cron_secret_input !== '') {
    // 화이트리스트 — 영숫자, 하이픈, 언더스코어
    if (!preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $cron_secret_input)) {
        // 길이/문자 부적합 → 빈 값으로 — 자동 재생성 트리거
        $cron_secret_input = '';
    }
}

// ---- 4. UPDATE ----
$config_table = !empty($g5['config_table']) ? $g5['config_table'] : (G5_TABLE_PREFIX . 'config');

// 호환 — g5_config 의 cf_guardian_* 컬럼이 아직 존재하는 환경에서는
// 기존 UPDATE 가 동작. cleanup.php 실행 후에는 컬럼 자체가 없으므로 SET 절
// 대상 컬럼이 누락되어 SQL 오류 가능. 따라서 첫 컬럼 존재 여부로 두 분기.
$has_legacy = false;
$col_check = sql_fetch(
    " SHOW COLUMNS FROM `{$config_table}` LIKE 'cf_guardian_use' ",
    false
);
if (!empty($col_check)) $has_legacy = true;

if ($has_legacy) {
    $sql = " UPDATE `{$config_table}` SET
                cf_guardian_use                       = " . (int)$use . ",
                cf_guardian_collect_levels            = '" . sql_escape_string($collect_str) . "',
                cf_guardian_log_keep_days             = " . (int)$keep_days . ",
                cf_guardian_default_cooldown_min      = " . (int)$cooldown . ",
                cf_guardian_aligo_enabled             = " . (int)$aligo . ",
                cf_guardian_emergency_stop            = " . (int)$emergency_stop . ",
                cf_guardian_email_daily_limit         = " . (int)$email_daily_limit . ",
                cf_guardian_sms_daily_limit           = " . (int)$sms_daily_limit . ",
                cf_guardian_kakao_daily_limit         = " . (int)$kakao_daily_limit . ",
                cf_guardian_sms_silent_enabled        = " . (int)$sms_silent_enabled . ",
                cf_guardian_sms_silent_start          = " . (int)$sms_silent_start . ",
                cf_guardian_sms_silent_end            = " . (int)$sms_silent_end . ",
                cf_guardian_summary_mode              = '" . sql_escape_string($summary_mode) . "',
                cf_guardian_rule_match_logging        = " . (int)$match_logging . ",
                cf_guardian_rule_match_keep_days      = " . (int)$match_keep_days . ",
                cf_guardian_kakao_template_code       = '" . sql_escape_string($kakao_tpl_code) . "',
                cf_guardian_kakao_emphasize_title     = '" . sql_escape_string($kakao_emphasize) . "' ";
    @sql_query($sql, false);
}

// guardian_config 테이블에도 항상 동기화 (cleanup.php 실행 후에도 동작 보장)
if (defined('GUARDIAN_LIB_PATH')) {
    require_once GUARDIAN_LIB_PATH . '/guardian_config.lib.php';
} else {
    require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_config.lib.php';
}
if (function_exists('guardian_config_set')) {
    guardian_config_set('use',                   $use,                   'bool');
    guardian_config_set('collect_levels',        $collect_str,           'string');
    guardian_config_set('log_keep_days',         $keep_days,             'int');
    guardian_config_set('default_cooldown_min',  $cooldown,              'int');
    guardian_config_set('aligo_enabled',         $aligo,                 'bool');
    guardian_config_set('emergency_stop',        $emergency_stop,        'bool');
    guardian_config_set('email_daily_limit',     $email_daily_limit,     'int');
    guardian_config_set('sms_daily_limit',       $sms_daily_limit,       'int');
    guardian_config_set('kakao_daily_limit',     $kakao_daily_limit,     'int');
    guardian_config_set('sms_silent_enabled',    $sms_silent_enabled,    'bool');
    guardian_config_set('sms_silent_start',      $sms_silent_start,      'int');
    guardian_config_set('sms_silent_end',        $sms_silent_end,        'int');
    guardian_config_set('summary_mode',          $summary_mode,          'string');
    guardian_config_set('rule_match_logging',    $match_logging,         'bool');
    guardian_config_set('rule_match_keep_days',  $match_keep_days,       'int');
    guardian_config_set('kakao_template_code',   $kakao_tpl_code,        'string');
    guardian_config_set('kakao_emphasize_title', $kakao_emphasize,       'string');

    // 신규 ON/OFF 옵션 — guardian_config 전용
    guardian_config_set('brand_footer_enabled',     $brand_footer_enabled,     'bool');
    guardian_config_set('unresolved_alert_enabled', $unresolved_alert_enabled, 'bool');
    guardian_config_set('series_section_enabled',   $series_section_enabled,   'bool');
    guardian_config_set('kakao_channel_enabled',    $kakao_channel_enabled,    'bool');

    // cron 인증 토큰 — 정상 입력은 그대로 저장. 비어있거나 부적합이면
    // 빈 문자열을 저장 → 다음 환경설정 진입 시 guardian_get_or_create_cron_secret()
    // 가 자동 재생성한다.
    guardian_config_set('cron_secret', $cron_secret_input, 'string');
}

goto_url('./guardian_config.php?msg=' . urlencode('환경설정이 저장되었습니다.'));
