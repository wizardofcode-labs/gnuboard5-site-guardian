<?php
/**
 * 그누보드 운영지킴이 - g5_config 컬럼 정리 스크립트 (일회성)
 *
 * v1.0 → v1.1 업그레이드 시 install.php 가 g5_config.cf_guardian_* 컬럼을
 * 새 guardian_config 테이블로 마이그레이션한다. 본 스크립트는 마이그레이션
 * 정상 동작을 며칠 확인한 후 사용자가 g5_config 의 cf_guardian_* 컬럼을
 * 삭제하고 싶을 때 실행하는 일회성 스크립트.
 *
 * 안전 장치:
 *   1. 관리자 권한 검사
 *   2. 마이그레이션 완료 마커 확인 (없으면 중단)
 *   3. 이중 컨펌 (?confirm=yes)
 *   4. .cleanup_done 마커 파일 생성 (재실행 방지)
 *
 * 처음 v1.1 신규 설치자는 본 스크립트를 실행할 필요가 없다 (install.php 가
 * 마이그레이션 단계를 자동으로 SKIP).
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.1.0
 */
include_once('../../common.php');

if (!defined('_GNUBOARD_')) exit;

if (!$is_admin) {
    alert('관리자만 접근 가능합니다.');
}

// =====================================================================
// 0. 재실행 방지 — .cleanup_done 마커 파일 존재 시 즉시 종료
// =====================================================================
$flag_path = __DIR__ . '/.cleanup_done';
if (@file_exists($flag_path)) {
    $done_at = trim((string)@file_get_contents($flag_path));
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="ko"><head><meta charset="UTF-8"><title>운영지킴이 cleanup</title></head>';
    echo '<body style="font-family:\'Apple SD Gothic Neo\',sans-serif; max-width:720px; margin:30px auto; padding:0 15px; color:#222; line-height:1.6;">';
    echo '<h2>이미 정리 완료됨</h2>';
    echo '<div style="background:#f0fdf4; border:1px solid #16a34a; padding:14px 18px; border-radius:6px;">';
    echo '운영지킴이 cleanup 이 ' . htmlspecialchars($done_at, ENT_QUOTES, 'UTF-8') . ' 에 이미 완료되었습니다.<br>';
    echo '본 파일(<code>cleanup.php</code>)은 더 이상 사용할 일이 없으므로 FTP 로 삭제하시는 것을 권장합니다.';
    echo '</div></body></html>';
    exit;
}

// =====================================================================
// 1. guardian_config.lib.php 로드 + 마이그레이션 마커 확인
// =====================================================================
require_once __DIR__ . '/lib/guardian_config.lib.php';

$migrated = guardian_config_get('_migrated_from_g5_config', '');
if (empty($migrated)) {
    alert('마이그레이션이 아직 완료되지 않았습니다. 먼저 install.php 를 실행해 주세요.', './install.php');
}

// =====================================================================
// 2. 컨펌 체크
// =====================================================================
$confirm = isset($_GET['confirm']) ? (string)$_GET['confirm'] : '';
if ($confirm !== 'yes') {
    header('Content-Type: text/html; charset=UTF-8');
    ?><!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>운영지킴이 cleanup</title>
<style>
body { font-family: 'Apple SD Gothic Neo', sans-serif; max-width: 720px; margin: 30px auto; padding: 0 15px; color: #222; line-height: 1.6; }
h2 { border-bottom: 2px solid #333; padding-bottom: 8px; }
.warn { background:#fff3cd; border:1px solid #ffc107; padding:14px 18px; border-radius:6px; margin:14px 0; }
.danger { background:#fee; border:2px solid #c33; padding:14px 18px; border-radius:6px; margin:14px 0; color:#991b1b; }
.btn { display:inline-block; padding:10px 18px; background:#dc3545; color:#fff; text-decoration:none; border-radius:6px; font-weight:bold; }
.btn:hover { background:#c82333; }
.btn-cancel { background:#6c757d; margin-left:10px; }
ul { margin:8px 0 8px 24px; }
li { font-size: 13px; }
code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
</style>
</head>
<body>
<h2>🗑️ g5_config 정리 스크립트</h2>

<p>v1.0 → v1.1 마이그레이션이 완료되었습니다 (<?php echo htmlspecialchars($migrated, ENT_QUOTES, 'UTF-8'); ?>).</p>

<div class="warn">
<strong>⚠️ 본 스크립트는 g5_config 테이블의 다음 컬럼을 모두 삭제합니다.</strong>
<ul>
    <li>cf_guardian_use, cf_guardian_collect_levels, cf_guardian_log_keep_days</li>
    <li>cf_guardian_default_cooldown_min, cf_guardian_aligo_enabled</li>
    <li>cf_guardian_emergency_stop, cf_guardian_email_daily_limit</li>
    <li>cf_guardian_sms_daily_limit, cf_guardian_kakao_daily_limit</li>
    <li>cf_guardian_sms_silent_enabled, cf_guardian_sms_silent_start, cf_guardian_sms_silent_end</li>
    <li>cf_guardian_kakao_template_code, cf_guardian_kakao_emphasize_title</li>
    <li>cf_guardian_rule_match_logging, cf_guardian_rule_match_keep_days</li>
    <li>cf_guardian_summary_mode, cf_guardian_summary_last_daily, cf_guardian_summary_last_weekly</li>
</ul>
</div>

<div class="danger">
<strong>실행 전 다음을 반드시 확인하세요</strong>
<ul>
    <li><strong>g5_config 테이블을 백업했는지</strong> 확인</li>
    <li>운영지킴이가 며칠간 정상 동작했는지 확인 (오류 캡처 / 알림 발송)</li>
    <li>guardian_config 테이블에 모든 설정이 정상 마이그레이션됐는지 확인</li>
</ul>
</div>

<p style="margin-top:24px;">
    <a href="?confirm=yes" class="btn">⚠ 확인하고 실행</a>
    <a href="<?php echo G5_ADMIN_URL; ?>/guardian_config.php" class="btn btn-cancel">취소 (환경설정으로)</a>
</p>

<p style="margin-top:30px; font-size:12px; color:#888;">
    실행 후에는 <code>.cleanup_done</code> 마커 파일이 생성되어 재실행이 차단됩니다.
    안전을 위해 본 <code>cleanup.php</code> 파일을 FTP 로 삭제하시는 것도 권장합니다.
</p>
</body>
</html>
<?php
    exit;
}

// =====================================================================
// 3. 컬럼 삭제 실행
// =====================================================================
$columns_to_drop = array(
    'cf_guardian_use', 'cf_guardian_collect_levels', 'cf_guardian_log_keep_days',
    'cf_guardian_default_cooldown_min', 'cf_guardian_aligo_enabled',
    'cf_guardian_emergency_stop', 'cf_guardian_email_daily_limit',
    'cf_guardian_sms_daily_limit', 'cf_guardian_kakao_daily_limit',
    'cf_guardian_sms_silent_enabled', 'cf_guardian_sms_silent_start',
    'cf_guardian_sms_silent_end', 'cf_guardian_kakao_template_code',
    'cf_guardian_kakao_emphasize_title', 'cf_guardian_rule_match_logging',
    'cf_guardian_rule_match_keep_days', 'cf_guardian_summary_mode',
    'cf_guardian_summary_last_daily', 'cf_guardian_summary_last_weekly',
);

$config_table = !empty($g5['config_table']) ? $g5['config_table'] : (G5_TABLE_PREFIX . 'config');
$msg = array();
$dropped = 0;

foreach ($columns_to_drop as $col) {
    $col_esc = sql_escape_string($col);
    $check = sql_fetch(" SHOW COLUMNS FROM `{$config_table}` LIKE '{$col_esc}' ", false);
    if (!empty($check)) {
        @sql_query(" ALTER TABLE `{$config_table}` DROP COLUMN `{$col}` ", false);
        $msg[] = "[OK] {$col} 삭제됨";
        $dropped++;
    } else {
        $msg[] = "[SKIP] {$col} 컬럼 이미 없음";
    }
}

// 마커 파일 생성 (재실행 방지)
@file_put_contents($flag_path, date('Y-m-d H:i:s'));

// =====================================================================
// 4. 결과 페이지 출력
// =====================================================================
header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<title>운영지킴이 cleanup 완료</title>
<style>
body { font-family: 'Apple SD Gothic Neo', sans-serif; max-width: 720px; margin: 30px auto; padding: 0 15px; color: #222; line-height: 1.6; }
h2 { border-bottom: 2px solid #333; padding-bottom: 8px; }
.ok { background:#f0fdf4; border:2px solid #16a34a; padding:14px 18px; border-radius:6px; margin:14px 0; color:#15803d; }
ul { list-style: none; padding: 0; }
li { padding: 4px 0; font-size: 13px; border-bottom: 1px solid #eee; }
.note { background:#eff6ff; border-left:4px solid #3b82f6; padding:12px 16px; margin-top:20px; font-size:13px; color:#1e3a8a; }
</style>
</head>
<body>
<h2>✅ g5_config 정리 완료</h2>

<div class="ok">
<strong><?php echo (int)$dropped; ?>개 컬럼 삭제 완료.</strong><br>
운영지킴이는 이제 별도 <code>guardian_config</code> 테이블만 사용합니다.
</div>

<ul>
<?php foreach ($msg as $m) {
    echo '<li>' . htmlspecialchars($m, ENT_QUOTES, 'UTF-8') . '</li>';
} ?>
</ul>

<div class="note">
💡 <strong>cleanup.php 파일을 삭제하시기를 권장합니다.</strong><br>
FTP 로 접속해 <code>plugin/gb_guardian/cleanup.php</code> 파일을 삭제하세요. 삭제하지 않으셔도 보안 위험은 없지만 (마커 파일로 재실행 차단), 더 이상 사용할 일이 없는 파일입니다.
</div>

<p style="margin-top:20px;">
<a href="<?php echo G5_ADMIN_URL; ?>/guardian_config.php" style="display:inline-block; padding:8px 16px; background:#0f3460; color:#fff; text-decoration:none; border-radius:4px;">환경설정 페이지로</a>
</p>
</body>
</html>
