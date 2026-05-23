<?php
/**
 * 그누보드 운영지킴이 - 대시보드
 *
 * 화면 구성:
 *   1. 통계 카드 4개 (오늘 / 미해결 / 7일 / 최다발생 파일)
 *   2. 최근 7일 일별 오류 추이 (Chart.js)
 *   3. 최다 발생 오류 TOP 5
 *   4. 미처리 Fatal/Error/Exception 최근 10건
 *
 * Chart.js 는 CDN 로드. 폐쇄망에서 로드 실패 시 안내 박스로 대체.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
$sub_menu = "700100";
include_once('./_common.php');

auth_check_menu($auth, $sub_menu, 'r');

if (defined('GUARDIAN_LIB_PATH')) {
    include_once(GUARDIAN_LIB_PATH . '/guardian_admin.lib.php');
    include_once(GUARDIAN_LIB_PATH . '/guardian_chart.lib.php');
} else {
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_admin.lib.php');
    include_once(G5_PLUGIN_PATH . '/gb_guardian/lib/guardian_chart.lib.php');
}

// ---------------------------------------------------------------------
// 1. 통계 카드 데이터 (모두 sql_fetch + false 로 보호)
// ---------------------------------------------------------------------
$today_count = 0;
$row = sql_fetch(
    " SELECT COALESCE(SUM(occurrence_count), 0) AS cnt
      FROM `{$g5['guardian_log_table']}`
      WHERE DATE(created_dt) = CURDATE() ",
    false
);
if (!empty($row)) $today_count = (int)$row['cnt'];

$unresolved_count = guardian_count_unresolved();

$weekly_count = 0;
$row = sql_fetch(
    " SELECT COALESCE(SUM(occurrence_count), 0) AS cnt
      FROM `{$g5['guardian_log_table']}`
      WHERE created_dt >= DATE_SUB(NOW(), INTERVAL 7 DAY) ",
    false
);
if (!empty($row)) $weekly_count = (int)$row['cnt'];

$top_file_name = '-';
$top_file_count = 0;
$row = sql_fetch(
    " SELECT error_file, SUM(occurrence_count) AS total
      FROM `{$g5['guardian_log_table']}`
      WHERE created_dt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        AND error_file <> ''
      GROUP BY error_file
      ORDER BY total DESC
      LIMIT 1 ",
    false
);
if (!empty($row['error_file'])) {
    $top_file_name = $row['error_file'];
    $top_file_count = (int)$row['total'];
}

// ---------------------------------------------------------------------
// 2. 차트 데이터
// ---------------------------------------------------------------------
$chart = guardian_chart_daily_data(7);
$top_errors  = guardian_chart_top_errors(5, 7);
$recent_unresolved = guardian_chart_recent_unresolved(10);

// 차트 색상 매핑 (라인 차트의 등급별 색)
$level_colors = array(
    'FATAL'      => '#d32f2f',
    'ERROR'      => '#e53935',
    'EXCEPTION'  => '#c62828',
    'WARNING'    => '#f57c00',
    'DB'         => '#ef6c00',
    'NOTICE'     => '#1976d2',
    'DEPRECATED' => '#9e9e9e',
);
$datasets_js = array();
foreach ($chart['datasets'] as $lv => $values) {
    $color = isset($level_colors[$lv]) ? $level_colors[$lv] : '#666666';
    $datasets_js[] = array(
        'label' => $lv,
        'data'  => $values,
        'borderColor' => $color,
        'backgroundColor' => $color . '33',
        'tension' => 0.2,
        'fill' => false,
    );
}

$g5['title'] = '운영지킴이 — 그누보드5/영카트5 운영 진단키트 — 대시보드';
include_once(G5_ADMIN_PATH . '/admin.head.php');
?>
<style>a.btn,a.btn_01,a.btn_02,a.btn_03,a.btn_submit,a.btn_frmline{text-decoration:none}a.btn:hover,a.btn_01:hover,a.btn_02:hover,a.btn_03:hover{text-decoration:none}</style>

<!-- ===== 헤더 액션 ===== -->
<div class="local_desc01 local_desc" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
        <strong>운영지킴이 v<?php echo defined('GUARDIAN_VERSION') ? get_text(GUARDIAN_VERSION) : '1.0.0'; ?></strong>
        <span style="color:#888; margin-left:10px;">
            <?php if (!empty($config['cf_guardian_use'])) { ?>
                <span style="color:#2e7d32;">● 활성</span>
            <?php } else { ?>
                <span style="color:#c33;">● 비활성</span> (환경설정에서 활성화하세요)
            <?php } ?>
        </span>
    </div>
    <div>
        <a href="<?php echo G5_ADMIN_URL; ?>/guardian_log.php" class="btn btn_02">오류 로그 전체 보기</a>
        <a href="<?php echo G5_ADMIN_URL; ?>/guardian_config.php" class="btn btn_01">환경설정</a>
    </div>
</div>

<!-- ===== 통계 카드 4개 ===== -->
<div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:15px; margin-top:20px;">
    <div style="background:#fff; border:1px solid #e0e0e0; border-left:4px solid #1976d2; padding:18px 20px; border-radius:4px;">
        <div style="color:#888; font-size:12px; margin-bottom:8px;">오늘 오류</div>
        <div style="font-size:28px; font-weight:bold; color:#1976d2;"><?php echo number_format($today_count); ?></div>
        <div style="color:#aaa; font-size:11px;">건 (occurrence_count 합)</div>
    </div>
    <div style="background:#fff; border:1px solid #e0e0e0; border-left:4px solid #d32f2f; padding:18px 20px; border-radius:4px;">
        <div style="color:#888; font-size:12px; margin-bottom:8px;">미해결 (Fatal/Error/Exception)</div>
        <div style="font-size:28px; font-weight:bold; color:#d32f2f;"><?php echo number_format($unresolved_count); ?></div>
        <div style="color:#aaa; font-size:11px;">건 (조치 필요)</div>
    </div>
    <div style="background:#fff; border:1px solid #e0e0e0; border-left:4px solid #f57c00; padding:18px 20px; border-radius:4px;">
        <div style="color:#888; font-size:12px; margin-bottom:8px;">지난 7일</div>
        <div style="font-size:28px; font-weight:bold; color:#f57c00;"><?php echo number_format($weekly_count); ?></div>
        <div style="color:#aaa; font-size:11px;">건</div>
    </div>
    <div style="background:#fff; border:1px solid #e0e0e0; border-left:4px solid #2e7d32; padding:18px 20px; border-radius:4px;">
        <div style="color:#888; font-size:12px; margin-bottom:8px;">최다 발생 파일 (7일)</div>
        <div style="font-size:14px; font-weight:bold; color:#2e7d32; word-break:break-all;">
            <?php echo guardian_truncate_text($top_file_name, 30); ?>
        </div>
        <div style="color:#aaa; font-size:11px;"><?php echo number_format($top_file_count); ?>회</div>
    </div>
</div>

<!-- ===== 차트 ===== -->
<h2 class="h2_frm" style="margin-top:30px;">📊 최근 7일 오류 추이</h2>
<div style="background:#fff; border:1px solid #e0e0e0; padding:20px; border-radius:4px; min-height:300px;">
    <div id="chart_container" style="position:relative; height:280px;">
        <canvas id="guardian_daily_chart"></canvas>
    </div>
</div>

<!-- ===== 최다 발생 오류 TOP 5 ===== -->
<h2 class="h2_frm" style="margin-top:30px;">🔥 최다 발생 오류 TOP 5 (7일)</h2>
<div class="tbl_head01 tbl_wrap">
<table>
<colgroup>
    <col style="width:80px;">
    <col>
    <col style="width:240px;">
    <col style="width:80px;">
    <col style="width:120px;">
</colgroup>
<thead>
<tr>
    <th scope="col">등급</th>
    <th scope="col">메시지</th>
    <th scope="col">파일:라인</th>
    <th scope="col">발생</th>
    <th scope="col">최근</th>
</tr>
</thead>
<tbody>
<?php if (empty($top_errors)) { ?>
<tr><td colspan="5" style="text-align:center; color:#888; padding:40px 0;">데이터가 없습니다.</td></tr>
<?php } else { foreach ($top_errors as $row) { ?>
<tr>
    <td><?php echo guardian_level_badge($row['error_level']); ?></td>
    <td><?php echo guardian_truncate_text($row['error_message'], 100); ?></td>
    <td style="font-size:12px; color:#555;">
        <?php echo guardian_truncate_text($row['error_file'], 40); ?>:<?php echo (int)$row['error_line']; ?>
    </td>
    <td style="text-align:center; font-weight:bold; color:#d32f2f;"><?php echo number_format((int)$row['total']); ?>회</td>
    <td style="font-size:12px; color:#666;"><?php echo guardian_format_datetime($row['last_dt']); ?></td>
</tr>
<?php } } ?>
</tbody>
</table>
</div>

<!-- ===== 미처리 최근 10건 ===== -->
<h2 class="h2_frm" style="margin-top:30px;">📌 미처리 Fatal / Error / Exception 최근 10건</h2>
<div class="tbl_head01 tbl_wrap">
<table>
<colgroup>
    <col style="width:80px;">
    <col>
    <col style="width:240px;">
    <col style="width:80px;">
    <col style="width:120px;">
</colgroup>
<thead>
<tr>
    <th scope="col">등급</th>
    <th scope="col">메시지</th>
    <th scope="col">파일:라인</th>
    <th scope="col">발생</th>
    <th scope="col">최근</th>
</tr>
</thead>
<tbody>
<?php if (empty($recent_unresolved)) { ?>
<tr><td colspan="5" style="text-align:center; color:#2e7d32; padding:40px 0;">✅ 미처리 Fatal/Error/Exception 이 없습니다. 사이트가 안정적입니다.</td></tr>
<?php } else { foreach ($recent_unresolved as $row) {
    $log_id = (int)$row['log_id'];
    $occured = !empty($row['last_occurred_dt']) ? $row['last_occurred_dt'] : $row['created_dt'];
?>
<tr>
    <td><?php echo guardian_level_badge($row['error_level']); ?></td>
    <td>
        <a href="<?php echo G5_ADMIN_URL; ?>/guardian_log.php?stx=<?php echo urlencode($row['error_message']); ?>" style="color:#222; text-decoration:none;">
            <?php echo guardian_truncate_text($row['error_message'], 100); ?>
        </a>
    </td>
    <td style="font-size:12px; color:#555;">
        <?php echo guardian_truncate_text($row['error_file'], 40); ?>:<?php echo (int)$row['error_line']; ?>
    </td>
    <td style="text-align:center;"><?php echo number_format((int)$row['occurrence_count']); ?>회</td>
    <td style="font-size:12px; color:#666;"><?php echo guardian_format_datetime($occured); ?></td>
</tr>
<?php } } ?>
</tbody>
</table>
</div>
<div style="text-align:right; margin-top:10px;">
    <a href="<?php echo G5_ADMIN_URL; ?>/guardian_log.php?resolved=0&amp;level=" class="btn btn_02">미해결 전체 보기</a>
</div>

<!-- ===== Chart.js — CDN + 폴백 ===== -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var labels   = <?php echo json_encode($chart['labels']); ?>;
    var datasets = <?php echo json_encode($datasets_js); ?>;

    function renderFallback(msg) {
        var container = document.getElementById('chart_container');
        if (!container) return;
        container.innerHTML =
            '<div style="display:flex; align-items:center; justify-content:center; height:100%; color:#999; text-align:center; padding:40px;">' +
            (msg || '차트를 표시할 수 없습니다.') +
            '</div>';
    }

    if (typeof Chart === 'undefined') {
        renderFallback(
            '차트 라이브러리(Chart.js) 로드에 실패했습니다.<br>' +
            '서버에서 CDN(<code>cdn.jsdelivr.net</code>) 에 접근할 수 있는지 확인하거나,<br>' +
            '관리자에게 문의하세요.'
        );
        return;
    }

    if (!datasets || datasets.length === 0) {
        renderFallback('표시할 데이터가 없습니다. 운영지킴이를 활성화하고 잠시 후 다시 확인하세요.');
        return;
    }

    var ctx = document.getElementById('guardian_daily_chart');
    try {
        new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { mode: 'index', intersect: false }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    } catch (e) {
        renderFallback('차트 렌더링 중 오류: ' + e.message);
    }
})();
</script>

<?php
include_once(G5_ADMIN_PATH . '/admin.tail.php');
