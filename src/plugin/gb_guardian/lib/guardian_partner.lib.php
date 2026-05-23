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
 * @version 1.1.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * WizardOfCode 시리즈 솔루션 메타데이터 (정적 배열).
 *
 * @return array
 */
function guardian_get_partner_solutions()
{
    // 가격 정보는 의도적으로 포함하지 않는다. 판매처 / 제작자 정책 변경 시
    // 운영지킴이를 재배포해야 하는 부담이 생기므로, 사용자가 클릭하면 최신 가격을
    // 판매처에서 직접 확인하도록 유도한다.
    return array(
        'smtp_manager_v2' => array(
            'name'        => 'SMTP Manager v2.0',
            'subtitle'    => '캠페인 발송 + 예약 메일 + 자동 수신거부',
            'description' => 'SMTP 인증 메일 발송 + 캠페인 관리 + 발송 모니터링. 운영지킴이 알림 메일을 안정적으로 발송합니다.',
            'url'         => 'https://sir.kr/contents-mall/items/1772284664',
            'cta_label'   => '자세히 보기',
            'icon'        => '📧',
            'category'    => 'mail',
        ),
        'aligo_kakao' => array(
            'name'        => '알리고 카카오 알림톡',
            'subtitle'    => 'SMS + 알림톡 + 휴대폰 인증 통합',
            'description' => '카카오 알림톡으로 SMS보다 저렴하게 발송. 휴대폰 인증까지 한 패키지.',
            'url'         => 'https://sir.kr/contents-mall/items/1734924774',
            'cta_label'   => '자세히 보기',
            'icon'        => '💬',
            'category'    => 'kakao',
        ),
        'aligo_sms' => array(
            'name'        => '알리고 SMS 문자 발송',
            'subtitle'    => '코어 변경 최소 + 휴대폰 인증',
            'description' => '알리고 SMS API 통합. 휴대폰 문자 인증 포함. 코어 변경 최소화 설계.',
            'url'         => 'https://sir.kr/contents-mall/items/1732015631',
            'cta_label'   => '자세히 보기',
            'icon'        => '📱',
            'category'    => 'sms',
        ),
        'wizardofcode_repair' => array(
            'name'        => 'WizardOfCode 유지보수',
            'subtitle'    => '그누보드/영카트 전문 수정 의뢰',
            'description' => '운영지킴이 제작자가 직접 수정합니다. 평일 24시간 이내 응답. 첫 진단 무료.',
            'url'         => 'https://wizardofcode.kr/?page_id=941',
            'cta_label'   => '의뢰 페이지',
            'icon'        => '🛠',
            'category'    => 'service',
        ),
    );
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
