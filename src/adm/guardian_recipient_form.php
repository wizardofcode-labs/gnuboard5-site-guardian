<?php
/**
 * 그누보드 운영지킴이 - 수신자 등록 / 수정 폼
 *
 * recipient_id 가 GET 으로 들어오면 수정, 없으면 신규.
 * 저장은 guardian_recipient_update.php 가 처리.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700400";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

$rid = isset($_GET['recipient_id']) ? (int)$_GET['recipient_id'] : 0;
$is_edit = ($rid > 0);

$row = array(
    'recipient_id' => 0,
    'name'         => '',
    'email'        => '',
    'mobile'       => '',
    'active'       => 1,
);

if ($is_edit) {
    $found = sql_fetch(
        " SELECT recipient_id, name, email, mobile, active
          FROM `{$g5['guardian_recipient_table']}`
          WHERE recipient_id = " . $rid . " LIMIT 1 ",
        false
    );
    if (empty($found)) {
        alert('수신자를 찾을 수 없습니다.', './guardian_recipient.php');
    }
    $row = $found;
}

$g5['title'] = $is_edit ? '수신자 수정' : '수신자 등록';
include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}</style>

<form name="frecipient" method="post" action="./guardian_recipient_update.php" autocomplete="off"
      onsubmit="return validateRecipient();">
<input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">
<input type="hidden" name="act" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">
<input type="hidden" name="recipient_id" value="<?php echo (int)$row['recipient_id']; ?>">

<div class="local_desc01 local_desc">
    이메일은 필수입니다. 휴대폰은 SMS / 카카오 알림톡 발송 시 필요합니다.
    저장 후 우측 상단 "테스트 발송" 버튼으로 실제 도착 여부를 확인하세요.
</div>

<div class="tbl_frm01 tbl_wrap">
<table>
<colgroup>
    <col class="grid_4">
    <col>
</colgroup>
<tbody>
    <tr>
        <th scope="row"><label for="name">이름 <span style="color:#c33;">*</span></label></th>
        <td>
            <input type="text" name="name" id="name" required maxlength="50"
                   value="<?php echo get_text($row['name']); ?>" class="frm_input" style="width:300px;">
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="email">이메일 <span style="color:#c33;">*</span></label></th>
        <td>
            <input type="email" name="email" id="email" required maxlength="100"
                   value="<?php echo get_text($row['email']); ?>" class="frm_input" style="width:300px;">
            <p style="color:#888; font-size:12px; margin-top:5px;">메일 발송에 사용됩니다.</p>
        </td>
    </tr>
    <tr>
        <th scope="row"><label for="mobile">휴대폰</label></th>
        <td>
            <input type="text" name="mobile" id="mobile" maxlength="20"
                   value="<?php echo get_text($row['mobile']); ?>" class="frm_input" style="width:200px;"
                   placeholder="01012345678">
            <p style="color:#888; font-size:12px; margin-top:5px;">
                SMS / 카카오 알림톡 발송에 사용됩니다. 하이픈은 자동 제거됩니다.
            </p>
        </td>
    </tr>
    <tr>
        <th scope="row">활성</th>
        <td>
            <input type="checkbox" name="active" id="active" value="1" <?php echo !empty($row['active']) ? 'checked' : ''; ?>>
            <label for="active" style="margin-left:6px;">알림 수신 활성화</label>
        </td>
    </tr>
</tbody>
</table>
</div>

<div class="btn_fixed_top">
    <a href="./guardian_recipient.php" class="btn btn_02">취소</a>
    <button type="submit" class="btn_submit btn">저장</button>
    <?php if ($is_edit) { ?>
        <a href="./guardian_notify_test.php?recipient_id=<?php echo $rid; ?>" class="btn btn_03">테스트 발송</a>
    <?php } ?>
</div>

</form>

<script>
function validateRecipient() {
    var email = document.getElementById('email').value.trim();
    var mobile = document.getElementById('mobile').value.replace(/[^0-9]/g, '');
    if (!email) { alert('이메일은 필수입니다.'); return false; }
    if (mobile && !/^01[016-9]\d{7,8}$/.test(mobile)) {
        alert('휴대폰 번호 형식이 올바르지 않습니다 (01012345678).');
        return false;
    }
    return true;
}
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
