<?php
/**
 * 그누보드 운영지킴이 - WizardOfCode 시리즈 메타데이터
 *
 * 환경설정 페이지에 정적으로 표시되는 시리즈 솔루션 4개의 메타데이터.
 *
 * 절대 원칙:
 *   - 자동 솔루션 감지 로직 금지 (function_exists / file_exists 체크 X)
 *   - 점수 / 매칭 / 추천 알고리즘 금지
 *   - 사용자가 직접 카드를 보고 선택하도록 정적 표시만
 *   - "함께 쓰면 좋은 도구 안내" 톤 (광고 X)
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.1.2
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * WizardOfCode 시리즈 솔루션 메타데이터 (정적 배열).
 *
 * @return array
 */
function guardian_get_partner_solutions()
{
    /*
     * 유료/자체배포 버전: 시리즈 상품 메타데이터 배열을 여기 정의.
     * 각 항목 구조: name, subtitle, description, url, cta_label, icon, category
     * 무료 배포본에서는 빈 배열을 반환한다. guardian_config 값으로 되살릴 수 있다.
     */
    return array();
}

/**
 * 환경설정 페이지의 시리즈 섹션 HTML 을 반환한다.
 *
 * series_section_enabled 가 OFF 면 빈 문자열.
 *
 * @return string
 */
function guardian_render_series_section()
{
    if (function_exists('guardian_config_get')
        && !guardian_config_get('series_section_enabled', true)) {
        return '';
    }

    $partners = guardian_get_partner_solutions();

    // 노출할 시리즈 항목이 없으면 섹션 전체를 그리지 않는다
    if (empty($partners)) {
        return '';
    }
    $brand    = function_exists('guardian_get_brand_info') ? guardian_get_brand_info() : array('name' => 'WizardOfCode');
    $brand_name = htmlspecialchars((string)$brand['name'], ENT_QUOTES, 'UTF-8');

    $html  = '<h3 style="margin-top:30px; color:#0f3460; border-bottom:2px solid #0f3460; padding-bottom:5px;">';
    $html .= '🛠 ' . $brand_name . ' 시리즈 솔루션';
    $html .= '</h3>';
    $html .= '<p style="font-size:13px; color:#666; margin:10px 0 16px 0;">';
    $html .= '운영지킴이와 함께 사용하면 더욱 강력해지는 ' . $brand_name . ' 시리즈 솔루션입니다. ';
    $html .= '<span style="color:#888;">(자동 감지하지 않으니 필요한 솔루션을 직접 확인 후 선택하세요.)</span>';
    $html .= '</p>';

    $html .= '<div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">';

    foreach ($partners as $key => $p) {
        $name     = htmlspecialchars((string)$p['name'],        ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string)$p['subtitle'],    ENT_QUOTES, 'UTF-8');
        $desc     = htmlspecialchars((string)$p['description'], ENT_QUOTES, 'UTF-8');
        $url      = htmlspecialchars((string)$p['url'],         ENT_QUOTES, 'UTF-8');
        $cta      = htmlspecialchars((string)$p['cta_label'],   ENT_QUOTES, 'UTF-8');
        $icon     = htmlspecialchars((string)$p['icon'],        ENT_QUOTES, 'UTF-8');

        // 가격은 의도적으로 미표시 — 판매처에서 사용자가 직접 최신 가격을
        // 확인하도록 유도한다 (가격 변동 대응 부담 0).
        $html .= '<div style="padding:18px 20px; background:#f9fafb; border-radius:10px; border:1px solid #e5e7eb;">'
              . '<div style="margin-bottom:6px;">'
              . '<span style="font-size:24px;">' . $icon . '</span>'
              . '</div>'
              . '<div style="font-size:14px; font-weight:bold; color:#0F3460; margin-bottom:4px;">' . $name . '</div>'
              . '<div style="font-size:11px; color:#666; margin-bottom:8px;">' . $subtitle . '</div>'
              . '<div style="font-size:12px; color:#374151; line-height:1.55; margin-bottom:10px; min-height:48px;">' . $desc . '</div>'
              . '<a href="' . $url . '" target="_blank" '
              . 'style="display:inline-block; padding:6px 14px; background:#0F3460; color:#fff; text-decoration:none; border-radius:4px; font-size:12px;">'
              . $cta . ' →'
              . '</a>'
              . '</div>';
    }
    $html .= '</div>';

    return $html;
}
