<?php
/**
 * 그누보드 운영지킴이 - 설치 / 업그레이드 스크립트
 *
 * 본 스크립트는 다음을 수행한다.
 *   1. 7개 운영지킴이 테이블 생성 (CREATE TABLE IF NOT EXISTS)
 *      그누보드 g5_config 테이블은 절대 수정하지 않는다 (v1.1+).
 *   2. v1.0 → v1.1 업그레이드 환경에서만 g5_config.cf_guardian_* → guardian_config
 *      자동 마이그레이션. 신규 v1.1 설치 시에는 g5_config 변경 0건.
 *   3. guardian_config 기본 설정값 INSERT (없는 키만)
 *   4. cron 인증 토큰 자동 생성
 *   5. PHP / 확장 / DB 환경 점검 결과 출력
 *
 * 모든 ALTER 는 SHOW COLUMNS 사전 체크 패턴으로 100회 재실행해도 안전하다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.1.0
 */
include_once('../../common.php');

if (!defined('_GNUBOARD_')) exit;

// 관리자만 접근 가능 — 그누보드 컨벤션
if (!$is_admin) {
    alert('관리자만 접근 가능합니다.');
}

$msg = array();

// ---------------------------------------------------------------------
// 0. 캐릭터셋 / 테이블 접두사 결정
// ---------------------------------------------------------------------
// 그누보드 환경값을 그대로 따르되, 누락된 경우 안전 기본값 사용.
$charset = !empty($g5['db_charset']) ? $g5['db_charset'] : 'utf8';

// 운영지킴이 테이블명 (extend 미적재 환경에서도 install.php 단독 실행 가능)
$tbl_log             = G5_TABLE_PREFIX . 'guardian_log';
$tbl_rule            = G5_TABLE_PREFIX . 'guardian_rule';
$tbl_recipient       = G5_TABLE_PREFIX . 'guardian_recipient';
$tbl_notify_log      = G5_TABLE_PREFIX . 'guardian_notify_log';
$tbl_summary_queue   = G5_TABLE_PREFIX . 'guardian_summary_queue';     // 
$tbl_rule_match_log  = G5_TABLE_PREFIX . 'guardian_rule_match_log';    // 
$tbl_guardian_config = G5_TABLE_PREFIX . 'guardian_config';            // 신규 (g5_config 대체)

// ---------------------------------------------------------------------
// 1. g5_config 사전 정보 (마이그레이션 / 죽은 컬럼 제거 시 사용)
// ---------------------------------------------------------------------
// v1.1+ 부터는 g5_config 테이블에 새 컬럼을 절대 추가하지 않는다.
// 운영지킴이 모든 설정은 별도 guardian_config 테이블에 저장된다.
// 본 변수는 v1.0 → v1.1 업그레이드 환경 감지 + 죽은 컬럼 DROP 에만 사용.
$config_table = !empty($g5['config_table']) ? $g5['config_table'] : (G5_TABLE_PREFIX . 'config');

// ---------------------------------------------------------------------
// 2. 테이블 생성 (CREATE TABLE IF NOT EXISTS)
// ---------------------------------------------------------------------

// --- 2.1 guardian_log : 오류 로그 ---
$sql_log = "
CREATE TABLE IF NOT EXISTS `{$tbl_log}` (
  `log_id`            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `error_hash`        VARCHAR(64)         NOT NULL DEFAULT '',
  `error_level`       VARCHAR(20)         NOT NULL DEFAULT 'NOTICE',
  `error_message`     TEXT                NOT NULL,
  `error_file`        VARCHAR(500)        NOT NULL DEFAULT '',
  `error_line`        INT(10) UNSIGNED    NOT NULL DEFAULT '0',
  `error_trace`       MEDIUMTEXT          NULL,
  `request_url`       VARCHAR(500)        NOT NULL DEFAULT '',
  `request_method`    VARCHAR(10)         NOT NULL DEFAULT '',
  `user_id`           VARCHAR(50)         NOT NULL DEFAULT '',
  `user_ip`           VARCHAR(50)         NOT NULL DEFAULT '',
  `occurrence_count`  INT(10) UNSIGNED    NOT NULL DEFAULT '1',
  `last_occurred_dt`  DATETIME            NULL DEFAULT NULL,
  `resolved`          TINYINT(1)          NOT NULL DEFAULT '0',
  `notified`          TINYINT(1)          NOT NULL DEFAULT '0',
  `created_dt`        DATETIME            NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `idx_hash_created` (`error_hash`, `created_dt`),
  KEY `idx_level_created` (`error_level`, `created_dt`),
  KEY `idx_resolved`     (`resolved`, `created_dt`),
  KEY `idx_notified`     (`notified`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset}
";

// --- 2.2 guardian_rule : 알림 규칙 ---
$sql_rule = "
CREATE TABLE IF NOT EXISTS `{$tbl_rule}` (
  `rule_id`         INT(10) UNSIGNED  NOT NULL AUTO_INCREMENT,
  `rule_name`       VARCHAR(100)      NOT NULL DEFAULT '',
  `rule_active`     TINYINT(1)        NOT NULL DEFAULT '1',
  `error_levels`    VARCHAR(200)      NOT NULL DEFAULT '',
  `file_pattern`    VARCHAR(200)      NOT NULL DEFAULT '',
  `channel`         VARCHAR(50)       NOT NULL DEFAULT 'email',
  `recipient_ids`   VARCHAR(500)      NOT NULL DEFAULT '',
  `mode`            VARCHAR(20)       NOT NULL DEFAULT 'instant',
  `schedule_time`   VARCHAR(50)       NOT NULL DEFAULT '',
  `cooldown_min`    INT(10) UNSIGNED  NOT NULL DEFAULT '30',
  `daily_limit`     INT(10) UNSIGNED  NOT NULL DEFAULT '50',
  `created_dt`      DATETIME          NOT NULL,
  `updated_dt`      DATETIME          NULL DEFAULT NULL,
  PRIMARY KEY (`rule_id`),
  KEY `idx_active` (`rule_active`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset}
";

// --- 2.3 guardian_recipient : 알림 수신자 ---
// 알리고 카카오 알림톡은 휴대폰 번호 기반 발송이라 별도 카카오 ID 컬럼이 불필요.
$sql_recipient = "
CREATE TABLE IF NOT EXISTS `{$tbl_recipient}` (
  `recipient_id`  INT(10) UNSIGNED  NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(50)       NOT NULL DEFAULT '',
  `email`         VARCHAR(100)      NOT NULL DEFAULT '',
  `mobile`        VARCHAR(20)       NOT NULL DEFAULT '',
  `active`        TINYINT(1)        NOT NULL DEFAULT '1',
  `created_dt`    DATETIME          NOT NULL,
  PRIMARY KEY (`recipient_id`),
  KEY `idx_active` (`active`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset}
";

// --- 2.4 guardian_notify_log : 알림 발송 이력 ---
$sql_notify_log = "
CREATE TABLE IF NOT EXISTS `{$tbl_notify_log}` (
  `notify_id`     BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id`       INT(10) UNSIGNED    NOT NULL DEFAULT '0',
  `error_hash`    VARCHAR(64)         NOT NULL DEFAULT '',
  `channel`       VARCHAR(20)         NOT NULL DEFAULT '',
  `recipient`     VARCHAR(200)        NOT NULL DEFAULT '',
  `status`        VARCHAR(20)         NOT NULL DEFAULT '',
  `fail_reason`   TEXT                NULL,
  `sent_dt`       DATETIME            NOT NULL,
  PRIMARY KEY (`notify_id`),
  KEY `idx_rule_sent` (`rule_id`, `sent_dt`),
  KEY `idx_hash`      (`error_hash`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset}
";

// --- 2.5 guardian_summary_queue : 일일/주간 요약 발송 큐 ---
$sql_summary_queue = "
CREATE TABLE IF NOT EXISTS `{$tbl_summary_queue}` (
  `queue_id`      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `rule_id`       INT(10) UNSIGNED    NOT NULL,
  `log_id`        BIGINT(20) UNSIGNED NOT NULL,
  `error_hash`    VARCHAR(64)         NOT NULL DEFAULT '',
  `mode`          VARCHAR(20)         NOT NULL DEFAULT 'daily',
  `processed`     TINYINT(1)          NOT NULL DEFAULT '0',
  `created_dt`    DATETIME            NOT NULL,
  PRIMARY KEY (`queue_id`),
  KEY `idx_processed_mode` (`processed`, `mode`),
  KEY `idx_rule_processed` (`rule_id`, `processed`),
  KEY `idx_created`        (`created_dt`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset}
";

// --- 2.6 guardian_rule_match_log : 규칙 매칭 추적 로그 (★ 차별화 포인트) ---
$sql_rule_match_log = "
CREATE TABLE IF NOT EXISTS `{$tbl_rule_match_log}` (
  `match_id`      BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `log_id`        BIGINT(20) UNSIGNED NOT NULL,
  `error_hash`    VARCHAR(64)         NOT NULL DEFAULT '',
  `rule_id`       INT(10) UNSIGNED    NOT NULL DEFAULT '0',
  `result`        VARCHAR(30)         NOT NULL DEFAULT '',
  `result_detail` TEXT                NULL,
  `created_dt`    DATETIME            NOT NULL,
  PRIMARY KEY (`match_id`),
  KEY `idx_log_id`      (`log_id`),
  KEY `idx_rule_result` (`rule_id`, `result`),
  KEY `idx_created`     (`created_dt`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset}
";

// --- 2.7 guardian_config : 별도 설정 테이블 ---
$sql_guardian_config = "
CREATE TABLE IF NOT EXISTS `{$tbl_guardian_config}` (
  `cfg_key`     VARCHAR(64)  NOT NULL,
  `cfg_value`   TEXT         NULL,
  `cfg_type`    VARCHAR(16)  NOT NULL DEFAULT 'string',
  `updated_dt`  DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`cfg_key`)
) ENGINE=InnoDB DEFAULT CHARSET={$charset}
";

$tables = array(
    $tbl_log             => $sql_log,
    $tbl_rule            => $sql_rule,
    $tbl_recipient       => $sql_recipient,
    $tbl_notify_log      => $sql_notify_log,
    $tbl_summary_queue   => $sql_summary_queue,
    $tbl_rule_match_log  => $sql_rule_match_log,
    $tbl_guardian_config => $sql_guardian_config,
);

foreach ($tables as $tname => $sql) {
    @sql_query($sql, false);
    $check = sql_fetch(" SHOW TABLES LIKE '" . sql_escape_string($tname) . "' ", false);
    if (!empty($check)) {
        $msg[] = "[OK] 테이블 확인/생성 완료 — {$tname}";
    } else {
        $msg[] = "[FAIL] 테이블 생성 실패 — {$tname} (DB 권한을 확인하세요)";
    }
}

// ---------------------------------------------------------------------
// 2.5 마이그레이션 — 죽은 컬럼 제거 (재실행 안전)
// ---------------------------------------------------------------------

// (a) guardian_recipient.kakao_id : 알리고 알림톡은 휴대폰 번호 기반이라
//     별도 카카오 ID 가 필요 없어 제거한다.
$col_check = sql_fetch(
    " SHOW COLUMNS FROM `{$tbl_recipient}` LIKE 'kakao_id' ",
    false
);
if (!empty($col_check)) {
    @sql_query(" ALTER TABLE `{$tbl_recipient}` DROP COLUMN `kakao_id` ", false);
    $msg[] = "[MIGRATE] guardian_recipient.kakao_id 컬럼 제거 완료";
} else {
    $msg[] = "[SKIP] guardian_recipient.kakao_id 컬럼 이미 없음";
}

// (b) g5_config.cf_guardian_mailer_mode : 그누보드 mailer() 가 SMTP Manager
//     의 mail_options 훅을 통해 SMTP/PHP-mail 자동 분기를 처리하므로
//     운영지킴이 자체 모드 설정이 불필요. 컬럼 제거.
$col_check = sql_fetch(
    " SHOW COLUMNS FROM `{$config_table}` LIKE 'cf_guardian_mailer_mode' ",
    false
);
if (!empty($col_check)) {
    @sql_query(" ALTER TABLE `{$config_table}` DROP COLUMN `cf_guardian_mailer_mode` ", false);
    $msg[] = "[MIGRATE] g5_config.cf_guardian_mailer_mode 컬럼 제거 완료";
} else {
    $msg[] = "[SKIP] g5_config.cf_guardian_mailer_mode 컬럼 이미 없음";
}

// (b2) g5_config.cf_guardian_default_cooldown_notify : 모든 guardian_notify()
//      호출이 cooldown_min 옵션을 명시 전달하므로(매칭 엔진은 규칙 cooldown_min,
//      테스트 발송은 0) 폴백 컬럼이 사용되지 않는 죽은 컬럼. 제거.
$col_check = sql_fetch(
    " SHOW COLUMNS FROM `{$config_table}` LIKE 'cf_guardian_default_cooldown_notify' ",
    false
);
if (!empty($col_check)) {
    @sql_query(" ALTER TABLE `{$config_table}` DROP COLUMN `cf_guardian_default_cooldown_notify` ", false);
    $msg[] = "[MIGRATE] g5_config.cf_guardian_default_cooldown_notify 컬럼 제거 완료";
} else {
    $msg[] = "[SKIP] g5_config.cf_guardian_default_cooldown_notify 컬럼 이미 없음";
}

// (c) guardian_rule 테이블에 priority / dedup_scope 컬럼 추가
//     기본 스키마에는 두 컬럼이 없었음.
$col_check = sql_fetch(
    " SHOW COLUMNS FROM `{$tbl_rule}` LIKE 'priority' ",
    false
);
if (empty($col_check)) {
    @sql_query(
        " ALTER TABLE `{$tbl_rule}`
          ADD COLUMN `priority` INT(11) NOT NULL DEFAULT '50' AFTER `mode` ",
        false
    );
    $msg[] = "[MIGRATE] guardian_rule.priority 컬럼 추가 완료";
} else {
    $msg[] = "[SKIP] guardian_rule.priority 컬럼 이미 존재";
}

$col_check = sql_fetch(
    " SHOW COLUMNS FROM `{$tbl_rule}` LIKE 'dedup_scope' ",
    false
);
if (empty($col_check)) {
    @sql_query(
        " ALTER TABLE `{$tbl_rule}`
          ADD COLUMN `dedup_scope` VARCHAR(20) NOT NULL DEFAULT 'rule' AFTER `priority` ",
        false
    );
    $msg[] = "[MIGRATE] guardian_rule.dedup_scope 컬럼 추가 완료";
} else {
    $msg[] = "[SKIP] guardian_rule.dedup_scope 컬럼 이미 존재";
}

// =====================================================================
// 2.6 g5_config.cf_guardian_* → guardian_config 마이그레이션 (v1.0 → v1.1)
// =====================================================================
// guardian_config.lib.php 가 마이그레이션 함수와 캐시 로직을 제공한다.
// install.php 단독 실행 시에도 동작하도록 $g5 키를 직접 등록.
// 신규 v1.1 설치 환경에서는 g5_config 에 cf_guardian_* 컬럼이 없으므로
// 마이그레이션 함수가 0건 처리하고, 이후 기본값 INSERT 단계가 보강한다.
$g5['guardian_config_table'] = $tbl_guardian_config;

require_once __DIR__ . '/lib/guardian_config.lib.php';

// 신규 설치 vs v1.0→v1.1 업그레이드 시나리오 감지
$has_legacy_columns = false;
foreach (array('cf_guardian_use', 'cf_guardian_collect_levels') as $check_col) {
    $exists = sql_fetch(
        " SHOW COLUMNS FROM `{$config_table}` LIKE '" . sql_escape_string($check_col) . "' ",
        false
    );
    if (!empty($exists)) { $has_legacy_columns = true; break; }
}

// g5_config → guardian_config 마이그레이션 (멱등 — 마커 있으면 SKIP)
$migration_result = guardian_config_migrate_from_g5_config();
if ($migration_result['status'] === 'completed') {
    $msg[] = "[MIGRATE] g5_config → guardian_config 이전 완료 ({$migration_result['count']}개 항목)";
} else {
    $msg[] = "[SKIP] g5_config → guardian_config 이미 마이그레이션됨";
}

// guardian_config 기본값 INSERT — 없는 키만 추가 (멱등).
// 마이그레이션이 우선이라 v1.0 값이 살아있으면 그대로 유지된다.
// 신규 v1.1 설치에서는 g5_config 컬럼이 없어 마이그레이션이 0건 처리하므로,
// 본 블록에서 모든 핵심 설정의 기본값을 guardian_config 에 보강해준다.
$guardian_config_defaults = array(
    // 핵심 설정
    array('use',                       0,                                  'bool'),
    array('collect_levels',            'FATAL,ERROR,WARNING,EXCEPTION,DB', 'string'),
    array('log_keep_days',             30,                                 'int'),
    array('default_cooldown_min',      30,                                 'int'),
    array('aligo_enabled',             0,                                  'bool'),
    // 알림 발송 보호
    array('emergency_stop',            0,                                  'bool'),
    array('email_daily_limit',         500,                                'int'),
    array('sms_daily_limit',           50,                                 'int'),
    array('kakao_daily_limit',         100,                                'int'),
    array('sms_silent_enabled',        1,                                  'bool'),
    array('sms_silent_start',          22,                                 'int'),
    array('sms_silent_end',            8,                                  'int'),
    array('kakao_template_code',       '',                                 'string'),
    array('kakao_emphasize_title',     '',                                 'string'),
    // 알림 규칙 엔진 + 요약 발송
    array('rule_match_logging',        1,                                  'bool'),
    array('rule_match_keep_days',      7,                                  'int'),
    array('summary_mode',              'visitor',                          'string'),
    array('summary_last_daily',        '',                                 'string'),
    array('summary_last_weekly',       '',                                 'string'),
    // 브랜드 / 시리즈 노출 ON/OFF
    array('brand_footer_enabled',      1,                                  'bool'),
    array('unresolved_alert_enabled',  1,                                  'bool'),
    array('series_section_enabled',    1,                                  'bool'),
    array('kakao_channel_enabled',     1,                                  'bool'),
    // URL 기본값
    array('repair_url',                'https://wizardofcode.kr/?page_id=941',   'string'),
    array('series_page_url',           'https://wizardofcode.kr/?page_id=962',   'string'),
    array('kakao_channel_url',         'https://pf.kakao.com/_mkUxdn',     'string'),
);
$defaults_added = 0;
foreach ($guardian_config_defaults as $def) {
    list($k, $v, $t) = $def;
    // 명시 NULL 폴백으로 키 존재 여부 확인 (마이그레이션 / 기존 값이 있으면 보존)
    if (guardian_config_get($k, null) === null) {
        guardian_config_set($k, $v, $t);
        $defaults_added++;
    }
}
$msg[] = "[OK] guardian_config 기본 설정 {$defaults_added}건 추가" . ($defaults_added === 0 ? ' (이미 모두 존재)' : '');

// cron 인증 토큰 — 첫 설치/업그레이드 시 자동 생성. 기존 sha1(cf_admin_email)
// 방식은 메일 주소만 알면 추론 가능해 보안 위험. 랜덤 토큰으로 교체한다.
if (function_exists('guardian_get_or_create_cron_secret')) {
    $existed_secret = guardian_config_get('cron_secret', '');
    $token = guardian_get_or_create_cron_secret();
    if ($existed_secret === '') {
        $msg[] = "[OK] cron 인증 토큰 생성 완료 (관리자 → 환경설정에서 확인/변경 가능)";
    } else {
        $msg[] = "[SKIP] cron 인증 토큰 이미 존재";
    }
}

// ---------------------------------------------------------------------
// 3. 환경 점검
// ---------------------------------------------------------------------
$msg[] = '';
$msg[] = '== 환경 점검 ==';
$msg[] = "PHP 버전: " . PHP_VERSION;
$msg[] = "그누보드 버전: " . (defined('G5_GNUBOARD_VER') ? G5_GNUBOARD_VER : 'unknown');
$msg[] = "DB 캐릭터셋: " . $charset;
$msg[] = "테이블 접두사: " . G5_TABLE_PREFIX;
$msg[] = "mbstring 확장: " . (extension_loaded('mbstring')
    ? '[OK] 로드됨'
    : '[WARN] 미설치 — 한글 메시지 길이 제한이 정상 동작하지 않을 수 있음');
// 신규 설치 직후 시점에는 inject 레이어가 본 요청 \$config 에 반영되지 않으므로
// guardian_config 직접 조회로 활성화 상태를 가져온다.
$is_active_now = !empty($config['cf_guardian_use']) || !empty(guardian_config_get('use', false));
$msg[] = "현재 운영지킴이 활성화 상태: " . ($is_active_now
    ? '[ON]'
    : '[OFF] (관리자 → 환경설정 → "운영지킴이 사용" 체크박스를 ON 후 저장)');

// ---------------------------------------------------------------------
// 4. 결과 출력
// ---------------------------------------------------------------------
header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>그누보드 운영지킴이 v1.1 설치 — 그누보드5/영카트5 운영 진단키트</title>
<style>
body { font-family: 'Apple SD Gothic Neo', sans-serif; max-width: 720px; margin: 30px auto; padding: 0 15px; color: #222; line-height: 1.6; }
h2 { border-bottom: 2px solid #333; padding-bottom: 8px; }
ul { list-style: none; padding: 0; }
li { padding: 6px 10px; border-bottom: 1px solid #eee; font-size: 14px; }
li:empty { border: 0; height: 6px; }
.note { background: #f6f8fa; padding: 12px 16px; border-left: 4px solid #0366d6; margin-top: 20px; font-size: 13px; }
</style>
</head>
<body>
<h2>그누보드 운영지킴이 v1.1 설치 결과 — 그누보드5/영카트5 운영 진단키트</h2>
<ul>
<?php foreach ($msg as $m) {
    if ($m === '') { echo "<li></li>"; continue; }
    echo '<li>' . get_text($m) . '</li>';
} ?>
</ul>
<?php if ($has_legacy_columns) { ?>
<div class="note" style="background:#fef3c7; border-left-color:#fbbf24; color:#78350f;">
    📦 <strong>v1.0 → v1.1 업그레이드 감지됨.</strong><br>
    기존 <code>g5_config.cf_guardian_*</code> 컬럼 값이 새 <code>guardian_config</code> 테이블로 이전되었습니다.
    안전을 위해 g5_config 의 기존 컬럼은 <strong>아직 삭제하지 않았습니다</strong>.
    며칠간 운영지킴이가 정상 동작하는지 확인하신 후 <code>cleanup.php</code> 를 실행해 정리하시기 바랍니다.<br><br>
    <a href="./cleanup.php" style="display:inline-block; padding:8px 16px; background:#92400e; color:#fff; text-decoration:none; border-radius:4px; font-size:13px;">
        cleanup.php 실행 페이지로
    </a>
</div>
<?php } else { ?>
<div class="note" style="background:#f0fdf4; border-left-color:#16a34a; color:#15803d;">
    ✅ <strong>신규 설치가 완료되었습니다.</strong><br>
    <code>cleanup.php</code> 는 <strong>실행할 필요가 없습니다</strong> (마이그레이션할 기존 데이터가 없음).
    원하시면 <code>cleanup.php</code> 파일을 미리 삭제하셔도 됩니다.
</div>
<?php } ?>
<div class="note">
    💡 <code>install.php</code> 는 안전하게 보관하세요. 재실행해도 안전하며 (모든 ALTER 사전 체크), 향후 업그레이드 시에도 다시 실행해 주시면 됩니다.
</div>
</body>
</html>
