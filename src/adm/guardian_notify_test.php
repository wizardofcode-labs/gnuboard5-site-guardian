<?php
/**
 * 그누보드 운영지킴이 - 테스트 발송 페이지
 *
 * 가상의 오류 데이터로 실제 알림을 발송해 동작을 검증한다.
 *
 * 핵심 원칙:
 *   - 테스트 발송도 보호 시스템(비상정지/일일한도/야간무음/락)을 모두 거친다.
 *   - 쿨다운만 0 으로 우회 (매번 다른 error_hash 라 자연 회피).
 *   - 응답에 알리고 원본 메시지를 그대로 노출하지 않음 — 사용자에게는 success/failed 와 정제된 사유만.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700600";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'w');

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_admin.lib.php');
    include_once(GUARDIAN_LIB_PATH . '/guardian_notifier.lib.php');
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_admin.lib.php');
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_notifier.lib.php');
}

// 처리 분기
$test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_admin_token();

    $channel = isset($_POST['channel']) ? (string)$_POST['channel'] : '';
    if (!in_array($channel, array('email', 'sms', 'kakao'), true)) {
        alert('잘못된 채널입니다.');
    }

    $rid = isset($_POST['recipient_id']) ? (int)$_POST['recipient_id'] : 0;
    if ($rid <= 0) {
        alert('수신자를 선택해주세요.');
    }

    // 채널 가용성 사전 확인 (UI에 명확한 사유 표시 위해)
    if (!guardian_is_channel_available($channel)) {
        $test_result = array(
            'success' => false,
            'reason'  => '채널 사용 불가 — 환경설정에서 알리고 연동 활성화 또는 솔루션 설치를 확인하세요.',
            'channel' => $channel,
            'recipient_id' => $rid,
        );
    } else {
        $test_result = guardian_send_test_notification($channel, $rid);
        $test_result['channel']      = $channel;
        $test_result['recipient_id'] = $rid;
    }
}

// 활성 수신자 목록 (드롭다운용)
$recipients = array();
$res = @sql_query(
    " SELECT recipient_id, name, email, mobile
      FROM `{$g5['guardian_recipient_table']}`
      WHERE active = 1
      ORDER BY recipient_id DESC ",
    false
);
if ($res) {
    while ($r = sql_fetch_array($res)) {
        $recipients[] = $r;
    }
}

$selected_rid = isset($_GET['recipient_id']) ? (int)$_GET['recipient_id'] : 0;

// 채널 가용성 안내
$ch_email_ok = guardian_is_channel_available('email');
$ch_sms_ok   = guardian_is_channel_available('sms');
$ch_kakao_ok = guardian_is_channel_available('kakao');

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 테스트 발송';
include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}</style>

<div class="local_desc01 local_desc">
    가상의 <code>FATAL</code> 오류 시나리오로 실제 알림을 발송합니다.
    <strong>SMS / 카톡은 비용이 발생할 수 있습니다.</strong>
    모든 보호 시스템(비상정지 / 일일한도 / 야간무음 / 재진입락)을 통과해야 발송됩니다.
</div>

<?php if ($test_result !== null) { ?>
<?php if (!empty($test_result['success'])) { ?>
<div class="local_desc01 local_desc" style="background:#e8f5e9; color:#2e7d32; border-left:4px solid #2e7d32;">
    <strong>✓ 발송 성공</strong> —
    채널 <code><?php echo get_text($test_result['channel']); ?></code> /
    수신자 ID <?php echo (int)$test_result['recipient_id']; ?>
</div>
<?php } else { ?>
<div class="local_desc01 local_desc" style="background:#fef2f2; color:#c33; border-left:4px solid #c33;">
    <strong>✗ 발송 실패</strong> —
    <?php echo get_text(isset($test_result['reason']) ? $test_result['reason'] : '알 수 없는 오류'); ?>
</div>
<?php } ?>
<?php } ?>

<form name="ftest" method="post" action="">
<input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">

<div class="tbl_frm01 tbl_wrap">
<table>
<colgroup><col class="grid_4"><col></colgroup>
<tbody>
    <tr>
        <th scope="row">채널</th>
        <td>
            <label style="margin-right:15px;">
                <input type="radio" name="channel" value="email" checked>
                이메일
                <?php if (!$ch_email_ok) echo '<span style="color:#c33;font-size:12px;">(사용 불가)</span>'; ?>
            </label>
            <label style="margin-right:15px;">
                <input type="radio" name="channel" value="sms" <?php if (!$ch_sms_ok) echo 'disabled'; ?>>
                SMS
                <?php if (!$ch_sms_ok) echo '<span style="color:#c33;font-size:12px;">(알리고 연동 필요)</span>'; ?>
                <?php if ($ch_sms_ok) echo '<span style="color:#c63;font-size:12px;">(비용 발생)</span>'; ?>
            </label>
            <label>
                <input type="radio" name="channel" value="kakao" <?php if (!$ch_kakao_ok) echo 'disabled'; ?>>
                카카오 알림톡
                <?php if (!$ch_kakao_ok) echo '<span style="color:#c33;font-size:12px;">(알리고 카톡 + 템플릿 코드 필요)</span>'; ?>
            </label>
        </td>
    </tr>
    <tr>
        <th scope="row">수신자</th>
        <td>
            <select name="recipient_id" required style="min-width:300px;">
                <option value="">-- 활성 수신자 선택 --</option>
                <?php foreach ($recipients as $r) {
                    $sel = ((int)$r['recipient_id'] === $selected_rid) ? ' selected' : '';
                    $label = $r['name'] . ' <' . $r['email'] . '>';
                    if (!empty($r['mobile'])) {
                        $label .= ' / ' . $r['mobile'];
                    }
                ?>
                <option value="<?php echo (int)$r['recipient_id']; ?>"<?php echo $sel; ?>>
                    <?php echo get_text($label); ?>
                </option>
                <?php } ?>
            </select>
            <?php if (empty($recipients)) { ?>
            <p style="color:#c33; margin-top:8px; font-size:12px;">
                활성 수신자가 없습니다.
                <a href="./guardian_recipient.php" style="color:#1976d2;">수신자 관리</a>에서 먼저 등록하세요.
            </p>
            <?php } ?>
        </td>
    </tr>
    <tr>
        <th scope="row">테스트 메시지</th>
        <td>
            <div style="background:#f5f5f5; padding:12px 15px; border-radius:4px; font-family:Consolas,monospace; font-size:12px; color:#555;">
                <strong>가상 오류 시나리오</strong><br>
                레벨: TEST<br>
                메시지: 운영지킴이 테스트 알림입니다. 실제 오류가 아닙니다.<br>
                파일: [TEST]:0<br>
                시각: <?php echo date('Y-m-d H:i:s'); ?>
            </div>
            <p style="color:#888; font-size:12px; margin-top:8px;">
                매 발송마다 다른 <code>error_hash</code> 가 생성되어 쿨다운에 잡히지 않습니다.
                다른 보호(일일 한도 / 비상 정지 / 야간 무음 / 락)는 모두 적용됩니다.
            </p>
        </td>
    </tr>
</tbody>
</table>
</div>

<div class="btn_fixed_top">
    <a href="./guardian_dashboard.php" class="btn btn_02">취소</a>
    <button type="submit" class="btn_submit btn"
            <?php echo empty($recipients) ? 'disabled' : ''; ?>>
        테스트 발송
    </button>
</div>

</form>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
