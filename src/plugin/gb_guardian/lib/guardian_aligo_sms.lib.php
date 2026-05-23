<?php
/**
 * 그누보드 운영지킴이 - 알리고 SMS 어댑터
 *
 * 알리고 SMS 솔루션의 AligoSMS 클래스를 래핑한다.
 *   - 인스턴스: new AligoSMS($user_id, $key)
 *   - 호출    : $obj->sendSMS($message, $receiver, $sender, $subject, $msg_type, $testmode_yn)
 *
 * 인증 정보는 그누보드 코어가 g5_config 에 저장한 컬럼을 그대로 재사용:
 *   - cf_aligo_id     : 알리고 사용자 ID
 *   - cf_aligo_key    : 알리고 API 키
 *   - cf_aligo_sender : 발신번호
 *
 * 모든 발송 함수는 array('success'=>bool, 'reason'=>string) 반환.
 * 사이트를 죽이지 않는다 — try-catch 로 어떤 예외도 흡수.
 *
 * (c) 2026 K3SOFT / WizardOfCode
 *
 * @package gb_guardian
 * @version 1.0.0
 */
if (!defined('_GNUBOARD_')) exit;

/**
 * SMS 발송. guardian_notifier.lib.php 의 라우터가 호출.
 *
 * @param  string $to      한국 휴대폰 번호 (정제 자동 수행)
 * @param  string $message 본문 (자동 SMS/LMS 분기)
 * @return array
 */
function guardian_send_sms($to, $message)
{
    global $config;

    // ---- 사전 검증 ----
    if (!guardian_is_aligo_sms_available()) {
        return array('success' => false, 'reason' => '알리고 SMS 솔루션 미설치');
    }
    if (!class_exists('AligoSMS')) {
        return array('success' => false, 'reason' => 'AligoSMS 클래스 없음');
    }

    // 인증 정보
    $aligo_id     = isset($config['cf_aligo_id'])     ? trim((string)$config['cf_aligo_id'])     : '';
    $aligo_key    = isset($config['cf_aligo_key'])    ? trim((string)$config['cf_aligo_key'])    : '';
    $aligo_sender = isset($config['cf_aligo_sender']) ? trim((string)$config['cf_aligo_sender']) : '';

    if ($aligo_id === '' || $aligo_key === '') {
        return array('success' => false, 'reason' => '알리고 인증 정보(cf_aligo_id/key) 미설정');
    }
    if ($aligo_sender === '') {
        return array('success' => false, 'reason' => '알리고 발신번호(cf_aligo_sender) 미설정');
    }

    // ---- 수신자 번호 정제 ----
    $to = guardian_normalize_phone((string)$to);
    if (!guardian_is_valid_phone($to)) {
        return array('success' => false, 'reason' => '유효하지 않은 휴대폰 번호');
    }

    // ---- 메시지 길이 분기 (SMS 90 / LMS 2000 바이트 EUC-KR 기준) ----
    $message = (string)$message;
    $byte_len = guardian_strlen_bytes($message);

    if ($byte_len <= 90) {
        $msg_type = 'SMS';
    } elseif ($byte_len <= 2000) {
        $msg_type = 'LMS';
    } else {
        $message = guardian_truncate_bytes($message, 1990);
        $msg_type = 'LMS';
    }

    // 발신번호 정제
    $aligo_sender = guardian_normalize_phone($aligo_sender);

    // ---- 발송 ----
    try {
        $client = new AligoSMS($aligo_id, $aligo_key);
        $result = $client->sendSMS($message, $to, $aligo_sender, '', $msg_type, 'N');
    } catch (Exception $e) {
        return array('success' => false, 'reason' => 'AligoSMS Exception: ' . $e->getMessage());
    }

    // ---- 응답 해석 ----
    // 알리고 응답 표준: { result_code: 1=성공, 음수=실패, message: ... , msg_id: ... }
    if (!is_array($result)) {
        return array('success' => false, 'reason' => '알리고 응답 형식 오류');
    }
    $code = isset($result['result_code']) ? (string)$result['result_code'] : '';
    if ($code === '1') {
        return array('success' => true, 'reason' => '');
    }

    $err_msg = isset($result['message']) ? (string)$result['message'] : '알 수 없는 알리고 오류';
    return array(
        'success' => false,
        'reason'  => '알리고 SMS 실패 (code=' . $code . '): ' . $err_msg,
    );
}

// =====================================================================
// 한국 휴대폰 번호 정제 / 검증
// =====================================================================
/**
 * 한국 휴대폰 번호를 알리고 형식(01012345678)으로 정제.
 *
 * @param  string $phone
 * @return string
 */
function guardian_normalize_phone($phone)
{
    $phone = (string)$phone;

    // 모든 공백 / 하이픈 / 괄호 / 점 제거
    $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);

    // +82 또는 82 로 시작하는 국제 형식 처리
    if (strpos($phone, '+82') === 0) {
        $phone = '0' . substr($phone, 3);
    } elseif (strpos($phone, '82') === 0 && strlen($phone) >= 11 && strlen($phone) <= 12) {
        $phone = '0' . substr($phone, 2);
    }

    // 숫자만 남기기 (혹시 모를 잔여 문자 제거)
    $phone = preg_replace('/[^0-9]/', '', $phone);

    return $phone;
}

/**
 * 유효한 한국 휴대폰 번호 여부.
 *
 * 010, 011, 016, 017, 018, 019 (010-XXXX-XXXX 또는 011-XXX-XXXX).
 *
 * @param  string $phone (정제된 형태 가정)
 * @return bool
 */
function guardian_is_valid_phone($phone)
{
    return preg_match('/^01[016-9]\d{7,8}$/', (string)$phone) === 1;
}

// =====================================================================
// 바이트 단위 문자열 길이 (한글 SMS 처리)
// =====================================================================
/**
 * EUC-KR 기준 바이트 수.
 *
 * 알리고는 일반적으로 EUC-KR 기준으로 SMS/LMS 길이를 카운트한다.
 * UTF-8 환경에서 정확한 EUC-KR 길이를 얻기 위해 변환 후 strlen.
 *
 * @param  string $str
 * @return int
 */
function guardian_strlen_bytes($str)
{
    $str = (string)$str;
    if ($str === '') return 0;

    if (function_exists('iconv')) {
        $euckr = @iconv('UTF-8', 'EUC-KR//IGNORE', $str);
        if ($euckr !== false && $euckr !== '') {
            return strlen($euckr);
        }
    }
    if (function_exists('mb_convert_encoding')) {
        $euckr = @mb_convert_encoding($str, 'EUC-KR', 'UTF-8');
        if ($euckr !== false && $euckr !== '') {
            return strlen($euckr);
        }
    }
    // 폴백: UTF-8 바이트 수 (보수적 — 실제보다 큼)
    return strlen($str);
}

/**
 * 바이트 단위로 안전하게 자른다 (한글 깨짐 방지).
 *
 * @param  string $str
 * @param  int    $max_bytes
 * @return string
 */
function guardian_truncate_bytes($str, $max_bytes)
{
    $str = (string)$str;
    $max_bytes = (int)$max_bytes;
    if ($max_bytes <= 0) return '';
    if (guardian_strlen_bytes($str) <= $max_bytes) return $str;

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $len = mb_strlen($str, 'UTF-8');
        // 안전마진 4바이트 + 말줄임표 3바이트
        while ($len > 0 && guardian_strlen_bytes(mb_substr($str, 0, $len, 'UTF-8')) > $max_bytes - 4) {
            $len--;
        }
        return mb_substr($str, 0, $len, 'UTF-8') . '...';
    }
    return substr($str, 0, $max_bytes - 4) . '...';
}
