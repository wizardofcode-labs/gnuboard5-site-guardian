<?php
/**
 * 그누보드 운영지킴이 - 알림 규칙 목록
 *
 * 알림 규칙 목록 / 등록 / 수정 / 삭제 화면.
 *
 * 보안:
 *   - auth_check_menu / get_admin_token / sql_escape_string
 *   - 검색 필드 화이트리스트
 *   - IN(...) 절은 (int) 캐스팅 후 사용
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
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_admin.lib.php');
}

// ---- 입력값 정제 ----
$f_active  = isset($_GET['active'])  ? (string)$_GET['active']  : '';
if (!in_array($f_active, array('', '0', '1'), true)) $f_active = '';

$f_channel = isset($_GET['channel']) ? (string)$_GET['channel'] : '';
if (!in_array($f_channel, array('', 'email', 'sms', 'kakao'), true)) $f_channel = '';

$f_mode    = isset($_GET['mode'])    ? (string)$_GET['mode']    : '';
if (!in_array($f_mode, array('', 'instant', 'daily', 'weekly', 'timeofday'), true)) $f_mode = '';

$f_stx = isset($_GET['stx']) ? trim((string)$_GET['stx']) : '';

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$rows = 30;

// ---- WHERE 빌드 ----
$where = array('1=1');
if ($f_active !== '')  $where[] = "rule_active = " . (int)$f_active;
if ($f_channel !== '') $where[] = "channel = '" . sql_escape_string($f_channel) . "'";
if ($f_mode !== '')    $where[] = "`mode` = '" . sql_escape_string($f_mode) . "'";
if ($f_stx !== '')     $where[] = "rule_name LIKE '%" . sql_escape_string($f_stx) . "%'";
$where_clause = implode(' AND ', $where);

// ---- 카운트 ----
$total_count = 0;
$row_total = sql_fetch(
    " SELECT COUNT(*) AS cnt FROM `{$g5['guardian_rule_table']}` WHERE {$where_clause} ",
    false
);
if (!empty($row_total['cnt'])) $total_count = (int)$row_total['cnt'];

$total_page = ($rows > 0) ? (int)ceil($total_count / $rows) : 1;
if ($total_page < 1) $total_page = 1;
if ($page > $total_page) $page = $total_page;
$from = ($page - 1) * $rows;

// ---- 데이터 조회 (활성 수신자 수 서브쿼리 포함) ----
$result = @sql_query(
    " SELECT r.*,
             (SELECT COUNT(*)
              FROM `{$g5['guardian_recipient_table']}`
              WHERE FIND_IN_SET(recipient_id, r.recipient_ids)
                AND active = 1) AS active_recipient_count
      FROM `{$g5['guardian_rule_table']}` r
      WHERE {$where_clause}
      ORDER BY priority ASC, rule_id ASC
      LIMIT {$from}, {$rows} ",
    false
);

// ---- 페이징 ----
$qs_arr = array();
if ($f_active  !== '') $qs_arr[] = 'active='  . urlencode($f_active);
if ($f_channel !== '') $qs_arr[] = 'channel=' . urlencode($f_channel);
if ($f_mode    !== '') $qs_arr[] = 'mode='    . urlencode($f_mode);
if ($f_stx     !== '') $qs_arr[] = 'stx='     . urlencode($f_stx);
$qstr = implode('&amp;', $qs_arr);
$pagenav = function_exists('get_paging')
    ? get_paging(10, $page, $total_page, '?' . $qstr . '&amp;page=')
    : '';

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

// 모드 / 채널 한글 라벨
$mode_label = array(
    'instant'   => '즉시',
    'daily'     => '일일 요약',
    'weekly'    => '주간 요약',
    'timeofday' => '시간대 제한',
);
$channel_label = array(
    'email' => '메일',
    'sms'   => 'SMS',
    'kakao' => '카톡',
);

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 알림 규칙 관리';
include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}</style>

<?php if ($msg !== '') { ?>
<div class="local_desc01 local_desc" style="background:#e8f5e9;color:#2e7d32;border-left:4px solid #2e7d32;">
    <?php echo get_text($msg); ?>
</div>
<?php } ?>

<form id="fsearch" name="fsearch" class="local_sch01 local_sch" method="get" action="">
    <label for="active" class="sound_only">활성여부</label>
    <select name="active" id="active">
        <option value=""<?php  if ($f_active === '')  echo ' selected'; ?>>활성 전체</option>
        <option value="1"<?php if ($f_active === '1') echo ' selected'; ?>>활성</option>
        <option value="0"<?php if ($f_active === '0') echo ' selected'; ?>>비활성</option>
    </select>

    <label for="channel" class="sound_only">채널</label>
    <select name="channel" id="channel">
        <option value=""<?php       if ($f_channel === '')      echo ' selected'; ?>>채널 전체</option>
        <option value="email"<?php  if ($f_channel === 'email') echo ' selected'; ?>>메일</option>
        <option value="sms"<?php    if ($f_channel === 'sms')   echo ' selected'; ?>>SMS</option>
        <option value="kakao"<?php  if ($f_channel === 'kakao') echo ' selected'; ?>>카톡</option>
    </select>

    <label for="mode" class="sound_only">모드</label>
    <select name="mode" id="mode">
        <option value=""<?php           if ($f_mode === '')          echo ' selected'; ?>>모드 전체</option>
        <option value="instant"<?php    if ($f_mode === 'instant')   echo ' selected'; ?>>즉시</option>
        <option value="daily"<?php      if ($f_mode === 'daily')     echo ' selected'; ?>>일일 요약</option>
        <option value="weekly"<?php     if ($f_mode === 'weekly')    echo ' selected'; ?>>주간 요약</option>
        <option value="timeofday"<?php  if ($f_mode === 'timeofday') echo ' selected'; ?>>시간대</option>
    </select>

    <label for="stx" class="sound_only">규칙명</label>
    <input type="text" name="stx" id="stx" value="<?php echo get_text($f_stx); ?>" maxlength="100" class="frm_input" placeholder="규칙명">
    <button type="submit" class="btn_submit">검색</button>
    <a href="./guardian_rule.php" class="btn btn_02">초기화</a>
</form>

<form id="flist" name="flist" method="post" action="./guardian_rule_update.php">
<input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">
<input type="hidden" name="act" id="act_field" value="">

<div class="local_desc01 local_desc" style="display:flex; justify-content:space-between; align-items:center;">
    <div>총 <strong><?php echo number_format($total_count); ?></strong>개 규칙</div>
    <div>
        <a href="./guardian_rule_form.php" class="btn btn_01">+ 규칙 추가</a>
        <button type="button" onclick="ruleBulk('toggle_active')" class="btn btn_02">선택 활성토글</button>
        <button type="button" onclick="ruleBulk('delete')" class="btn" style="background:#d32f2f;color:#fff;">선택 삭제</button>
    </div>
</div>

<div class="tbl_head01 tbl_wrap">
<table>
<caption>알림 규칙 목록</caption>
<colgroup>
    <col style="width:36px;">
    <col style="width:60px;">
    <col>
    <col style="width:120px;">
    <col style="width:80px;">
    <col style="width:100px;">
    <col style="width:80px;">
    <col style="width:80px;">
    <col style="width:80px;">
</colgroup>
<thead>
<tr>
    <th><input type="checkbox" id="chkAll" onclick="ruleToggleAll(this)"></th>
    <th>우선순위</th>
    <th>규칙명</th>
    <th>등급</th>
    <th>채널</th>
    <th>모드</th>
    <th>수신자</th>
    <th>상태</th>
    <th>관리</th>
</tr>
</thead>
<tbody>
<?php
$row_count = 0;
if ($result) {
    while ($row = sql_fetch_array($result)) {
        $row_count++;
        $rid = (int)$row['rule_id'];
        $is_active = !empty($row['rule_active']);
        $row_mode = isset($row['mode']) ? (string)$row['mode'] : 'instant';
        $row_channel = isset($row['channel']) ? (string)$row['channel'] : 'email';
?>
<tr>
    <td><input type="checkbox" name="rule_ids[]" value="<?php echo $rid; ?>"></td>
    <td style="text-align:center;"><?php echo (int)$row['priority']; ?></td>
    <td>
        <a href="./guardian_rule_form.php?rule_id=<?php echo $rid; ?>" style="text-decoration:none; color:#1a1a2e; font-weight:600;">
            <?php echo get_text($row['rule_name']); ?>
        </a>
        <?php if (!empty($row['file_pattern'])) { ?>
            <div style="font-size:11px; color:#888; margin-top:2px;">패턴: <code><?php echo get_text($row['file_pattern']); ?></code></div>
        <?php } ?>
    </td>
    <td style="font-size:11px;"><?php echo get_text($row['error_levels']); ?></td>
    <td style="text-align:center; font-size:12px;">
        <?php echo isset($channel_label[$row_channel]) ? $channel_label[$row_channel] : get_text($row_channel); ?>
    </td>
    <td style="text-align:center; font-size:12px;">
        <?php echo isset($mode_label[$row_mode]) ? $mode_label[$row_mode] : get_text($row_mode); ?>
        <?php if ($row_mode !== 'instant' && !empty($row['schedule_time'])) { ?>
            <div style="font-size:11px; color:#888;"><?php echo get_text($row['schedule_time']); ?></div>
        <?php } ?>
    </td>
    <td style="text-align:center;">
        <?php
            $active_count = isset($row['active_recipient_count']) ? (int)$row['active_recipient_count'] : 0;
            $total_count_str = '';
            if (!empty($row['recipient_ids'])) {
                $total_in = count(array_filter(array_map('intval', explode(',', $row['recipient_ids']))));
                $total_count_str = $total_in;
            }
            if ($active_count === 0) {
                echo '<span style="color:#c33;">0명</span>';
            } else {
                echo $active_count . '명';
            }
        ?>
    </td>
    <td style="text-align:center;">
        <?php if ($is_active) { ?>
            <span style="color:#2e7d32;">● 활성</span>
        <?php } else { ?>
            <span style="color:#999;">● 비활성</span>
        <?php } ?>
    </td>
    <td style="text-align:center;">
        <a href="./guardian_rule_form.php?rule_id=<?php echo $rid; ?>" class="btn btn_03">수정</a>
    </td>
</tr>
<?php
    }
}
if ($row_count === 0) {
?>
<tr>
    <td colspan="9" style="text-align:center; color:#888; padding:60px 0;">
        등록된 알림 규칙이 없습니다. 우측 상단 "+ 규칙 추가" 버튼으로 추가하세요.<br>
        규칙이 없으면 오류가 캡처되어도 알림이 발송되지 않습니다.
    </td>
</tr>
<?php } ?>
</tbody>
</table>
</div>

</form>

<?php if ($pagenav !== '') { ?>
<div class="pg_wrap"><?php echo $pagenav; ?></div>
<?php } ?>

<script>
function ruleToggleAll(cb) {
    var boxes = document.querySelectorAll('input[name="rule_ids[]"]');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
}
function ruleBulk(act) {
    var boxes = document.querySelectorAll('input[name="rule_ids[]"]:checked');
    if (boxes.length === 0) { alert('대상을 선택하세요.'); return; }
    var msg = (act === 'delete')
        ? '선택한 ' + boxes.length + '개 규칙을 삭제하시겠습니까? 되돌릴 수 없습니다.'
        : '선택한 ' + boxes.length + '개 규칙의 활성 상태를 토글합니다.';
    if (!confirm(msg)) return;
    document.getElementById('act_field').value = act;
    document.forms['flist'].submit();
}
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
