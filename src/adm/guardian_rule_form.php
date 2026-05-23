<?php
/**
 * 그누보드 운영지킴이 - 알림 규칙 등록/수정 폼
 *
 * rule_id 가 GET 으로 들어오면 수정, 없으면 신규.
 * 모드(instant/daily/weekly/timeofday)에 따라 동적 UI 표시.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700300";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_admin.lib.php');
}

$rid = isset($_GET['rule_id']) ? (int)$_GET['rule_id'] : 0;
$is_edit = ($rid > 0);

// 기본 값
$row = array(
    'rule_id'        => 0,
    'rule_name'      => '',
    'rule_active'    => 1,
    'priority'       => 50,
    'error_levels'   => 'FATAL,ERROR,EXCEPTION',
    'file_pattern'   => '',
    'channel'        => 'email',
    'recipient_ids'  => '',
    'mode'           => 'instant',
    'schedule_time'  => '',
    'cooldown_min'   => 30,
    'daily_limit'    => 50,
    'dedup_scope'    => 'rule',
);

if ($is_edit) {
    $found = sql_fetch(
        " SELECT * FROM `{$g5['guardian_rule_table']}`
          WHERE rule_id = " . $rid . " LIMIT 1 ",
        false
    );
    if (empty($found)) {
        alert('규칙을 찾을 수 없습니다.', './guardian_rule.php');
    }
    $row = array_merge($row, $found);
}

// schedule_time 분해 (mode 별 폼 입력 채우기용)
$st_daily   = '09:00';
$st_dow     = 1;
$st_weekly  = '09:00';
$st_timeofday = '09-18';

if ($row['mode'] === 'daily' && $row['schedule_time'] !== '') {
    $st_daily = $row['schedule_time'];
}
if ($row['mode'] === 'weekly' && $row['schedule_time'] !== '') {
    $parts = explode('|', $row['schedule_time']);
    if (count($parts) === 2) {
        $st_dow = (int)$parts[0];
        $st_weekly = $parts[1];
    }
}
if ($row['mode'] === 'timeofday' && $row['schedule_time'] !== '') {
    $st_timeofday = $row['schedule_time'];
}

// 등급 체크박스용 — 현재 선택된 등급 배열로
$selected_levels = array_map('trim', explode(',', $row['error_levels']));
$selected_levels = array_map('strtoupper', $selected_levels);

// 수신자 목록 로드 (체크박스 빌드용)
$recipients = array();
$res = @sql_query(
    " SELECT recipient_id, name, email, mobile, active
      FROM `{$g5['guardian_recipient_table']}`
      WHERE active = 1
      ORDER BY recipient_id ASC ",
    false
);
if ($res) {
    while ($r = sql_fetch_array($res)) {
        $recipients[] = $r;
    }
}

// 현재 선택된 수신자 ID 배열
$selected_rids = array();
if (!empty($row['recipient_ids'])) {
    foreach (explode(',', $row['recipient_ids']) as $r) {
        $r = (int)trim($r);
        if ($r > 0) $selected_rids[] = $r;
    }
}

$g5['title'] = $is_edit ? '알림 규칙 수정' : '알림 규칙 등록';

// CSRF 토큰은 페이지당 1회만 발급한다. get_admin_token() 은 호출할 때마다
// 새 토큰을 만들어 세션을 덮어쓰므로, 같은 페이지에 두 폼(저장 / 삭제) 이
// 있을 때 마지막 발급 토큰만 유효해진다. PHP 변수로 받아 두 폼이 같은
// 토큰을 사용하도록 한다.
$g_admin_token = get_admin_token();

include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}</style>

<form name="frule" method="post" action="./guardian_rule_update.php" autocomplete="off"
      onsubmit="return validateRule();">
<input type="hidden" name="token" value="<?php echo $g_admin_token; ?>">
<input type="hidden" name="act" value="<?php echo $is_edit ? 'update' : 'insert'; ?>">
<input type="hidden" name="rule_id" value="<?php echo (int)$row['rule_id']; ?>">

<div class="local_desc01 local_desc">
    매칭 조건 + 발송 설정 + 보호 설정을 모두 입력하세요. 규칙은 등록 직후 활성 상태로 동작하므로
    필요 시 "활성" 체크를 끈 채 저장한 뒤 테스트할 수 있습니다.
</div>

<h3 style="margin:20px 0 10px 0; color:#0f3460; border-bottom:2px solid #0f3460; padding-bottom:5px;">📝 기본 정보</h3>
<div class="tbl_frm01 tbl_wrap">
<table>
<colgroup><col class="grid_4"><col></colgroup>
<tbody>
    <tr>
        <th><label for="rule_name">규칙명 <span style="color:#c33;">*</span></label></th>
        <td>
            <input type="text" name="rule_name" id="rule_name" required maxlength="100"
                   value="<?php echo get_text($row['rule_name']); ?>" class="frm_input" style="width:400px;">
        </td>
    </tr>
    <tr>
        <th>활성</th>
        <td>
            <input type="checkbox" name="rule_active" id="rule_active" value="1" <?php echo !empty($row['rule_active']) ? 'checked' : ''; ?>>
            <label for="rule_active" style="margin-left:6px;">규칙 활성화</label>
        </td>
    </tr>
    <tr>
        <th><label for="priority">우선순위</label></th>
        <td>
            <input type="number" name="priority" id="priority" min="1" max="100"
                   value="<?php echo (int)$row['priority']; ?>" class="frm_input" style="width:100px;">
            <span style="color:#666; font-size:12px;">1~100, 낮을수록 먼저 매칭됩니다.</span>
        </td>
    </tr>
</tbody>
</table>
</div>

<h3 style="margin:20px 0 10px 0; color:#0f3460; border-bottom:2px solid #0f3460; padding-bottom:5px;">🎯 매칭 조건</h3>
<div class="tbl_frm01 tbl_wrap">
<table>
<colgroup><col class="grid_4"><col></colgroup>
<tbody>
    <tr>
        <th>오류 등급</th>
        <td>
            <?php foreach (array('FATAL','ERROR','EXCEPTION','DB','WARNING','NOTICE','DEPRECATED') as $lv) { ?>
            <label style="display:inline-block; margin-right:12px; font-size:13px;">
                <input type="checkbox" name="error_levels[]" value="<?php echo $lv; ?>"
                       <?php echo in_array($lv, $selected_levels, true) ? 'checked' : ''; ?>>
                <?php echo $lv; ?>
            </label>
            <?php } ?>
            <label style="display:inline-block; margin-right:12px; font-size:13px; padding-left:12px; border-left:1px solid #ccc;">
                <input type="checkbox" name="error_levels[]" value="ALL"
                       <?php echo in_array('ALL', $selected_levels, true) ? 'checked' : ''; ?>>
                <strong>ALL</strong> (모든 등급)
            </label>
            <p style="font-size:12px; color:#888; margin-top:6px;">하나도 체크 안 하면 매칭되지 않습니다. ALL 체크 시 다른 체크는 무시됩니다.</p>
        </td>
    </tr>
    <tr>
        <th><label for="file_pattern">파일 패턴 (선택)</label></th>
        <td>
            <input type="text" name="file_pattern" id="file_pattern" maxlength="200"
                   value="<?php echo get_text($row['file_pattern']); ?>" class="frm_input" style="width:400px;"
                   placeholder="%bbs/write.php% 또는 %shop/order%">
            <p style="font-size:12px; color:#888; margin-top:5px;">
                SQL LIKE 스타일. <code>%</code>=임의 문자, <code>_</code>=한 글자.
                비워두면 모든 파일 매칭. 정규식 메타문자는 자동 이스케이프됩니다.
            </p>
        </td>
    </tr>
</tbody>
</table>
</div>

<h3 style="margin:20px 0 10px 0; color:#0f3460; border-bottom:2px solid #0f3460; padding-bottom:5px;">📤 발송 설정</h3>
<div class="tbl_frm01 tbl_wrap">
<table>
<colgroup><col class="grid_4"><col></colgroup>
<tbody>
    <tr>
        <th>발송 모드</th>
        <td>
            <?php foreach (array(
                'instant'   => '⚡ 즉시 발송',
                'daily'     => '📅 일일 요약',
                'weekly'    => '📆 주간 요약',
                'timeofday' => '⏰ 시간대 제한',
            ) as $mk => $ml) { ?>
            <label style="display:inline-block; margin-right:18px; font-size:13px;">
                <input type="radio" name="mode" value="<?php echo $mk; ?>"
                       onchange="updateModeUI(this.value);"
                       <?php echo $row['mode'] === $mk ? 'checked' : ''; ?>>
                <?php echo $ml; ?>
            </label>
            <?php } ?>

            <!-- mode 별 추가 입력 -->
            <div id="mode-extra-instant" class="mode-extra" style="display:none; margin-top:12px; padding:12px; background:#f5f5f5; border-radius:4px;">
                즉시 발송 — 추가 설정 없음. 오류 발생 시 곧바로 발송합니다.
            </div>

            <div id="mode-extra-daily" class="mode-extra" style="display:none; margin-top:12px; padding:12px; background:#f5f5f5; border-radius:4px;">
                <label>발송 시각:
                    <input type="time" name="schedule_time_daily" value="<?php echo get_text($st_daily); ?>" class="frm_input">
                </label>
                <span style="color:#666; font-size:12px; margin-left:10px;">매일 이 시각에 어제 오류 요약 발송</span>
            </div>

            <div id="mode-extra-weekly" class="mode-extra" style="display:none; margin-top:12px; padding:12px; background:#f5f5f5; border-radius:4px;">
                <label>요일:
                    <select name="schedule_dow" class="frm_input">
                        <?php foreach (array(1=>'월',2=>'화',3=>'수',4=>'목',5=>'금',6=>'토',7=>'일') as $dnum => $dname) { ?>
                            <option value="<?php echo $dnum; ?>" <?php echo ($st_dow === $dnum) ? 'selected' : ''; ?>><?php echo $dname; ?>요일</option>
                        <?php } ?>
                    </select>
                </label>
                <label style="margin-left:10px;">시각:
                    <input type="time" name="schedule_time_weekly" value="<?php echo get_text($st_weekly); ?>" class="frm_input">
                </label>
                <span style="color:#666; font-size:12px; margin-left:10px;">매주 이 요일/시각에 지난 7일 요약 발송</span>
            </div>

            <div id="mode-extra-timeofday" class="mode-extra" style="display:none; margin-top:12px; padding:12px; background:#f5f5f5; border-radius:4px;">
                <label>활성 시간대:
                    <input type="text" name="schedule_timeofday" value="<?php echo get_text($st_timeofday); ?>" class="frm_input" style="width:200px;" placeholder="09-18">
                </label>
                <p style="color:#666; font-size:12px; margin-top:5px;">
                    예: <code>09-18</code> (오전 9시~오후 6시), <code>09-12,14-18</code> (오전+오후, 점심 제외), <code>22-08</code> (자정 걸침).<br>
                    이 시간대에 발생한 오류만 즉시 발송. 외 시간 오류는 무시됩니다.
                </p>
            </div>
        </td>
    </tr>
    <tr>
        <th>채널</th>
        <td>
            <?php foreach (array(
                'email' => '📧 이메일',
                'sms'   => '📱 SMS',
                'kakao' => '💬 카카오 알림톡',
            ) as $ck => $cl) { ?>
            <label style="display:inline-block; margin-right:18px; font-size:13px;">
                <input type="radio" name="channel" value="<?php echo $ck; ?>" <?php echo $row['channel'] === $ck ? 'checked' : ''; ?>>
                <?php echo $cl; ?>
            </label>
            <?php } ?>
            <p style="font-size:12px; color:#888; margin-top:5px;">
                SMS / 카톡 발송에는 알리고 솔루션 설치와 환경설정 활성화가 필요합니다.
                일일/주간 요약은 항상 메일로 발송됩니다.
            </p>
        </td>
    </tr>
    <tr>
        <th>수신자</th>
        <td>
            <?php if (empty($recipients)) { ?>
                <p style="color:#c33;">⚠️ 활성 수신자가 없습니다.
                    <a href="./guardian_recipient_form.php" class="btn btn_03" style="margin-left:10px;">수신자 먼저 등록</a>
                </p>
            <?php } else { ?>
                <div style="max-height:200px; overflow-y:auto; border:1px solid #ddd; padding:10px; border-radius:4px;">
                <?php foreach ($recipients as $r) { ?>
                    <label style="display:block; padding:4px 0; font-size:13px;">
                        <input type="checkbox" name="recipient_ids[]" value="<?php echo (int)$r['recipient_id']; ?>"
                               <?php echo in_array((int)$r['recipient_id'], $selected_rids, true) ? 'checked' : ''; ?>>
                        <strong><?php echo get_text($r['name']); ?></strong>
                        <span style="color:#666;">(<?php echo get_text($r['email']); ?><?php if ($r['mobile']) echo ' / ' . get_text($r['mobile']); ?>)</span>
                    </label>
                <?php } ?>
                </div>
            <?php } ?>
        </td>
    </tr>
</tbody>
</table>
</div>

<h3 style="margin:20px 0 10px 0; color:#0f3460; border-bottom:2px solid #0f3460; padding-bottom:5px;">🛡️ 보호 설정</h3>
<div class="tbl_frm01 tbl_wrap">
<table>
<colgroup><col class="grid_4"><col></colgroup>
<tbody>
    <tr>
        <th><label for="cooldown_min">동일 오류 쿨다운</label></th>
        <td>
            <input type="number" name="cooldown_min" id="cooldown_min" min="1" max="1440"
                   value="<?php echo (int)$row['cooldown_min']; ?>" class="frm_input" style="width:80px;"> 분
            <p style="font-size:12px; color:#888; margin-top:5px;">
                같은 오류 해시로 이미 성공 발송했으면 이 시간 내 재발송을 차단.
            </p>
        </td>
    </tr>
    <tr>
        <th>중복 제거 범위</th>
        <td>
            <label style="display:inline-block; margin-right:18px; font-size:13px;">
                <input type="radio" name="dedup_scope" value="rule" <?php echo $row['dedup_scope'] === 'rule' ? 'checked' : ''; ?>>
                규칙별 (이 규칙 + 같은 오류 + 같은 수신자)
            </label>
            <label style="display:inline-block; font-size:13px;">
                <input type="radio" name="dedup_scope" value="global" <?php echo $row['dedup_scope'] === 'global' ? 'checked' : ''; ?>>
                전역 (다른 규칙 포함, 같은 오류 + 같은 수신자에 1회만)
            </label>
            <p style="font-size:12px; color:#888; margin-top:5px;">
                전역으로 설정하면 같은 오류가 여러 규칙에 매칭돼도 수신자가 알림을 한 번만 받습니다.
            </p>
        </td>
    </tr>
</tbody>
</table>
</div>

<div class="btn_fixed_top">
    <a href="./guardian_rule.php" class="btn btn_02">취소</a>
    <button type="submit" class="btn_submit btn">저장</button>
    <?php if ($is_edit) { ?>
        <button type="button" onclick="confirmDelete();" class="btn" style="background:#d32f2f; color:#fff;">삭제</button>
        <a href="./guardian_rule_match_log.php?rule_id=<?php echo $rid; ?>" class="btn btn_03">매칭 로그 보기</a>
    <?php } ?>
</div>

</form>

<?php if ($is_edit) { ?>
<form id="fdelete" method="post" action="./guardian_rule_update.php" style="display:none;">
    <input type="hidden" name="token" value="<?php echo $g_admin_token; ?>">
    <input type="hidden" name="act" value="delete_one">
    <input type="hidden" name="rule_id" value="<?php echo $rid; ?>">
</form>
<?php } ?>

<script>
function updateModeUI(mode) {
    var modes = ['instant', 'daily', 'weekly', 'timeofday'];
    for (var i = 0; i < modes.length; i++) {
        var el = document.getElementById('mode-extra-' + modes[i]);
        if (el) el.style.display = (modes[i] === mode) ? 'block' : 'none';
    }
}
function validateRule() {
    var name = document.getElementById('rule_name').value.trim();
    if (!name) { alert('규칙명을 입력하세요.'); return false; }

    var levels = document.querySelectorAll('input[name="error_levels[]"]:checked');
    if (levels.length === 0) { alert('오류 등급을 1개 이상 선택하세요.'); return false; }

    var rids = document.querySelectorAll('input[name="recipient_ids[]"]:checked');
    if (rids.length === 0) {
        if (!confirm('수신자를 선택하지 않았습니다. 그래도 저장하시겠습니까? (규칙이 매칭돼도 발송 대상이 없습니다.)')) return false;
    }
    return true;
}
function confirmDelete() {
    if (!confirm('이 규칙을 삭제하시겠습니까? 되돌릴 수 없습니다.')) return;
    document.forms['fdelete'].submit();
}
// 초기 로드 시 현재 모드 UI 표시
updateModeUI('<?php echo get_text($row['mode']); ?>');
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
