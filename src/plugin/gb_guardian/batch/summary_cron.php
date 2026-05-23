<?php
/**
 * 그누보드 운영지킴이 - 일일/주간 요약 발송 cron 스크립트
 *
 * crontab 등록 예시 (5~10분 간격 권장):
 *   #/10 * * * * /usr/bin/php /var/www/g5/plugin/gb_guardian/batch/summary_cron.php
 *
 * 또는 웹 cron 서비스 사용 시:
 *   https://yoursite.com/plugin/gb_guardian/batch/summary_cron.php?secret=KEY
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */

// CLI 또는 웹 secret 인증
$is_cli = (PHP_SAPI === 'cli');
if (!$is_cli) {
    // 웹 호출 시 — 인증 토큰 검증 (config 로드 전 1차 입력 체크)
    $secret = isset($_GET['secret']) ? (string)$_GET['secret'] : '';
    if ($secret === '') {
        http_response_code(403);
        die('CLI 또는 secret 파라미터 필요');
    }
}

// 그누보드 환경 로드 — common.php 까지 거슬러 올라가기
chdir(__DIR__ . '/../../..');  // batch → gb_guardian → plugin → 그누보드 루트
include_once('./common.php');

if (!defined('_GNUBOARD_')) {
    fwrite(STDERR, "Failed to load gnuboard common.php\n");
    exit(1);
}

// 웹 호출 시 secret 본 검증 (config 로드 후)
//   - 운영지킴이 v1.1+ : guardian_config.cron_secret 사용 (랜덤 토큰)
//   - 구 sha1(cf_admin_email) 방식은 폐기 (메일 주소만 알면 추론 가능했음)
if (!$is_cli) {
    require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_config.lib.php';
    $expected = function_exists('guardian_config_get')
        ? (string)guardian_config_get('cron_secret', '')
        : '';

    if ($expected === '') {
        http_response_code(403);
        die('cron 토큰이 설정되지 않았습니다. 관리자 → 환경설정 페이지를 한 번 방문해 주세요.');
    }
    // hash_equals 가 있으면 타이밍 공격 방어 (PHP 5.6+ 표준)
    $ok = function_exists('hash_equals')
        ? hash_equals($expected, $secret)
        : ($expected === $secret);
    if (!$ok) {
        http_response_code(403);
        die('Invalid secret');
    }
    header('Content-Type: text/plain; charset=UTF-8');
}

// 운영지킴이 활성 / 비상정지 체크
if (empty($config['cf_guardian_use'])) {
    echo "[" . date('Y-m-d H:i:s') . "] 운영지킴이 비활성 — skip\n";
    exit(0);
}
if (!empty($config['cf_guardian_emergency_stop'])) {
    echo "[" . date('Y-m-d H:i:s') . "] 비상 정지 활성 — skip\n";
    exit(0);
}

// 필요 라이브러리 로드 (cron 환경에서는 extend.php 가 활성 상태로 이미 로드했어야 정상이지만,
// summary_mode='cron' 등으로 visitor 트리거를 끈 경우에도 동작하도록 명시 require)
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian.lib.php';
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_db.lib.php';
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_protector.lib.php';
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_template.lib.php';
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_mailer.lib.php';
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_aligo_sms.lib.php';
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_aligo_kakao.lib.php';
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_notifier.lib.php';
require_once G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_summary.lib.php';

// 시간 보호 (요약 발송이 길어질 수 있음)
@set_time_limit(300);
@ignore_user_abort(true);

echo "[" . date('Y-m-d H:i:s') . "] daily summary check...\n";
try {
    guardian_process_daily_summaries();
} catch (Exception $e) {
    echo "[ERROR] daily: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] weekly summary check...\n";
try {
    guardian_process_weekly_summaries();
} catch (Exception $e) {
    echo "[ERROR] weekly: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] cleanup old data...\n";
try {
    guardian_cleanup_old_data();
} catch (Exception $e) {
    echo "[ERROR] cleanup: " . $e->getMessage() . "\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
