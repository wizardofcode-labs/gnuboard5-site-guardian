<?php
/**
 * 그누보드 운영지킴이 - 환경설정 화면
 *
 * cf_guardian_* 컬럼 5개 편집:
 *   - cf_guardian_use                  : 운영지킴이 활성화
 *   - cf_guardian_collect_levels       : 수집 등급 (CSV)
 *   - cf_guardian_log_keep_days        : 로그 보관 기간(일)
 *   - cf_guardian_default_cooldown_min : 기본 디바운싱 쿨다운(분)
 *   - cf_guardian_aligo_enabled        : 알리고 SMS 연동 토글
 *
 * 알리고 미설치 환경에서 별도 안내 노출 (SMS / 카카오 알림톡 발송에는
 * 알리고 SMS / 카카오 알림톡 솔루션이 필요).
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700900";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_admin.lib.php');
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_admin.lib.php');
}

// 기본 설정값 로드
$cfg = array(
    'use'         => !empty($config['cf_guardian_use']) ? 1 : 0,
    'levels'      => isset($config['cf_guardian_collect_levels']) && $config['cf_guardian_collect_levels'] !== ''
                       ? (string)$config['cf_guardian_collect_levels']
                       : 'FATAL,ERROR,WARNING,EXCEPTION,DB',
    'keep_days'   => isset($config['cf_guardian_log_keep_days']) && $config['cf_guardian_log_keep_days'] !== ''
                       ? (int)$config['cf_guardian_log_keep_days']
                       : 30,
    'cooldown'    => isset($config['cf_guardian_default_cooldown_min']) && $config['cf_guardian_default_cooldown_min'] !== ''
                       ? (int)$config['cf_guardian_default_cooldown_min']
                       : 30,
    'aligo'       => !empty($config['cf_guardian_aligo_enabled']) ? 1 : 0,
);

// 발송 보호 설정값 로드
$cfg3 = array(
    'emergency_stop'     => !empty($config['cf_guardian_emergency_stop']) ? 1 : 0,
    'email_daily_limit'  => isset($config['cf_guardian_email_daily_limit']) && $config['cf_guardian_email_daily_limit'] !== ''
                              ? (int)$config['cf_guardian_email_daily_limit'] : 500,
    'sms_daily_limit'    => isset($config['cf_guardian_sms_daily_limit']) && $config['cf_guardian_sms_daily_limit'] !== ''
                              ? (int)$config['cf_guardian_sms_daily_limit'] : 50,
    'kakao_daily_limit'  => isset($config['cf_guardian_kakao_daily_limit']) && $config['cf_guardian_kakao_daily_limit'] !== ''
                              ? (int)$config['cf_guardian_kakao_daily_limit'] : 100,
    'sms_silent'         => !empty($config['cf_guardian_sms_silent_enabled']) ? 1 : 0,
    'sms_silent_start'   => isset($config['cf_guardian_sms_silent_start']) && $config['cf_guardian_sms_silent_start'] !== ''
                              ? (int)$config['cf_guardian_sms_silent_start'] : 22,
    'sms_silent_end'     => isset($config['cf_guardian_sms_silent_end']) && $config['cf_guardian_sms_silent_end'] !== ''
                              ? (int)$config['cf_guardian_sms_silent_end'] : 8,
    'summary_mode'       => isset($config['cf_guardian_summary_mode']) && $config['cf_guardian_summary_mode'] !== ''
                              ? (string)$config['cf_guardian_summary_mode'] : 'visitor',
    'last_daily'         => isset($config['cf_guardian_summary_last_daily'])  ? (string)$config['cf_guardian_summary_last_daily']  : '',
    'last_weekly'        => isset($config['cf_guardian_summary_last_weekly']) ? (string)$config['cf_guardian_summary_last_weekly'] : '',
    'match_logging'      => !empty($config['cf_guardian_rule_match_logging']) ? 1 : 0,
    'match_keep_days'    => isset($config['cf_guardian_rule_match_keep_days']) && $config['cf_guardian_rule_match_keep_days'] !== ''
                              ? (int)$config['cf_guardian_rule_match_keep_days'] : 7,
    'kakao_tpl_code'     => isset($config['cf_guardian_kakao_template_code']) ? (string)$config['cf_guardian_kakao_template_code'] : '',
    'kakao_emphasize'    => isset($config['cf_guardian_kakao_emphasize_title']) ? (string)$config['cf_guardian_kakao_emphasize_title'] : '',
);

// 수집 등급을 배열로
$selected_levels = array_map('trim', explode(',', $cfg['levels']));
$selected_levels = array_map('strtoupper', $selected_levels);
$selected_levels = array_flip($selected_levels);

$all_levels = array(
    'FATAL'      => 'FATAL — 치명적 오류 (사이트 다운 위험)',
    'ERROR'      => 'ERROR — 일반 오류',
    'EXCEPTION'  => 'EXCEPTION — 처리되지 않은 예외',
    'WARNING'    => 'WARNING — 경고 (운영 영향 있음)',
    'DB'         => 'DB — 쿼리 오류',
    'NOTICE'     => 'NOTICE — 알림 (노이즈 가능성)',
    'DEPRECATED' => 'DEPRECATED — 폐기 예정 (주로 PHP 8 업그레이드 시)',
);

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

// 카카오 알림톡 권장 템플릿 — 실제 파일을 읽어 화면에 노출. 운영자가 파일을
// 편집하면 본 화면 안내도 자동으로 동기화된다 (단일 진실 원천).
$kakao_tpl_path = (defined('GUARDIAN_PATH') ? GUARDIAN_PATH : G5_PLUGIN_PATH . '/gb_guardian')
                . '/templates/kakao_default.txt';
$kakao_tpl_body = '';
$kakao_tpl_loaded = false;
if (@is_readable($kakao_tpl_path)) {
    $raw = @file_get_contents($kakao_tpl_path);
    if ($raw !== false) {
        // BOM 제거 + 양끝 줄바꿈 정리 (가독성)
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string)$raw);
        $kakao_tpl_body = rtrim((string)$raw, "\r\n");
        $kakao_tpl_loaded = ($kakao_tpl_body !== '');
    }
}
// 폴백 — 파일 읽기 실패 시 인라인 기본 (인라인 템플릿 함수와 동일 본문)
if (!$kakao_tpl_loaded) {
    $kakao_tpl_body = "[#{site_name}] 사이트 오류 알림\n\n"
                    . "#{error_level} 등급 오류가 발생했습니다.\n"
                    . "오류: #{error_message}\n"
                    . "파일: #{error_file}\n"
                    . "시각: #{error_time}\n\n"
                    . "상세 내용은 관리자 페이지에서 확인하시기 바랍니다.";
}

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 환경설정';
include_once(G5_ADMIN_PATH . '/admin.head.php');

$aligo_installed = guardian_is_aligo_installed();
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}
/* 토글 가이드 섹션 */
details > summary{outline:none;}
details > summary::-webkit-details-marker{display:none;}
details > summary:hover{background:rgba(0,0,0,0.04);}
details:not([open]) > summary .guardian-toggle-icon::after{content:"▼ 펼치기";font-size:12px;}
details[open] > summary .guardian-toggle-icon::after{content:"▲ 접기";font-size:12px;}
</style>

<?php if ($msg !== '') { ?>
<div class="local_desc01 local_desc" style="background:#e8f5e9; color:#2e7d32; border-left:4px solid #2e7d32;">
    <?php echo get_text($msg); ?>
</div>
<?php } ?>

<div style="display:flex; align-items:center; justify-content:space-between; gap:14px; background:#eef6ff; border:1px solid #b6d4ee; border-radius:8px; padding:14px 18px; margin:14px 0 22px 0;">
    <div style="font-size:13px; color:#0d47a1; line-height:1.6;">
        <strong>🧪 설치 테스트</strong> — 운영지킴이가 정상적으로 오류를 캡처하고 알림을 발송하는지 1분 안에 확인할 수 있습니다.
        강제로 경고/예외를 발생시킨 후 오류 로그·매칭 추적 로그·알림 발송 이력 화면에서 결과를 검증하세요.
    </div>
    <a href="./guardian_test_warning.php" class="btn btn_01" style="white-space:nowrap; flex-shrink:0;">테스트 페이지 열기 →</a>
</div>

<form name="guardian_config_form" method="post" action="./guardian_config_update.php" autocomplete="off">
<input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">

<section id="anc_guardian_basic">
<h2 class="h2_frm">기본 설정</h2>
<div class="tbl_frm01 tbl_wrap">
<table>
<caption>운영지킴이 기본 설정</caption>
<colgroup>
    <col class="grid_4">
    <col>
</colgroup>
<tbody>
    <tr>
        <th scope="row"><label for="guardian_use">운영지킴이 사용</label></th>
        <td>
            <input type="checkbox" name="guardian_use" id="guardian_use" value="1" <?php echo $cfg['use'] ? 'checked' : ''; ?>>
            <label for="guardian_use" style="margin-left:6px;">활성화</label>
            <p style="margin-top:6px; color:#888; font-size:12px;">
                비활성 상태에서는 운영지킴이 라이브러리 자체가 로드되지 않아
                사이트 성능에 영향을 주지 않습니다.
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row">수집 등급</th>
        <td>
            <?php foreach ($all_levels as $lv => $label) {
                $checked = isset($selected_levels[$lv]) ? 'checked' : '';
                $id = 'lv_' . strtolower($lv);
            ?>
            <div style="margin:4px 0;">
                <input type="checkbox" name="collect_levels[]" id="<?php echo $id; ?>" value="<?php echo get_text($lv); ?>" <?php echo $checked; ?>>
                <label for="<?php echo $id; ?>" style="margin-left:6px;"><?php echo get_text($label); ?></label>
            </div>
            <?php } ?>
            <p style="margin-top:8px; color:#888; font-size:12px;">
                NOTICE / DEPRECATED 는 노이즈가 많을 수 있어 기본 비활성화 권장.
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row"><label for="keep_days">로그 보관 기간</label></th>
        <td>
            <input type="number" name="keep_days" id="keep_days" min="1" max="365" value="<?php echo (int)$cfg['keep_days']; ?>" class="frm_input" style="width:100px;">
            일
            <p style="margin-top:6px; color:#888; font-size:12px; line-height:1.6;">
                <strong>"해결됨" 으로 표시된 오류 로그</strong> 가 본 기간을 지나면 자동 삭제됩니다.<br>
                정리 시점은 별도 cron 이 아닌 <strong>요약 cron 또는 방문자 트리거</strong> 가 동작할 때 함께 실행됩니다 (위 "요약 발송 트리거 모드" 설정과 동일).<br>
                <span style="color:#c63;">⚠️ 미해결 (resolved=0) 로그는 보관 기간이 지나도 자동 삭제되지 않습니다.</span>
                오류 처리 후 로그 화면에서 "해결됨 토글" 을 눌러주셔야 정리 대상이 됩니다.
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row"><label for="cooldown_min">기본 디바운싱 쿨다운</label></th>
        <td>
            <input type="number" name="cooldown_min" id="cooldown_min" min="1" max="1440" value="<?php echo (int)$cfg['cooldown']; ?>" class="frm_input" style="width:100px;">
            분
            <p style="margin-top:6px; color:#888; font-size:12px;">
                동일 오류가 이 시간 내에 반복 발생하면 카운트만 +1 되고 새 row 는 만들지 않습니다.
                <strong>SMS 비용 폭탄 방지의 핵심 설정입니다.</strong>
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row"><label for="aligo_enabled">알리고 SMS 연동</label></th>
        <td>
            <input type="checkbox" name="aligo_enabled" id="aligo_enabled" value="1" <?php echo $cfg['aligo'] ? 'checked' : ''; ?> <?php echo $aligo_installed ? '' : 'disabled'; ?>>
            <label for="aligo_enabled" style="margin-left:6px;">사용</label>
            <?php if ($aligo_installed) { ?>
                <span style="color:#2e7d32; margin-left:10px;">✓ 알리고 솔루션 감지됨</span>
            <?php } else { ?>
                <span style="color:#c63; margin-left:10px;">⚠ 알리고 솔루션 미설치</span>
            <?php } ?>
        </td>
    </tr>
</tbody>
</table>
</div>
</section>

<?php if (!$aligo_installed) { ?>
<div class="local_desc01 local_desc" style="background:#fff3e0; border-left:4px solid #f57c00; color:#5d4037; margin-top:20px;">
    <strong>⚠️ SMS / 카카오 알림톡을 사용하려면 알리고 SMS / 카카오 솔루션이 필요합니다.</strong><br>
    <span style="font-size:13px;">
        운영지킴이는 자체 SMS·알림톡 발송 기능을 포함하지 않으며, 외부 알리고 솔루션의 클래스를 통해 발송합니다.
        솔루션이 설치된 환경이라면 본 페이지를 새로고침하면 자동으로 감지됩니다.
        설치 지원이나 문의는 <strong>아래 "제작자 정보"</strong> 의 카카오톡 채널로 남겨주세요.
    </span>
</div>
<?php } ?>

<!-- 알림 발송 보호 -->
<section id="anc_guardian_notify" style="margin-top:30px;">
<h2 class="h2_frm">🛡️ 알림 발송 보호 설정</h2>

<!-- 비상 정지 (가장 위, 빨간 박스) -->
<div style="background:#fee; border:2px solid #c33; padding:18px 20px; border-radius:8px; margin:15px 0;">
    <h3 style="color:#c33; margin:0 0 10px 0; font-size:16px;">🚨 비상 정지</h3>
    <p style="font-size:13px; color:#666; margin:0 0 10px 0;">
        의심스러운 발송 폭주가 감지되면 즉시 활성화하세요. <strong>모든 채널의 알림이 즉시 중지됩니다.</strong>
    </p>
    <label style="font-size:14px;">
        <input type="checkbox" name="emergency_stop" value="1" <?php echo $cfg3['emergency_stop'] ? 'checked' : ''; ?>>
        <strong style="color:#c33;">모든 알림 발송 즉시 중지</strong>
    </label>
</div>

<div class="tbl_frm01 tbl_wrap">
<table>
<colgroup><col class="grid_4"><col></colgroup>
<tbody>
    <tr>
        <th scope="row"><label for="email_daily_limit">이메일 일일 한도</label></th>
        <td>
            <input type="number" name="email_daily_limit" id="email_daily_limit" min="0" max="10000"
                   value="<?php echo (int)$cfg3['email_daily_limit']; ?>" class="frm_input" style="width:100px;">
            건/일
            <span style="color:#888; font-size:12px;">(0 = 차단)</span>
        </td>
    </tr>
    <tr>
        <th scope="row">
            <label for="sms_daily_limit">SMS 일일 한도</label>
            <span style="color:#c63; font-size:11px; display:block;">★ 비용 발생</span>
        </th>
        <td>
            <input type="number" name="sms_daily_limit" id="sms_daily_limit" min="0" max="1000"
                   value="<?php echo (int)$cfg3['sms_daily_limit']; ?>" class="frm_input" style="width:100px;">
            건/일
            <p style="color:#c63; font-size:12px; margin-top:5px;">
                ⚠️ SMS 는 건당 비용 발생. <strong>너무 높게 설정하면 알리고 잔액 폭주 위험</strong>이 있습니다. 50건 이하 권장.
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="kakao_daily_limit">카톡 일일 한도</label></th>
        <td>
            <input type="number" name="kakao_daily_limit" id="kakao_daily_limit" min="0" max="2000"
                   value="<?php echo (int)$cfg3['kakao_daily_limit']; ?>" class="frm_input" style="width:100px;">
            건/일
            <span style="color:#888; font-size:12px;">(0 = 차단)</span>
        </td>
    </tr>

    <tr>
        <th scope="row">야간 무음 시간 (SMS / 카톡)</th>
        <td>
            <label style="margin-right:15px;">
                <input type="checkbox" name="sms_silent_enabled" value="1" <?php echo $cfg3['sms_silent'] ? 'checked' : ''; ?>>
                활성화
            </label>
            <input type="number" name="sms_silent_start" min="0" max="23"
                   value="<?php echo (int)$cfg3['sms_silent_start']; ?>" class="frm_input" style="width:60px;">시 ~
            <input type="number" name="sms_silent_end" min="0" max="23"
                   value="<?php echo (int)$cfg3['sms_silent_end']; ?>" class="frm_input" style="width:60px;">시
            <p style="color:#888; font-size:12px; margin-top:5px;">
                이 시간대에는 SMS / 카톡이 차단됩니다 (메일은 정상). 22~08 처럼 자정 걸치는 범위도 가능.
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row">요약 발송 트리거 모드</th>
        <td>
            <label style="margin-right:18px;">
                <input type="radio" name="summary_mode" value="visitor" <?php echo $cfg3['summary_mode'] === 'visitor' ? 'checked' : ''; ?>>
                <strong>방문자 트리거</strong> <span style="color:#666; font-size:12px;">(기본 — 호스팅 cron 불가 환경)</span>
            </label>
            <label>
                <input type="radio" name="summary_mode" value="cron" <?php echo $cfg3['summary_mode'] === 'cron' ? 'checked' : ''; ?>>
                <strong>cron 직접 등록</strong> <span style="color:#666; font-size:12px;">(권장 — cron 사용 가능 환경)</span>
            </label>
            <p style="color:#888; font-size:12px; margin-top:6px;">
                일일/주간 요약 메일을 누가 트리거할지 결정합니다. 즉시 알림(instant) 은 본 설정과 무관하게 오류 발생 시 곧바로 발송됩니다.
            </p>
            <?php
            // 마지막 처리 시각 표시
            $last_d = $cfg3['last_daily'];
            $last_w = $cfg3['last_weekly'];
            if ($last_d || $last_w) {
            ?>
            <p style="color:#666; font-size:11px; margin-top:6px;">
                마지막 처리:
                일일=<?php echo $last_d ? get_text($last_d) : '없음'; ?>
                · 주간=<?php echo $last_w ? get_text($last_w) : '없음'; ?>
            </p>
            <?php } ?>
        </td>
    </tr>

    <?php
    // cron 가이드 박스 — 토큰은 guardian_config 의 'cron_secret' 사용.
    // 첫 방문 시 자동 생성(메일 prefix 16자 + 랜덤 16자 hex). 사용자가 직접
    // 변경하거나 재생성 버튼으로 새 랜덤 토큰을 발급받을 수도 있다.
    $cron_secret = function_exists('guardian_get_or_create_cron_secret')
        ? guardian_get_or_create_cron_secret()
        : '';
    $cron_admin_email = !empty($config['cf_admin_email']) ? (string)$config['cf_admin_email'] : '';
    $cron_email_prefix = preg_replace('/[^A-Za-z0-9]/', '', $cron_admin_email);
    $cron_email_prefix = substr($cron_email_prefix, 0, 16);
    $cron_url = G5_URL . '/plugin/gb_guardian/batch/summary_cron.php?secret=' . urlencode($cron_secret);
    $cron_path = G5_PATH . '/plugin/gb_guardian/batch/summary_cron.php';
    ?>
    <tr>
        <th scope="row"><label for="cron_secret">cron 인증 토큰</label></th>
        <td>
            <input type="text" name="cron_secret" id="cron_secret" maxlength="64"
                   value="<?php echo get_text($cron_secret); ?>"
                   class="frm_input" style="width:340px; font-family:monospace;">
            <button type="button" onclick="guardianRegenerateCronSecret()" class="btn btn_03">🔄 랜덤 재생성</button>
            <p style="color:#888; font-size:12px; margin-top:6px; line-height:1.6;">
                웹 cron URL 의 <code>?secret=</code> 값입니다. 기본값은
                <strong>관리자 메일 앞 16자(영숫자) + 랜덤 16자 hex</strong> 로 자동 생성되며,
                직접 수정하셔도 됩니다 (영숫자/하이픈/언더스코어, 8~64자 권장).
                토큰을 비우고 저장하면 다음 환경설정 진입 시 자동 재생성됩니다.
            </p>
            <p style="color:#c63; font-size:12px; margin-top:4px;">
                ⚠️ 토큰을 변경하시면 등록한 외부 cron URL 도 새 값으로 갱신해야 합니다.
                토큰이 노출되면 외부에서 cron 을 임의 트리거할 수 있으니 비공개로 관리하세요.
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row" colspan="2" style="background:#e8f4fd; border-left:4px solid #1976d2; text-align:left; padding:0;">
            <details style="padding:0;">
                <summary style="cursor:pointer; padding:14px 16px; font-size:14px; color:#0d47a1; list-style:none; display:flex; justify-content:space-between; align-items:center; user-select:none;">
                    <span><strong>🕒 cron 등록 가이드 (선택)</strong></span>
                    <span class="guardian-toggle-icon" style="color:#1976d2;"></span>
                </summary>
                <div style="padding:0 16px 14px 16px;">
                    <div style="font-size:13px; color:#444; line-height:1.7; margin-bottom:12px;">
                        cron 모드를 선택하셨다면 호스팅 서버에 다음 중 한 가지 방법으로 5~10분 간격 작업을 등록하세요.
                        cron 이 없는 환경(저가 호스팅 등)에서는 <strong>방문자 트리거 모드</strong>를 그대로 사용하시면 됩니다.
                    </div>

                    <?php if ($cron_secret === '') { ?>
                        <div style="background:#fff3e0; border:1px solid #ffb74d; padding:10px 14px; border-radius:4px; margin-bottom:12px; font-size:12px; color:#e65100;">
                            ⚠️ cron 토큰이 비어있습니다. 위 "cron 인증 토큰" 입력란을 비우신 채로 저장하셨다면, 이 페이지를 새로고침하시면 자동으로 재생성됩니다.
                        </div>
                    <?php } else { ?>

                        <!-- 방법 1: 서버 crontab -->
                        <div style="margin-bottom:14px;">
                            <div style="font-size:12px; font-weight:bold; color:#333; margin-bottom:6px;">
                                ▼ 방법 1 — SSH 접속 가능한 서버 (crontab)
                            </div>
                            <div style="font-size:11px; color:#666; margin-bottom:4px;">
                                서버에 SSH 접속 후 <code>crontab -e</code> 명령으로 다음 한 줄을 추가:
                            </div>
<pre style="background:#fff; border:1px solid #b6d4ee; border-radius:4px; padding:10px 12px; font-size:12px; color:#1a1a2e; white-space:pre-wrap; word-break:break-all; margin:0;">*/10 * * * * /usr/bin/php <?php echo get_text($cron_path); ?></pre>
                            <div style="font-size:11px; color:#888; margin-top:4px;">
                                PHP 실행 경로 <code>/usr/bin/php</code> 는 호스팅마다 다를 수 있습니다 (<code>which php</code> 로 확인).
                            </div>
                        </div>

                        <!-- 방법 2: 웹 cron -->
                        <div style="margin-bottom:14px;">
                            <div style="font-size:12px; font-weight:bold; color:#333; margin-bottom:6px;">
                                ▼ 방법 2 — 외부 웹 cron 서비스 (cron-job.org, EasyCron 등)
                            </div>
                            <div style="font-size:11px; color:#666; margin-bottom:4px;">
                                외부 cron 서비스에 다음 URL 을 5~10분 간격으로 호출하도록 등록:
                            </div>
<pre style="background:#fff; border:1px solid #b6d4ee; border-radius:4px; padding:10px 12px; font-size:12px; color:#1a1a2e; white-space:pre-wrap; word-break:break-all; margin:0;"><?php echo get_text($cron_url); ?></pre>
                            <div style="font-size:11px; color:#888; margin-top:4px;">
                                ⚠️ <code>secret</code> 토큰은 위 "cron 인증 토큰" 입력란의 값입니다. 토큰을 변경하시면 cron 등록도 함께 갱신해야 합니다. 토큰이 노출되면 외부에서 cron 을 임의 트리거할 수 있으나, 본 스크립트는 발송 함수만 호출하므로 위험은 제한적입니다.
                            </div>
                        </div>

                    <?php } ?>

                    <div style="margin-top:14px; padding-top:10px; border-top:1px dashed #b6d4ee; font-size:11px; color:#666;">
                        <strong>💡 동작 확인</strong>
                        cron 등록 후 첫 실행이 일어나면 위 "마지막 처리" 시각이 갱신됩니다.
                        요약 메일이 도착하지 않더라도 발송할 큐가 비어있으면 메일을 보내지 않으므로
                        "마지막 처리" 시각만 갱신될 수 있습니다 — 정상 동작입니다.
                    </div>
                </div>
            </details>
        </th>
    </tr>

    <tr>
        <th scope="row">매칭 추적 로깅</th>
        <td>
            <label>
                <input type="checkbox" name="match_logging" value="1" <?php echo !empty($cfg3['match_logging']) ? 'checked' : ''; ?>>
                <strong>활성화</strong>
            </label>
            <p style="color:#888; font-size:12px; margin-top:5px;">
                매 매칭 시도를 <code>g5_guardian_rule_match_log</code> 테이블에 기록합니다.
                "왜 알림이 왔지/안 왔지?" 디버깅에 사용. OFF 로 두면 추적 로그 화면이 비어 보이지만 실제 발송 동작에는 영향이 없습니다.
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row"><label for="match_keep_days">자동 정리 보관 기간</label></th>
        <td>
            <input type="number" name="match_keep_days" id="match_keep_days" min="1" max="365"
                   value="<?php echo (int)$cfg3['match_keep_days']; ?>" class="frm_input" style="width:100px;">
            일
            <p style="color:#888; font-size:12px; margin-top:5px;">
                매칭 추적 로그 / 처리된 요약 큐의 자동 삭제 기준 (기본 7일).
                cron 또는 방문자 트리거가 동작할 때마다 본 기간을 지난 데이터를 자동 삭제합니다.
                짧을수록 DB 사용량이 작고, 길수록 디버깅 가능 기간이 길어집니다.
                <br>※ 해결됨 처리된 오류 로그는 별도 항목 <code>cf_guardian_log_keep_days</code>(기본 30일) 기준으로 정리됩니다.
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row"><label for="kakao_tpl_code">카톡 알림톡 템플릿 코드</label></th>
        <td>
            <input type="text" name="kakao_tpl_code" id="kakao_tpl_code" maxlength="50"
                   value="<?php echo get_text($cfg3['kakao_tpl_code']); ?>" class="frm_input" style="width:300px;">
            <p style="color:#888; font-size:12px; margin-top:5px;">
                알리고 카톡 관리자에서 등록·승인된 템플릿 코드. 비워두면 카톡 발송 비활성.
            </p>
        </td>
    </tr>

    <tr>
        <th scope="row"><label for="kakao_emphasize">카톡 강조 표기 (선택)</label></th>
        <td>
            <input type="text" name="kakao_emphasize" id="kakao_emphasize" maxlength="50"
                   value="<?php echo get_text($cfg3['kakao_emphasize']); ?>" class="frm_input" style="width:400px;"
                   placeholder='예: #{error_level} 등급 오류 발생'>
            <p style="color:#888; font-size:12px; margin-top:5px;">
                알림톡 상단 강조 영역에 표시될 짧은 제목. 변수 사용 가능 — 발송 시점에 실제 값으로 치환됩니다.
            </p>
            <div style="background:#f5f5f5; border:1px solid #e0e0e0; border-radius:4px; padding:8px 12px; margin-top:6px; font-size:11px; color:#555; line-height:1.6;">
                <strong>예시 입력</strong>: <code>#{error_level} 등급 오류 발생</code> → <span style="color:#0f3460;">"FATAL 등급 오류 발생"</span> 으로 치환되어 발송됩니다.<br>
                ※ 사용 가능한 변수 8종은 아래 <strong>"📌 본문 / 강조 표기에 사용 가능한 변수"</strong> 표를 참고하세요. 본문과 동일한 변수 모두 사용 가능합니다.
            </div>
        </td>
    </tr>

    <tr>
        <th scope="row" colspan="2" style="background:#fff8e1; border-left:4px solid #fbc02d; text-align:left; padding:0;">
            <details style="padding:0;">
                <summary style="cursor:pointer; padding:14px 16px; font-size:14px; color:#5d4037; list-style:none; display:flex; justify-content:space-between; align-items:center; user-select:none;">
                    <span><strong>📋 카카오 알림톡 사용 전 필수 안내</strong></span>
                    <span class="guardian-toggle-icon" style="color:#f9a825;"></span>
                </summary>
                <div style="padding:0 16px 14px 16px;">
                    <div style="font-size:13px; color:#444; line-height:1.7;">
                        카카오 알림톡은 <strong>카카오 비즈메시지 센터에 사전 등록·승인된 템플릿</strong>으로만 발송됩니다.
                        아래 본문을 그대로 카카오 비즈센터(또는 알리고 카톡 관리자) 에 등록하신 후, 발급받은
                        <code>템플릿 코드</code>를 위 입력란에 붙여 넣으세요.
                    </div>

                    <div style="margin-top:14px;">
                        <div style="font-size:12px; color:#666; margin-bottom:6px; display:flex; justify-content:space-between; align-items:center;">
                            <span>▼ <strong>카카오 비즈센터에 등록할 권장 템플릿 본문</strong> (변수는 <code>#{변수명}</code> 형식으로 작성)</span>
                            <span style="font-size:10px; color:#999;">
                                <?php if ($kakao_tpl_loaded) { ?>
                                    출처: <code>plugin/gb_guardian/templates/kakao_default.txt</code>
                                <?php } else { ?>
                                    <span style="color:#c63;">⚠ 파일 읽기 실패 — 폴백 본문 표시</span>
                                <?php } ?>
                            </span>
                        </div>
<pre style="background:#fff; border:1px solid #e0c97f; border-radius:4px; padding:12px 15px; font-size:12px; line-height:1.6; color:#333; white-space:pre-wrap; margin:0;"><?php echo get_text($kakao_tpl_body); ?></pre>
                        <div style="font-size:11px; color:#888; margin-top:6px;">
                            위 본문은 <code>plugin/gb_guardian/templates/kakao_default.txt</code> 의 실제 내용을 그대로 노출한 것입니다.
                            파일을 수정하시면 본 화면 표시도 자동으로 갱신됩니다.
                            운영지킴이가 발송할 때 변수가 실제 값으로 치환된 텍스트를 알리고에 전달하며, 알리고가 카카오 비즈센터의 등록 본문과 매칭하여 알림톡으로 전송합니다.
                        </div>
                    </div>

                    <!-- 사용 가능한 변수 목록 — 본문 / 강조 표기 모두에서 사용 가능 -->
                    <div style="margin-top:14px; background:#fff; border:1px solid #e0c97f; border-radius:4px; padding:12px 15px;">
                        <div style="font-size:12px; font-weight:bold; color:#5d4037; margin-bottom:8px;">
                            📌 본문 / 강조 표기에 사용 가능한 변수 (8종)
                        </div>
                        <div style="font-size:11px; color:#555; margin-bottom:8px; line-height:1.6;">
                            카카오 알림톡의 표준 변수 형식은 <code>#{변수명}</code> (소문자 + 샵 prefix) 입니다.
                            카카오 비즈메시지 센터 등록 본문 / 본 화면의 "카톡 강조 표기" / 본 권장 템플릿 모두 동일 형식을 사용하세요.<br>
                            <span style="color:#c63;">⚠️ 앞에 <code>#</code> 가 빠진 <code>{변수명}</code> 형식은 카카오에서 인식하지 않습니다.</span>
                        </div>
                        <table style="width:100%; border-collapse:collapse; font-size:11px;">
                            <thead>
                                <tr style="background:#fff8e1; color:#5d4037;">
                                    <th style="text-align:left; padding:6px 8px; border-bottom:1px solid #e0c97f; width:32%;">변수</th>
                                    <th style="text-align:left; padding:6px 8px; border-bottom:1px solid #e0c97f;">설명 / 예시</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;"><code>#{site_name}</code></td>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;">사이트 이름 (그누보드 <code>cf_title</code>) — 예: <em>K3SOFT 쇼핑몰</em></td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;"><code>#{error_level}</code></td>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;">오류 등급 — 예: <em>FATAL</em>, <em>ERROR</em>, <em>WARNING</em>, <em>EXCEPTION</em>, <em>DB</em>, <em>NOTICE</em>, <em>DEPRECATED</em></td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;"><code>#{error_message}</code></td>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;">오류 메시지 (마스킹 처리됨) — 예: <em>Undefined variable: foo</em></td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;"><code>#{error_file}</code></td>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;">오류 발생 파일 (절대경로 마스킹됨) — 예: <em>[ROOT]/bbs/login.php</em></td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;"><code>#{error_line}</code></td>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;">오류 발생 라인 번호 — 예: <em>42</em></td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;"><code>#{error_time}</code></td>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;">발생 시각 — 예: <em>2026-05-10 14:32:08</em></td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;"><code>#{error_url}</code></td>
                                    <td style="padding:5px 8px; border-bottom:1px solid #f3e5b8;">발생 페이지 URL (개인정보 마스킹됨) — 예: <em>/bbs/board.php?bo_table=free</em></td>
                                </tr>
                                <tr>
                                    <td style="padding:5px 8px;"><code>#{occurrence_count}</code></td>
                                    <td style="padding:5px 8px;">동일 오류 누적 발생 횟수 — 예: <em>3</em></td>
                                </tr>
                            </tbody>
                        </table>
                        <div style="font-size:11px; color:#888; margin-top:8px; padding-top:8px; border-top:1px dashed #e0c97f;">
                            💡 위 변수는 본 카카오 알림톡 본문 (<code>kakao_default.txt</code>) 과 위 "카톡 강조 표기" 입력란에서 모두 <code>#{변수명}</code> 형식으로 사용합니다.
                            메일 / SMS 본문 템플릿(<code>mail_default.html</code> / <code>sms_default.txt</code>)에서는 같은 변수가 <code>{ERROR_LEVEL}</code> 같은 대문자 + 중괄호 형식으로 사용되며, 운영자가 직접 편집할 수 있습니다.
                        </div>
                    </div>

                    <div style="margin-top:12px; font-size:12px; color:#666;">
                        <strong>⚠️ 등록 시 주의사항</strong>
                        <ul style="margin:6px 0 0 18px; padding:0; line-height:1.7;">
                            <li>광고성 키워드(무료/이벤트/할인/혜택 등)는 카카오 심사에서 거부됩니다.</li>
                            <li>변수가 차지하는 비율이 50% 를 넘지 않도록 정형 텍스트를 충분히 둬야 합니다.</li>
                            <li>본문이 등록 템플릿과 글자 단위로 일치해야 발송됩니다 (변수 자리 외 모든 텍스트 동일).</li>
                            <li>템플릿 본문을 수정하려면 <code>plugin/gb_guardian/templates/kakao_default.txt</code> 파일과 카카오 비즈센터 등록본 양쪽 모두 동일하게 수정해야 합니다 (글자 단위로 일치 필요).</li>
                            <li>일부 변수만 사용하셔도 됩니다 — 본문에 안 쓴 변수는 무시되며, 본문에 쓴 변수만 치환됩니다.</li>
                        </ul>
                    </div>
                </div>
            </details>
        </th>
    </tr>

</tbody>
</table>
</div>
</section>

<div class="local_desc01 local_desc" style="background:#fff8e1; border-left:4px solid #fbc02d; color:#5d4037; margin-top:20px;">
    <strong>💡 발송 보호 시스템 동작 순서</strong><br>
    <span style="font-size:13px;">
        매 발송 호출 시 다음 순서로 검증: <strong>활성화 → 비상정지 → 채널 가용성 → 수신자 활성 → 야간 무음(SMS/카톡) → 일일 한도 → 쿨다운 → 재진입 락</strong> →
        실제 발송. 어느 한 단계에서 차단되면 발송 시도조차 하지 않습니다.
    </span>
</div>

<!-- ==================================================================== -->

<!-- ==================================================================== -->

<?php if (function_exists('guardian_render_series_section')) {
    echo guardian_render_series_section();
} ?>

<h3 style="margin-top:30px; color:#0f3460; border-bottom:2px solid #0f3460; padding-bottom:5px;">💡 추천 알림 설정</h3>
<p style="font-size:13px; color:#666;">
    부담스러우시면 끄셔도 됩니다. 끄셔도 운영지킴이 핵심 기능(오류 캡처·알림 발송)은 정상 동작합니다.
</p>

<div class="tbl_frm01 tbl_wrap">
<table>
<colgroup><col class="grid_4"><col></colgroup>
<tbody>
    <tr>
        <th scope="row">메일 푸터 브랜드 표기</th>
        <td>
            <label>
                <input type="checkbox" name="brand_footer_enabled" value="1"
                       <?php echo (function_exists('guardian_config_get') && guardian_config_get('brand_footer_enabled', true)) ? 'checked' : ''; ?>>
                활성화 — 메일 푸터에 작은 WizardOfCode 표기 1줄 추가
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">미해결 오류 알림</th>
        <td>
            <label>
                <input type="checkbox" name="unresolved_alert_enabled" value="1"
                       <?php echo (function_exists('guardian_config_get') && guardian_config_get('unresolved_alert_enabled', true)) ? 'checked' : ''; ?>>
                활성화 — 일일/주간 요약 메일에 미해결 FATAL/ERROR 진단 알림 박스 추가
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">시리즈 섹션 표시</th>
        <td>
            <label>
                <input type="checkbox" name="series_section_enabled" value="1"
                       <?php echo (function_exists('guardian_config_get') && guardian_config_get('series_section_enabled', true)) ? 'checked' : ''; ?>>
                활성화 — 본 환경설정 페이지의 WizardOfCode 시리즈 카드 4개 표시
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">카카오톡 채널 노출</th>
        <td>
            <label>
                <input type="checkbox" name="kakao_channel_enabled" value="1"
                       <?php echo (function_exists('guardian_config_get') && guardian_config_get('kakao_channel_enabled', true)) ? 'checked' : ''; ?>>
                활성화 — 제작자 정보 박스에 카카오톡 채널 버튼 (유지보수 의뢰 문의 채널)
            </label>
        </td>
    </tr>
</tbody>
</table>
</div>

<?php if (function_exists('guardian_render_developer_info_box')) {
    echo guardian_render_developer_info_box();
} ?>

<div class="btn_fixed_top">
    <a href="<?php echo G5_ADMIN_URL; ?>/guardian_dashboard.php" class="btn btn_02">취소</a>
    <button type="submit" class="btn_submit btn">저장</button>
</div>

</form>

<script>
// cron 인증 토큰 — 클라이언트 즉시 재생성 (메일 prefix + 랜덤 16자 hex).
// JS Math.random 은 암호학적 난수가 아니지만, 본 토큰 용도(공격자 추측 어렵게)
// 에는 충분. 보다 강한 난수가 필요하면 입력란을 비우고 저장 → 서버에서
// openssl_random_pseudo_bytes 로 재생성됨.
function guardianRegenerateCronSecret() {
    var emailPrefix = <?php echo json_encode($cron_email_prefix); ?>;
    var hex = '0123456789abcdef';
    var rnd = '';
    // crypto.getRandomValues 가 있으면 사용 (모던 브라우저)
    if (window.crypto && window.crypto.getRandomValues) {
        var arr = new Uint8Array(8);
        window.crypto.getRandomValues(arr);
        for (var i = 0; i < arr.length; i++) {
            var b = arr[i];
            rnd += hex.charAt((b >> 4) & 0xF) + hex.charAt(b & 0xF);
        }
    } else {
        for (var j = 0; j < 16; j++) {
            rnd += hex.charAt(Math.floor(Math.random() * 16));
        }
    }
    var input = document.getElementById('cron_secret');
    if (input) {
        input.value = emailPrefix + rnd;
        input.focus();
        if (input.select) input.select();
    }
}
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
