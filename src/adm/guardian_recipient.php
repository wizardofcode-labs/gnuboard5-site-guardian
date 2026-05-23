<?php
/**
 * 그누보드 운영지킴이 - 수신자 목록 관리
 *
 * 수신자 등록 / 활성화 토글 / 일괄 처리 화면.
 *
 * 화면 구성:
 *   - 활성여부 / 텍스트 검색
 *   - 수신자 목록 (이름/이메일/휴대폰/카톡/상태)
 *   - 일괄 액션 (활성토글 / 삭제)
 *   - 행 클릭 → 수정 페이지 (모달 아님)
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
$sub_menu = "700400";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_admin.lib.php');
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_admin.lib.php');
}

// ---- 입력값 정제 ----
$f_active = isset($_GET['active']) ? (string)$_GET['active'] : '';
if (!in_array($f_active, array('', '0', '1'), true)) $f_active = '';

$f_sfl = isset($_GET['sfl']) ? (string)$_GET['sfl'] : 'name';
$valid_sfl = array('name', 'email', 'mobile');
if (!in_array($f_sfl, $valid_sfl, true)) $f_sfl = 'name';

$f_stx = isset($_GET['stx']) ? trim((string)$_GET['stx']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$rows = 30;

// ---- WHERE 빌드 ----
$where = array('1=1');
if ($f_active !== '') {
    $where[] = "active = " . (int)$f_active;
}
if ($f_stx !== '') {
    $where[] = "`{$f_sfl}` LIKE '%" . sql_escape_string($f_stx) . "%'";
}
$where_clause = implode(' AND ', $where);

// ---- 카운트 + 데이터 ----
$total_count = 0;
$row_total = sql_fetch(
    " SELECT COUNT(*) AS cnt FROM `{$g5['guardian_recipient_table']}` WHERE {$where_clause} ",
    false
);
if (!empty($row_total['cnt'])) $total_count = (int)$row_total['cnt'];

$total_page = ($rows > 0) ? (int)ceil($total_count / $rows) : 1;
if ($total_page < 1) $total_page = 1;
if ($page > $total_page) $page = $total_page;
$from = ($page - 1) * $rows;

$result = @sql_query(
    " SELECT recipient_id, name, email, mobile, active, created_dt
      FROM `{$g5['guardian_recipient_table']}`
      WHERE {$where_clause}
      ORDER BY recipient_id DESC
      LIMIT {$from}, {$rows} ",
    false
);

// ---- 페이징 ----
$qs_arr = array();
if ($f_active !== '') $qs_arr[] = 'active=' . urlencode($f_active);
if ($f_stx    !== '') {
    $qs_arr[] = 'sfl=' . urlencode($f_sfl);
    $qs_arr[] = 'stx=' . urlencode($f_stx);
}
$qstr = implode('&amp;', $qs_arr);
$pagenav = function_exists('get_paging')
    ? get_paging(10, $page, $total_page, '?' . $qstr . '&amp;page=')
    : '';

$msg = isset($_GET['msg']) ? (string)$_GET['msg'] : '';

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 수신자 관리';
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
        <option value=""<?php if ($f_active === '') echo ' selected'; ?>>전체</option>
        <option value="1"<?php if ($f_active === '1') echo ' selected'; ?>>활성</option>
        <option value="0"<?php if ($f_active === '0') echo ' selected'; ?>>비활성</option>
    </select>

    <label for="sfl" class="sound_only">검색필드</label>
    <select name="sfl" id="sfl">
        <option value="name"<?php if ($f_sfl === 'name') echo ' selected'; ?>>이름</option>
        <option value="email"<?php if ($f_sfl === 'email') echo ' selected'; ?>>이메일</option>
        <option value="mobile"<?php if ($f_sfl === 'mobile') echo ' selected'; ?>>휴대폰</option>
    </select>

    <label for="stx" class="sound_only">검색어</label>
    <input type="text" name="stx" id="stx" value="<?php echo get_text($f_stx); ?>" maxlength="100" class="frm_input">
    <button type="submit" class="btn_submit">검색</button>
    <a href="./guardian_recipient.php" class="btn_02 btn">초기화</a>
</form>

<form id="flist" name="flist" method="post" action="./guardian_recipient_update.php">
<input type="hidden" name="token" value="<?php echo get_admin_token(); ?>">
<input type="hidden" name="act" id="act_field" value="">

<div class="local_desc01 local_desc" style="display:flex; justify-content:space-between; align-items:center;">
    <div>총 <strong><?php echo number_format($total_count); ?></strong>명</div>
    <div>
        <a href="./guardian_recipient_form.php" class="btn btn_01">+ 수신자 추가</a>
        <button type="button" onclick="recipientBulk('toggle_active')" class="btn btn_02">선택 활성토글</button>
        <button type="button" onclick="recipientBulk('delete')" class="btn" style="background:#d32f2f;color:#fff;">선택 삭제</button>
    </div>
</div>

<div class="tbl_head01 tbl_wrap">
<table>
<caption>수신자 목록</caption>
<colgroup>
    <col style="width:36px;">
    <col style="width:120px;">
    <col>
    <col style="width:160px;">
    <col style="width:80px;">
    <col style="width:120px;">
</colgroup>
<thead>
<tr>
    <th><input type="checkbox" id="chkAll" onclick="recipientToggleAll(this)"></th>
    <th>이름</th>
    <th>이메일</th>
    <th>휴대폰</th>
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
        $rid = (int)$row['recipient_id'];
        $is_active = !empty($row['active']);
?>
<tr>
    <td><input type="checkbox" name="recipient_ids[]" value="<?php echo $rid; ?>"></td>
    <td><?php echo get_text($row['name']); ?></td>
    <td style="font-size:12px;"><?php echo get_text($row['email']); ?></td>
    <td style="font-size:12px;"><?php echo get_text($row['mobile']); ?></td>
    <td style="text-align:center;">
        <?php if ($is_active) { ?>
            <span style="color:#2e7d32;">● 활성</span>
        <?php } else { ?>
            <span style="color:#999;">● 비활성</span>
        <?php } ?>
    </td>
    <td style="text-align:center;">
        <a href="./guardian_recipient_form.php?recipient_id=<?php echo $rid; ?>" class="btn btn_03">수정</a>
    </td>
</tr>
<?php
    }
}
if ($row_count === 0) {
?>
<tr>
    <td colspan="6" style="text-align:center; color:#888; padding:60px 0;">
        등록된 수신자가 없습니다. 우측 상단 "+ 수신자 추가" 버튼으로 추가하세요.
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
function recipientToggleAll(cb) {
    var boxes = document.querySelectorAll('input[name="recipient_ids[]"]');
    for (var i = 0; i < boxes.length; i++) boxes[i].checked = cb.checked;
}
function recipientBulk(act) {
    var boxes = document.querySelectorAll('input[name="recipient_ids[]"]:checked');
    if (boxes.length === 0) { alert('대상을 선택하세요.'); return; }
    var msg = (act === 'delete')
        ? '선택한 ' + boxes.length + '명을 삭제하시겠습니까? 되돌릴 수 없습니다.'
        : '선택한 ' + boxes.length + '명의 활성 상태를 토글합니다.';
    if (!confirm(msg)) return;
    document.getElementById('act_field').value = act;
    document.forms['flist'].submit();
}
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
