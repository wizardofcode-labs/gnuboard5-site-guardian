<?php
/**
 * 그누보드 운영지킴이 - 알리고 카카오 알림톡 어댑터
 *
 * 알리고 카톡 솔루션의 KakaoAlimtalk 클래스를 래핑.
 *   - 인스턴스: new KakaoAlimtalk($apikey, $userid)
 *   - 호출    : $obj->sendAlimtalk($senderkey, $tpl_code, $sender, $receiver, ...)
 *
 * 인증 정보 (그누보드 코어 + 운영지킴이 자체 컬럼 조합):
 *   - cf_aligo_id           : 알리고 사용자 ID (SMS와 동일)
 *   - cf_aligo_key          : 알리고 API 키 (SMS와 동일)
 *   - cf_aligo_kakao_sdkey  : 카카오 발신키 (senderkey, 알림톡 솔루션이 저장)
 *   - cf_aligo_sender       : SMS 폴백용 발신번호
 *   - cf_guardian_kakao_template_code : 알림톡 템플릿 코드 (운영지킴이 자체 컬럼)
 *
 * 카카오 알림톡 특수성:
 *   - 사전 등록·승인된 템플릿만 발송 가능 (자유 메시지 불가)
 *   - 실패 시 SMS 폴백 옵션 (failover='Y')
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * 카톡 알림톡 발송. guardian_notifier 라우터가 호출.
 *
 * @param  string $to         수신자 휴대폰 번호 (카톡 가입 여부는 알리고가 판단)
 * @param  string $message    본문 (사전 승인된 템플릿 내용과 일치해야 함)
 * @param  array  $error_data 변수 치환에 사용 (현재는 사용 안 하나 인터페이스 일관성)
 * @return array
 */
function guardian_send_kakao($to, $message, array $error_data = array())
{
    global $config;

    // ---- 사전 검증 ----
    if (!guardian_is_aligo_kakao_available()) {
        return array('success' => false, 'reason' => '알리고 카톡 솔루션 미설치');
    }
    if (!class_exists('KakaoAlimtalk')) {
        return array('success' => false, 'reason' => 'KakaoAlimtalk 클래스 없음');
    }

    // 인증 + 발신 정보
    $aligo_id      = isset($config['cf_aligo_id'])           ? trim((string)$config['cf_aligo_id'])           : '';
    $aligo_key     = isset($config['cf_aligo_key'])          ? trim((string)$config['cf_aligo_key'])          : '';
    $sender_key    = isset($config['cf_aligo_kakao_sdkey'])  ? trim((string)$config['cf_aligo_kakao_sdkey'])  : '';
    $sender_phone  = isset($config['cf_aligo_sender'])       ? trim((string)$config['cf_aligo_sender'])       : '';
    $template_code = isset($config['cf_guardian_kakao_template_code'])
                       ? trim((string)$config['cf_guardian_kakao_template_code']) : '';
    $emphasize_title = isset($config['cf_guardian_kakao_emphasize_title'])
                       ? trim((string)$config['cf_guardian_kakao_emphasize_title']) : '';

    // 강조 표기에 변수가 포함된 경우 발송 시점에 실제 값으로 치환한다.
    // 사용자가 환경설정에 입력하는 값이라 4가지 변형(`{KEY}` / `{key}` /
    // `#{KEY}` / `#{key}`) 을 모두 인식하는 flexible 치환을 사용한다.
    if ($emphasize_title !== ''
        && function_exists('guardian_build_template_vars')
        && function_exists('guardian_replace_vars_flexible')) {
        $emphasize_title = guardian_replace_vars_flexible(
            $emphasize_title,
            guardian_build_template_vars($error_data)
        );
    }
    // 알리고 emtitle 길이 안전 컷 (보통 50자 미만 권장)
    if (function_exists('mb_substr') && mb_strlen($emphasize_title, 'UTF-8') > 50) {
        $emphasize_title = mb_substr($emphasize_title, 0, 50, 'UTF-8');
    }

    if ($aligo_id === '' || $aligo_key === '') {
        return array('success' => false, 'reason' => '알리고 인증 정보 미설정');
    }
    if ($sender_key === '') {
        return array('success' => false, 'reason' => '카카오 발신키(cf_aligo_kakao_sdkey) 미설정');
    }
    if ($template_code === '') {
        return array('success' => false, 'reason' => '알림톡 템플릿 코드(cf_guardian_kakao_template_code) 미설정');
    }
    if ($sender_phone === '') {
        return array('success' => false, 'reason' => '발신번호(cf_aligo_sender) 미설정');
    }

    // ---- 수신자 정제 ----
    $to = guardian_normalize_phone((string)$to);
    if (!guardian_is_valid_phone($to)) {
        return array('success' => false, 'reason' => '유효하지 않은 휴대폰 번호');
    }

    $sender_phone = guardian_normalize_phone($sender_phone);

    // ---- SMS 폴백 본문 (알림톡 미수신 시 자동 SMS 전환) ----
    $message     = (string)$message;
    $fmessage    = $message;
    if (guardian_strlen_bytes($fmessage) > 2000) {
        $fmessage = guardian_truncate_bytes($fmessage, 1990);
    }

    $subject = '[' . (isset($config['cf_title']) ? (string)$config['cf_title'] : '운영지킴이') . '] 사이트 오류 알림';
    $fsubject = $subject;

    // ---- 발송 ----
    try {
        $client = new KakaoAlimtalk($aligo_key, $aligo_id);
        $result = $client->sendAlimtalk(
            $sender_key,         // senderkey
            $template_code,      // tpl_code
            $sender_phone,       // sender (발신번호)
            $to,                 // receiver
            $subject,            // subject
            $message,            // message
            $emphasize_title,    // emtitle (강조 표기, 선택)
            '',                  // buttons
            'Y',                 // failover : 알림톡 실패 시 SMS 폴백
            $fsubject,           // fsubject
            $fmessage,           // fmessage
            ''                   // scheduledAt
        );
    } catch (Exception $e) {
        return array('success' => false, 'reason' => 'KakaoAlimtalk Exception: ' . $e->getMessage());
    }

    // ---- 응답 해석 ----
    if (!is_array($result)) {
        return array('success' => false, 'reason' => '알리고 카톡 응답 형식 오류');
    }
    $code = isset($result['code']) ? (string)$result['code']
          : (isset($result['result_code']) ? (string)$result['result_code'] : '');

    // 알리고 카톡 API 의 성공 코드 체계
    //   code = 0 (정상 접수) 또는 result_code = 1 (호환)
    //   v10 API: 'code' 필드. 일부 응답: 'result_code' 필드.
    if ($code === '0' || $code === '1') {
        return array('success' => true, 'reason' => '');
    }

    $err_msg = isset($result['message']) ? (string)$result['message'] : '알 수 없는 알리고 카톡 오류';
    return array(
        'success' => false,
        'reason'  => '알리고 카톡 실패 (code=' . $code . '): ' . $err_msg,
    );
}
