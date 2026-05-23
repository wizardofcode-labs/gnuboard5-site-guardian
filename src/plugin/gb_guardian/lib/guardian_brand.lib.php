<?php
/**
 * 그누보드 운영지킴이 - 브랜드 정보
 *
 * 메인 브랜드 = WizardOfCode (사용자가 가장 많이 보는 이름)
 * 제작사     = K3SOFT (저작권 / 사업자 정보 보조 영역에만 사용)
 *
 * 본 라이브러리의 모든 함수는 guardian_config_get() 으로 사용자가 환경설정에서
 * 변경한 값을 우선 반영하고, 없을 때만 기본값을 사용한다.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.1.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * 메인 브랜드(WizardOfCode) 정보.
 *
 * @return array
 */
function guardian_get_brand_info()
{
    $get = function ($k, $d) {
        return function_exists('guardian_config_get') ? guardian_config_get($k, $d) : $d;
    };
    return array(
        'name'              => 'WizardOfCode',
        'tagline'           => '그누보드/영카트 전문 유지보수',
        'homepage'          => $get('brand_homepage',      'https://wizardofcode.kr'),
        'series_url'        => $get('series_page_url',     'https://wizardofcode.kr/?page_id=962'),
        'repair_url'        => $get('repair_url',          'https://wizardofcode.kr/?page_id=941'),
        'kakao_channel_url' => $get('kakao_channel_url',   'https://pf.kakao.com/_mkUxdn'),
        'logo_emoji'        => '🛡',
    );
}

/**
 * 제작사(K3SOFT) 정보.
 *
 * @return array
 */
function guardian_get_developer_info()
{
    return array(
        'company'   => 'K3SOFT',
        'role'      => '제작사',
        'copyright' => '© 2026 K3SOFT / WizardOfCode',
    );
}

/**
 * 운영지킴이 부제목.
 *
 * @return string
 */
function guardian_get_subtitle()
{
    return '그누보드5/영카트5 운영 진단키트';
}

/**
 * 메일 푸터의 작은 브랜드 표기 HTML 을 반환한다.
 *
 * brand_footer_enabled 가 OFF 면 빈 문자열 반환.
 *
 * @return string
 */
function guardian_render_brand_footer()
{
    if (function_exists('guardian_config_get')
        && !guardian_config_get('brand_footer_enabled', true)) {
        return '';
    }

    $brand = guardian_get_brand_info();
    $name       = htmlspecialchars($brand['name'],       ENT_QUOTES, 'UTF-8');
    $tagline    = htmlspecialchars($brand['tagline'],    ENT_QUOTES, 'UTF-8');
    $homepage   = htmlspecialchars($brand['homepage'],   ENT_QUOTES, 'UTF-8');
    $series_url = htmlspecialchars($brand['series_url'], ENT_QUOTES, 'UTF-8');

    return '<div style="margin-top:24px; padding-top:16px; border-top:1px solid #e5e7eb; text-align:center; font-family:\'맑은 고딕\',Arial,sans-serif;">'
         . '<div style="font-size:11px; color:#9ca3af; line-height:1.6; margin-bottom:6px;">'
         . '🛡 <strong style="color:#374151;">' . $name . '</strong> — ' . $tagline
         . '</div>'
         . '<div style="font-size:11px;">'
         . '<a href="' . $homepage . '" style="color:#6b7280; text-decoration:none; margin:0 6px;">제작자 사이트</a>'
         . ' · '
         . '<a href="' . $series_url . '" style="color:#6b7280; text-decoration:none; margin:0 6px;">' . $name . ' 시리즈 보기</a>'
         . '</div>'
         . '</div>';
}

/**
 * 일일/주간 요약 메일에 들어가는 미해결 FATAL 알림 박스.
 *
 * 광고가 아니라 진단 결과 알림. 30일 내 미해결 FATAL/ERROR/EXCEPTION 이
 * 1건 이상이면 빨간 박스 노출, 0건이면 빈 문자열.
 *
 * unresolved_alert_enabled 가 OFF 면 빈 문자열.
 *
 * @return string
 */
function guardian_render_unresolved_alert()
{
    global $g5;

    if (function_exists('guardian_config_get')
        && !guardian_config_get('unresolved_alert_enabled', true)) {
        return '';
    }
    if (empty($g5['guardian_log_table'])) return '';

    $row = sql_fetch(
        " SELECT COUNT(*) AS cnt
          FROM `{$g5['guardian_log_table']}`
          WHERE error_level IN ('FATAL', 'ERROR', 'EXCEPTION')
            AND resolved = 0
            AND created_dt > DATE_SUB(NOW(), INTERVAL 30 DAY) ",
        false
    );
    $count = !empty($row['cnt']) ? (int)$row['cnt'] : 0;
    if ($count === 0) return '';

    $count_safe = (int)$count;
    return '<div style="margin:20px 30px; padding:18px 22px; background:#fef2f2; border:2px solid #DC2626; border-radius:10px;">'
         . '<div style="font-size:15px; color:#991b1b; font-weight:bold; margin-bottom:6px;">'
         . '⚠ 미해결 FATAL/ERROR 오류 ' . $count_safe . '건 발견됨'
         . '</div>'
         . '<div style="font-size:13px; color:#7f1d1d; line-height:1.7;">'
         . '이 오류들은 사이트 운영에 영향을 줄 수 있습니다.<br>'
         . '관리자 페이지에서 확인하시고 처리해 주세요.'
         . '</div>'
         . '</div>';
}

/**
 * 환경설정 페이지의 제작자 정보 박스 HTML.
 *
 * 메인: WizardOfCode (큰 글씨, 노란색 강조)
 * 보조: K3SOFT 제작사 (작은 글씨, 박스 하단)
 *
 * @return string
 */
function guardian_render_developer_info_box()
{
    $brand = guardian_get_brand_info();
    $dev   = guardian_get_developer_info();

    $name          = htmlspecialchars($brand['name'],              ENT_QUOTES, 'UTF-8');
    $tagline       = htmlspecialchars($brand['tagline'],           ENT_QUOTES, 'UTF-8');
    $homepage      = htmlspecialchars($brand['homepage'],          ENT_QUOTES, 'UTF-8');
    $repair        = htmlspecialchars($brand['repair_url'],        ENT_QUOTES, 'UTF-8');
    $kakao_channel = htmlspecialchars($brand['kakao_channel_url'], ENT_QUOTES, 'UTF-8');
    $company       = htmlspecialchars($dev['company'],             ENT_QUOTES, 'UTF-8');
    $copyright     = htmlspecialchars($dev['copyright'],           ENT_QUOTES, 'UTF-8');

    $kakao_btn = '';
    if (!function_exists('guardian_config_get')
        || guardian_config_get('kakao_channel_enabled', true)) {
        $kakao_btn = '<a href="' . $kakao_channel . '" target="_blank" '
                   . 'style="display:inline-block; padding:8px 16px; background:#FEE500; color:#3c1e1e; text-decoration:none; border-radius:6px; font-size:12px; font-weight:bold;">'
                   . '💬 카톡 채널 (의뢰 문의)'
                   . '</a>';
    }

    return '<div style="margin-top:30px; padding:24px 28px; background:linear-gradient(135deg,#0F3460,#1a2842); color:#fff; border-radius:12px;">'
         // 메인 브랜드
         . '<h4 style="color:#fbbf24; margin:0 0 12px 0; font-size:18px;">'
         . '🛡 ' . $name
         . '</h4>'
         . '<div style="font-size:14px; line-height:1.8; margin-bottom:16px;">'
         . '본 솔루션은 <strong style="color:#fbbf24;">' . $name . '</strong>가 무료로 배포하는 그누보드 모니터링 도구입니다.<br>'
         . '<span style="color:rgba(255,255,255,0.7);">' . $tagline . '</span>'
         . '</div>'
         // CTA 3개
         . '<div style="margin-bottom:18px;">'
         . '<a href="' . $homepage . '" target="_blank" '
         . 'style="display:inline-block; margin-right:8px; margin-bottom:6px; padding:8px 16px; background:#fbbf24; color:#1a1a1a; text-decoration:none; border-radius:6px; font-size:12px; font-weight:bold;">'
         . '🔗 제작자 사이트'
         . '</a>'
         . '<a href="' . $repair . '" target="_blank" '
         . 'style="display:inline-block; margin-right:8px; margin-bottom:6px; padding:8px 16px; background:rgba(255,255,255,0.15); color:#fff; text-decoration:none; border-radius:6px; font-size:12px; font-weight:bold;">'
         . '🛠 유지보수 의뢰'
         . '</a>'
         . $kakao_btn
         . '</div>'
         // 응답 시간 안내
         . '<div style="font-size:11px; color:rgba(255,255,255,0.55); margin-bottom:14px;">'
         . '※ 카톡 채널은 평일 영업시간 24시간 이내 응답합니다.'
         . '</div>'
         // 제작사 정보 (보조)
         . '<div style="padding-top:14px; border-top:1px solid rgba(255,255,255,0.15); font-size:11px; color:rgba(255,255,255,0.5);">'
         . '제작사: ' . $company . ' · ' . $copyright
         . '</div>'
         . '</div>';
}

/**
 * 의뢰 페이지 URL 자동 생성. 오류 정보를 안전하게 첨부한다.
 *
 * 절대 첨부하지 않는 정보:
 *   - 사용자 IP
 *   - 전체 stack trace
 *   - 요청 URL 전체 (referer)
 *   - 세션 / 쿠키 / DB 접속 정보
 *
 * 첨부 가능 (모두 마스킹된 상태):
 *   - 호스트명 (도메인만)
 *   - 사이트명
 *   - 오류 해시 (식별용)
 *   - 오류 메시지 (200자 이내)
 *   - 파일명 (basename 만)
 *   - 발생 횟수
 *
 * @param  array|null $error_data
 * @return string
 */
function guardian_build_repair_url($error_data = null)
{
    $brand = guardian_get_brand_info();
    $base_url = $brand['repair_url'];

    if (empty($error_data) || !is_array($error_data)) {
        return $base_url . (strpos($base_url, '?') !== false ? '&' : '?') . 'source=guardian_v1.1';
    }

    global $config;
    $host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
    // 호스트명도 잠재적 위험 문자 제거
    $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host);

    $error_msg = '';
    if (isset($error_data['error_message']) && $error_data['error_message'] !== '') {
        $error_msg = (string)$error_data['error_message'];
    } elseif (isset($error_data['message']) && $error_data['message'] !== '') {
        $error_msg = (string)$error_data['message'];
    }
    if (function_exists('mb_substr') && $error_msg !== '') {
        $error_msg = mb_substr($error_msg, 0, 200, 'UTF-8');
    } elseif ($error_msg !== '') {
        $error_msg = substr($error_msg, 0, 200);
    }

    $error_file = '';
    if (isset($error_data['error_file']) && $error_data['error_file'] !== '') {
        $error_file = basename((string)$error_data['error_file']);
    } elseif (isset($error_data['file']) && $error_data['file'] !== '') {
        $error_file = basename((string)$error_data['file']);
    }

    $level = '';
    if (isset($error_data['error_level']) && $error_data['error_level'] !== '') {
        $level = (string)$error_data['error_level'];
    } elseif (isset($error_data['level']) && $error_data['level'] !== '') {
        $level = (string)$error_data['level'];
    }

    $line = 0;
    if (isset($error_data['error_line']) && $error_data['error_line'] !== '') {
        $line = (int)$error_data['error_line'];
    } elseif (isset($error_data['line']) && $error_data['line'] !== '') {
        $line = (int)$error_data['line'];
    }

    $params = array(
        'source'     => 'guardian_v1.1',
        'site'       => $host,
        'site_name'  => isset($config['cf_title']) ? (string)$config['cf_title'] : '',
        'error_hash' => isset($error_data['error_hash']) ? (string)$error_data['error_hash'] : '',
        'level'      => $level,
        'message'    => $error_msg,
        'file'       => $error_file,
        'line'       => $line,
        'count'      => isset($error_data['occurrence_count']) ? (int)$error_data['occurrence_count'] : 1,
    );
    // 빈 값 제거
    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== 0 && $v !== null;
    });

    $separator = (strpos($base_url, '?') !== false) ? '&' : '?';
    return $base_url . $separator . http_build_query($params);
}
