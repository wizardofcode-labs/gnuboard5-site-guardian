<?php
/**
 * 그누보드 운영지킴이 - 메일 / SMS 템플릿 렌더링
 *
 * 책임:
 *   - 채널별 기본 템플릿 로드 (templates/*)
 *   - {VAR} 토큰 치환
 *   - error_data 에서 템플릿 변수 빌드
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * 채널별 템플릿을 로드하고 변수 치환까지 마친 결과 반환.
 *
 * @param  string $channel    "email" / "sms" / "kakao"
 * @param  array  $error_data 오류 데이터 (캡처 형식)
 * @return array              array('subject' => ..., 'body' => ...)
 */
function guardian_render_template($channel, array $error_data)
{
    $template = guardian_load_template($channel);
    $vars     = guardian_build_template_vars($error_data);

    // 카카오 알림톡 템플릿은 비즈센터 등록본과 동일한 `#{변수명}` 형식을
    // 사용한다. 메일 / SMS 는 운영자가 직접 편집하는 표준 `{KEY}` 형식.
    $is_kakao = ((string)$channel === 'kakao');
    $sub_func = $is_kakao && function_exists('guardian_replace_vars_flexible')
              ? 'guardian_replace_vars_flexible'
              : 'guardian_replace_vars';

    return array(
        'subject' => isset($template['subject']) ? $sub_func($template['subject'], $vars) : '',
        'body'    => isset($template['body'])    ? $sub_func($template['body'], $vars)    : '',
    );
}

/**
 * error_data 에서 템플릿 치환용 변수 사전을 만든다.
 *
 * 모든 값은 마스킹된 그대로 사용 (캡처 단계에서 이미 마스킹됨).
 * 메일 본문에 들어가도 안전하다.
 *
 * @param  array $error_data
 * @return array
 */
function guardian_build_template_vars(array $error_data)
{
    global $config;

    $admin_log_url = G5_ADMIN_URL . '/guardian_log.php';
    if (!empty($error_data['error_hash'])) {
        $admin_log_url .= '?stx=' . urlencode((string)$error_data['error_hash']);
    }

    // 캡처가 만드는 $data 는 'level / message / file / line' 키를 쓰고,
    // 테스트 발송 가짜 데이터는 'error_level / error_message / error_file / error_line'
    // 키를 쓴다. 두 케이스 모두 정상 치환되도록 둘 다 폴백으로 검사한다.
    $pick = function ($keys, $default) use ($error_data) {
        foreach ($keys as $k) {
            if (isset($error_data[$k]) && $error_data[$k] !== '' && $error_data[$k] !== null) {
                return (string)$error_data[$k];
            }
        }
        return (string)$default;
    };

    // 브랜드 변수 — guardian_brand.lib.php 가 로드된 환경에서만 채움
    $subtitle               = function_exists('guardian_get_subtitle')         ? guardian_get_subtitle()         : '';
    $brand_footer_html      = function_exists('guardian_render_brand_footer')  ? guardian_render_brand_footer()  : '';
    $unresolved_alert_html  = function_exists('guardian_render_unresolved_alert') ? guardian_render_unresolved_alert() : '';

    return array(
        '{SITE_NAME}'              => isset($config['cf_title'])           ? (string)$config['cf_title'] : '',
        '{SITE_URL}'               => defined('G5_URL') ? G5_URL : '',
        '{ERROR_LEVEL}'            => $pick(array('error_level',   'level'),   ''),
        '{ERROR_MESSAGE}'          => $pick(array('error_message', 'message'), ''),
        '{ERROR_FILE}'             => $pick(array('error_file',    'file'),    ''),
        '{ERROR_LINE}'             => $pick(array('error_line',    'line'),    '0'),
        '{ERROR_TIME}'             => $pick(array('created_dt'),              date('Y-m-d H:i:s')),
        '{ERROR_URL}'              => $pick(array('request_url'),             ''),
        '{OCCURRENCE_COUNT}'       => isset($error_data['occurrence_count']) && (int)$error_data['occurrence_count'] > 0
                                          ? (string)(int)$error_data['occurrence_count']
                                          : '1',
        '{ADMIN_LOG_URL}'          => $admin_log_url,
        // 신규 ★
        '{SUBTITLE}'               => $subtitle,
        '{BRAND_FOOTER}'           => $brand_footer_html,
        '{UNRESOLVED_FATAL_ALERT}' => $unresolved_alert_html,
    );
}

/**
 * 토큰 치환. 단순 str_replace 라 정규식 비용 없음.
 *
 * @param  string $text
 * @param  array  $vars
 * @return string
 */
function guardian_replace_vars($text, array $vars)
{
    if ($text === null || $text === '') return '';
    return str_replace(array_keys($vars), array_values($vars), (string)$text);
}

/**
 * 변수 치환 — 4가지 형식을 모두 인식한다.
 *
 * 카카오 알림톡 비즈센터의 표준 변수 형식은 `#{변수명}` (소문자 + 샵 prefix)
 * 이다. 본 함수는 카카오 알림톡 본문 / 강조 표기 치환에 사용되며, 사용자가
 * 입력 시 대소문자 / 샵 prefix 를 혼용해도 정상 치환되도록 4가지 변형을
 * 모두 매칭한다 (관용 처리). 단, 외부 노출 문서·UI 에는 표준인 `#{변수명}`
 * 형식만 안내한다.
 *
 *   #{error_level}   ← ★ 카카오 표준 (권장)
 *   #{ERROR_LEVEL}   ← 관용 (대문자도 허용)
 *   {error_level}    ← 관용 (샵 누락도 허용)
 *   {ERROR_LEVEL}    ← 운영지킴이 내부 표준 (메일 / SMS 템플릿용)
 *
 * 메일 / SMS 본문 파일(templates/*.html, .txt)은 운영자가 직접 편집하므로
 * 빠른 단일 형식만 지원하는 guardian_replace_vars() 를 그대로 사용한다.
 *
 * @param  string $text
 * @param  array  $vars 키는 `{ERROR_LEVEL}` 같은 표준 형식
 * @return string
 */
function guardian_replace_vars_flexible($text, array $vars)
{
    if ($text === null || $text === '') return '';
    $text = (string)$text;

    // 표준 형식 키를 4가지 변형으로 확장.
    //
    // ★ 등록 순서 매우 중요 ★
    // str_replace 는 배열 순서대로 매칭한다. 짧은 형식인 `{error_message}` 가
    // 먼저 등록되면 `#{error_message}` 의 부분 문자열로 먼저 치환되어
    // `#에러메시지` 처럼 `#` 가 잔류하는 버그가 생긴다. 따라서 긴 형식
    // `#{...}` 을 반드시 먼저 등록해 우선 매칭되도록 한다.
    $expanded = array();
    $fallback = array();
    foreach ($vars as $key => $value) {
        if (preg_match('/^\{(.+)\}$/', (string)$key, $m)) {
            $name  = $m[1];                  // 'ERROR_LEVEL'
            $lower = strtolower($name);      // 'error_level'

            // 1) 긴 형식 (#{...}) 먼저
            $expanded['#{' . $name . '}']    = $value;   // #{ERROR_LEVEL}
            $expanded['#{' . $lower . '}']   = $value;   // #{error_level}
            // 2) 그다음 짧은 형식 ({...})
            $expanded['{' . $name . '}']     = $value;   // {ERROR_LEVEL}
            $expanded['{' . $lower . '}']    = $value;   // {error_level}
        } else {
            // 비표준 키도 폴백으로 그대로 매칭
            $fallback[(string)$key] = $value;
        }
    }
    if (!empty($fallback)) {
        $expanded = array_merge($expanded, $fallback);
    }

    return str_replace(array_keys($expanded), array_values($expanded), $text);
}

/**
 * 채널별 기본 템플릿 본문을 로드한다.
 *
 * 템플릿 파일이 없거나 읽기 실패 시 인라인 폴백 사용 — 사이트 절대 안 죽이기 원칙.
 *
 * @param  string $channel
 * @return array  array('subject' => ..., 'body' => ...)
 */
function guardian_load_template($channel)
{
    $template_dir = defined('GUARDIAN_PATH') ? GUARDIAN_PATH . '/templates' : '';

    $channel = (string)$channel;

    if ($channel === 'email') {
        $body_file = $template_dir . '/mail_default.html';
        $body = '';
        if ($template_dir !== '' && @is_readable($body_file)) {
            $contents = @file_get_contents($body_file);
            if ($contents !== false) {
                $body = $contents;
            }
        }
        if ($body === '') {
            $body = guardian_template_inline_email();
        }
        return array(
            'subject' => '[{SITE_NAME}] [{ERROR_LEVEL}] 사이트 오류 알림',
            'body'    => $body,
        );
    }

    if ($channel === 'sms') {
        $body_file = $template_dir . '/sms_default.txt';
        $body = '';
        if ($template_dir !== '' && @is_readable($body_file)) {
            $contents = @file_get_contents($body_file);
            if ($contents !== false) {
                $body = $contents;
            }
        }
        if ($body === '') {
            $body = guardian_template_inline_sms();
        }
        return array('subject' => '', 'body' => $body);
    }

    if ($channel === 'kakao') {
        // 카톡은 SMS 와 본문 제약이 다르다 (카카오 비즈센터 사전 등록 필수,
        // 광고성 키워드 회피, 변수 비율 50% 이하).
        // 별도 파일 templates/kakao_default.txt 로 분리.
        $body_file = $template_dir . '/kakao_default.txt';
        $body = '';
        if ($template_dir !== '' && @is_readable($body_file)) {
            $contents = @file_get_contents($body_file);
            if ($contents !== false) {
                $body = $contents;
            }
        }
        if ($body === '') {
            $body = guardian_template_inline_kakao();
        }
        return array('subject' => '', 'body' => $body);
    }

    return array('subject' => '', 'body' => '');
}

/**
 * 메일 템플릿 파일 읽기 실패 시의 인라인 폴백.
 *
 * @return string HTML 메일 본문 템플릿
 */
function guardian_template_inline_email()
{
    return '<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><title>{SITE_NAME} 운영지킴이</title></head>'
         . '<body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;">'
         . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:6px;padding:20px;">'
         . '<h2 style="margin-top:0;color:#0f3460;">[{ERROR_LEVEL}] {SITE_NAME} 사이트 오류</h2>'
         . '<p style="background:#fef2f2;border-left:4px solid #dc2626;padding:10px;word-break:break-all;">{ERROR_MESSAGE}</p>'
         . '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
         . '<tr><th style="text-align:left;padding:8px;background:#f9fafb;width:30%;">발생 시각</th><td style="padding:8px;">{ERROR_TIME}</td></tr>'
         . '<tr><th style="text-align:left;padding:8px;background:#f9fafb;">파일:라인</th><td style="padding:8px;">{ERROR_FILE}:{ERROR_LINE}</td></tr>'
         . '<tr><th style="text-align:left;padding:8px;background:#f9fafb;">발생 횟수</th><td style="padding:8px;color:#dc2626;font-weight:bold;">{OCCURRENCE_COUNT}회</td></tr>'
         . '<tr><th style="text-align:left;padding:8px;background:#f9fafb;">요청 URL</th><td style="padding:8px;word-break:break-all;font-size:11px;">{ERROR_URL}</td></tr>'
         . '</table>'
         . '<p style="margin-top:20px;text-align:center;">'
         . '<a href="{ADMIN_LOG_URL}" style="display:inline-block;padding:10px 20px;background:#0f3460;color:#fff;text-decoration:none;border-radius:4px;">관리자 페이지에서 자세히 보기</a>'
         . '</p>'
         . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:20px 0;">'
         . '<div style="font-size:11px;color:#888;text-align:center;">{SITE_NAME} 운영지킴이가 자동 발송한 알림입니다.</div>'
         . '</div></body></html>';
}

/**
 * 카카오 알림톡 템플릿 파일 읽기 실패 시의 인라인 폴백.
 *
 * 카톡 본문 제약: 광고성 키워드 회피, 변수 비율 50% 이하, 사용자가 카카오
 * 비즈메시지 센터에 사전 등록한 본문과 일치해야 발송됨.
 *
 * @return string 카톡 본문 템플릿
 */
function guardian_template_inline_kakao()
{
    // 카카오 알림톡은 비즈메시지 센터 등록 시 변수 형식이 `#{변수명}` 으로
    // 고정되어 있으며, 앞에 # 가 빠진 `{변수명}` 은 인식되지 않는다.
    // 본 인라인 폴백도 같은 형식을 사용해 사용자 등록 본문과 일치시킨다.
    return "[#{site_name}] 사이트 오류 알림\n\n"
         . "#{error_level} 등급 오류가 발생했습니다.\n"
         . "오류: #{error_message}\n"
         . "파일: #{error_file}\n"
         . "시각: #{error_time}\n\n"
         . "상세 내용은 관리자 페이지에서 확인하시기 바랍니다.";
}

/**
 * SMS 템플릿 파일 읽기 실패 시의 인라인 폴백.
 *
 * @return string SMS 본문 템플릿
 */
function guardian_template_inline_sms()
{
    return "[{SITE_NAME}]\n{ERROR_LEVEL} 오류 발생\n{ERROR_MESSAGE}\n\n발생: {ERROR_TIME}\n파일: {ERROR_FILE}:{ERROR_LINE}\n횟수: {OCCURRENCE_COUNT}회\n\n자세히: {ADMIN_LOG_URL}";
}
