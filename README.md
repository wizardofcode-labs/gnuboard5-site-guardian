# 그누보드 운영지킴이 (Gnuboard5 Site Guardian)

> 그누보드5 / 영카트5 사이트의 PHP 오류를 자동 감지해 메일·SMS·카카오 알림톡으로 알리는 운영 진단키트.

- **버전**: v1.1
- **저작권**: (c) 2026 K3SOFT / WizardOfCode
- **요구 환경**: 그누보드5 5.5+ / PHP 5.6 ~ 8.3 / MySQL(MariaDB) 5.5+
- **문의**: 카카오톡 채널 [WizardOfCode](https://pf.kakao.com/_mkUxdn) (설치 지원·오류 수정 의뢰)

---

## 1. 소개

운영지킴이는 그누보드 사이트에서 발생하는 PHP 오류·예외·DB 오류를 실시간으로 캐치해 데이터베이스에 기록하고, 운영자가 정의한 규칙에 따라 메일·SMS·카카오 알림톡으로 자동 발송한다. **고객이 항의하기 전에 운영자가 먼저 알 수 있도록** 만들어졌다.

```
오류 발생 → 자동 캡처 → DB 저장 → 규칙 매칭 → 알림 발송
                                        ├── 메일 (그누보드 mailer 자동 SMTP)
                                        ├── SMS (알리고 솔루션)
                                        └── 카카오 알림톡 (알리고 솔루션)
```

## 2. 주요 기능

### 2.1 오류 캡처 엔진
- **4가지 PHP 오류 자동 캡처**
  - 일반 에러 (Notice / Warning / Deprecated 등)
  - 처리되지 않은 예외 (Uncaught Exception)
  - Fatal Error (메모리 부족, 정의 안 된 함수 호출 등)
  - DB 쿼리 오류 (그누보드 sql_error 훅)
- 동일 오류 디바운싱 (기본 30분 쿨다운, 같은 해시 → 카운트만 증가)
- 개인정보 자동 마스킹 (이메일 / 휴대폰 / 주민번호 / 카드 / 비밀번호)
- 절대 경로 마스킹 (`/var/www/...` → `[ROOT]`)
- `@` 연산자 억제 존중 (사용자 의도 보존)
- 운영지킴이 자체 오류 차단 (무한 루프 방지)
- 비활성 시 디스크 I/O 0건 — **사이트 성능 영향 없음**

### 2.2 관리자 UI
- **대시보드** — 오늘/주간 발생 통계, 최다 오류 TOP 5, 미해결 항목 미리보기
- **오류 로그 목록** — 등급/기간/해결여부/메시지/파일 필터, 페이징, AJAX 모달 상세보기
- **알림 규칙 관리** — 등급/파일패턴/시간대 매칭 + 발송 모드/채널/수신자 설정
- **수신자 관리** — 이메일/휴대폰 등록, 활성/비활성 토글
- **알림 발송 이력** — 채널별 오늘 발송 카운트(일일 한도 모니터링), 일괄 삭제
- **테스트 발송** — 실제 발송 전 채널/수신자 검증
- **규칙 매칭 추적 로그** ★ — 9가지 결과(매칭/발송/스킵/예외 등) 추적, 디버깅용
- **환경설정** — 보호 시스템/카톡 가이드/cron 등록 가이드 통합
- **설치 테스트** ★ — 강제 경고/예외 발생 버튼 → 캡처·매칭·발송 흐름 1분 안에 검증

### 2.3 알림 규칙 엔진
- **4가지 발송 모드**
  - `instant` — 오류 발생 즉시 발송
  - `daily` — 매일 지정 시각에 어제 오류 요약 메일
  - `weekly` — 매주 지정 요일/시각에 지난 7일 요약 메일
  - `timeofday` — 지정 시간대(예: 09-18)에 발생한 오류만 즉시 발송
- 등급 매칭 (FATAL/ERROR/EXCEPTION/DB/WARNING/NOTICE/DEPRECATED 또는 ALL)
- 파일 패턴 매칭 (SQL LIKE 스타일, `%` / `_` 와일드카드)
- 우선순위 정렬 (1~100, 낮을수록 먼저)
- 중복 제거 정책 (규칙별 / 전역)
- 모든 매칭 시도를 추적 로그에 기록 → "왜 알림이 왔지/안 왔지?" 추적 가능

### 2.4 비용 보호 시스템 ★
SMS는 건당 비용이 발생하므로 **법적 안전장치 수준**으로 보호한다.

- **비상 정지 스위치** — 의심 폭주 감지 시 환경설정의 빨간 박스로 즉시 모든 발송 차단
- **일일 한도** — 채널별 (메일 500 / SMS 50 / 카톡 100, 사용자 조정 가능). 0 으로 두면 차단
- **야간 무음** — SMS / 카톡 발송 시간대 차단 (기본 22~08시, 메일은 정상)
- **동일 오류 쿨다운** — 같은 오류로 같은 수신자에게 30분 내 재발송 차단
- **재진입 락** — 발송 함수 무한 루프 차단
- **실패도 한도에 카운트** — 알리고 일시 장애로 실패가 폭주해도 비용 보호 무력화 안 됨

### 2.5 메일 / SMS / 카톡 발송
- **메일** — 그누보드 `mailer()` 함수 사용 → SMTP Manager 자동 후킹 → SMTP/PHP mail 자동 분기
- **SMS** — 알리고 SMS 솔루션의 `AligoSMS` 클래스 직접 호출. 90바이트 초과 시 자동 LMS 전환
- **카톡 알림톡** — 알리고 카톡 솔루션의 `KakaoAlimtalk::sendAlimtalk()` 호출. 카카오 비즈센터 사전 등록 템플릿 코드 필요. 알림톡 실패 시 SMS 자동 폴백
- 한국 휴대폰 번호 자동 정제 + 검증 (010-XXXX-XXXX → 01012345678)
- 알리고 인증 정보(`cf_aligo_id` / `cf_aligo_key` / `cf_aligo_sender`)는 그누보드 코어 컬럼 재사용 — 별도 입력 UI 불필요

### 2.6 자동 데이터 정리
- 매칭 추적 로그: 기본 7일 보관 (환경설정에서 1~365일 조정)
- 처리된 요약 큐: 7일 보관 (미처리 30일 강제 삭제)
- **해결 처리된 오류 로그**: 30일 보관 (환경설정에서 조정) — `resolved=1` 표시된 로그만 정리됨, 미해결 로그는 보존
- cron 트리거 또는 방문자 트리거가 자동 실행
- 알림 발송 이력은 자동 정리하지 않음 — 관리자 페이지에서 수동 일괄 삭제

### 2.7 별도 설정 시스템 (v1.1+)
- v1.1 부터 모든 운영지킴이 설정은 **별도 `g5_guardian_config` 테이블**에 저장
- 그누보드 `g5_config` 테이블을 오염시키지 않고, 설치/제거가 깔끔
- v1.0 환경에서 업그레이드 시 install.php 가 기존 `cf_guardian_*` 컬럼을 자동 마이그레이션
- 마이그레이션 후 며칠간 정상 동작을 확인한 뒤 `cleanup.php` 로 g5_config 컬럼 정리 가능

## 3. 파일 구성

```
repo root
├── README.md             # GitHub 소개 문서
└── docs/
│   └── index.html        # 상세 사용자 매뉴얼
└── src/
    ├── extend/
    │   └── gb_guardian.extend.php                  그누보드 진입점 (cf_guardian_use=0 일 때 즉시 return)
    ├── plugin/gb_guardian/
    │   ├── install.php                              설치 / 업그레이드 / 자동 마이그레이션
    │   ├── cleanup.php                              v1.0→v1.1 업그레이드 후 g5_config 컬럼 정리 (1회성)
    │   ├── README.md                                본 문서
    │   ├── MANUAL.html                              사용자 매뉴얼 (HTML)
    │   ├── lib/
    │   │   ├── guardian.lib.php                    공용 함수 (메뉴 / 컨텍스트 / 등급 매핑)
    │   │   ├── guardian_admin.lib.php              관리 화면 헬퍼 (시간 포맷 / 배지 / 카운트)
    │   │   ├── guardian_brand.lib.php              브랜드 / 푸터 / 미해결 알림 박스
    │   │   ├── guardian_chart.lib.php              대시보드 차트 데이터
    │   │   ├── guardian_config.lib.php             별도 설정 테이블 + 호환 레이어 + 마이그레이션
    │   │   ├── guardian_db.lib.php                 마스킹 / DB I/O / 디바운싱
    │   │   ├── error_handler.lib.php               4가지 핸들러 + 통합 캡처
    │   │   ├── guardian_protector.lib.php          ★ 보호 시스템 (비상정지/한도/쿨다운/락/야간무음)
    │   │   ├── guardian_template.lib.php           메일/SMS/카톡 템플릿 렌더링
    │   │   ├── guardian_mailer.lib.php             메일 어댑터 (그누보드 mailer 위임)
    │   │   ├── guardian_aligo_sms.lib.php          알리고 SMS 어댑터 (AligoSMS 클래스 래핑)
    │   │   ├── guardian_aligo_kakao.lib.php        알리고 카톡 어댑터 (KakaoAlimtalk 클래스 래핑)
    │   │   ├── guardian_notifier.lib.php           ★ 통합 발송 엔진 (8단계 검증)
    │   │   ├── guardian_partner.lib.php            함께 쓰면 좋은 솔루션 카드 메타데이터
    │   │   ├── guardian_rule_engine.lib.php        ★ 알림 규칙 매칭 엔진
    │   │   ├── guardian_summary.lib.php            일일/주간 요약 처리 + 자동 정리
    │   │   └── guardian_dispatcher.lib.php         캡처 후 매칭 엔진 진입점
    │   ├── templates/
    │   │   ├── mail_default.html                   즉시 발송 메일 템플릿
    │   │   ├── sms_default.txt                     SMS 본문 템플릿
    │   │   ├── kakao_default.txt                   카카오 알림톡 본문 템플릿
    │   │   ├── mail_summary_daily.html             일일 요약 메일 템플릿
    │   │   └── mail_summary_weekly.html            주간 요약 메일 템플릿
    │   └── batch/
    │       └── summary_cron.php                    cron 트리거 스크립트
    └── adm/
        ├── guardian_dashboard.php                   운영지킴이 대시보드
        ├── guardian_log.php                         오류 로그 목록
        ├── guardian_log_view.php                    오류 로그 상세 (AJAX 모달)
        ├── guardian_log_action.php                  오류 로그 일괄 처리
        ├── guardian_recipient.php                   수신자 목록
        ├── guardian_recipient_form.php              수신자 등록/수정
        ├── guardian_recipient_update.php            수신자 처리
        ├── guardian_rule.php                        알림 규칙 목록
        ├── guardian_rule_form.php                   규칙 등록/수정 (모드별 동적 UI)
        ├── guardian_rule_update.php                 규칙 처리
        ├── guardian_rule_match_log.php             ★ 규칙 매칭 추적 로그
        ├── guardian_notify_log.php                  알림 발송 이력
        ├── guardian_notify_log_action.php           발송 이력 일괄 처리
        ├── guardian_notify_test.php                 테스트 발송
        ├── guardian_test_warning.php               ★ 설치 테스트 (강제 경고 발생)
        ├── guardian_config.php                      환경설정
        └── guardian_config_update.php               환경설정 처리
```

## 4. 설치 방법

### 4.1 파일 업로드
위 파일들을 그누보드 루트 기준 동일한 경로에 업로드한다.

### 4.2 install.php 실행
관리자 로그인 후 브라우저에서 다음 URL 을 호출한다.

```
https://yoursite.com/plugin/gb_guardian/install.php
```

스크립트가 다음을 자동 수행한다.

- 7개 운영지킴이 테이블 생성
  - `g5_guardian_log` — 오류 로그
  - `g5_guardian_rule` — 알림 규칙
  - `g5_guardian_recipient` — 수신자
  - `g5_guardian_notify_log` — 알림 발송 이력
  - `g5_guardian_summary_queue` — 일일/주간 요약 큐
  - `g5_guardian_rule_match_log` — 규칙 매칭 추적 로그
  - `g5_guardian_config` — 운영지킴이 설정 (g5_config 대체)
- `g5_guardian_config` 테이블에 기본 설정값 INSERT (없는 키만, 멱등)
- v1.0 환경에서만 기존 `g5_config.cf_guardian_*` 컬럼 → `g5_guardian_config` 마이그레이션 (자동)
- cron 인증 토큰 자동 생성 (메일주소 prefix + 랜덤 16자 hex)
- PHP / 확장 / DB 환경 점검 결과 출력

> 💡 **v1.1+ 부터는 그누보드 `g5_config` 테이블에 컬럼을 절대 추가하지 않는다.** 신규 v1.1 설치 시 g5_config 변경 0건. 운영지킴이 설치 후 그대로 제거해도 그누보드 코어가 깨끗하게 남는다.

설치는 100회 재실행해도 안전하다. 모든 ALTER 는 사전 존재 체크를 거친다.

### 4.3 활성화

설치 직후에는 **비활성 상태(off)** 다. 다음 절차로 활성화한다.

1. 그누보드 관리자 → 좌측 메뉴 **운영지킴이** → **환경설정**
2. "운영지킴이 사용" 체크박스 ON → 저장

활성화 직후부터 모든 PHP 오류가 자동 캡처된다.

### 4.4 설치 테스트 (★ v1.1+)

활성화 직후 정상 동작을 검증하려면:

1. 관리자 → 운영지킴이 → **설치 테스트** 메뉴 (또는 환경설정 페이지 상단의 "테스트 페이지 열기" 버튼)
2. **WARNING 발생시키기** 버튼 클릭 → 의도적으로 PHP 경고 발생
3. **오류 로그** 화면에서 "운영지킴이 테스트 — 강제 경고" 가 캡처됐는지 확인
4. **매칭 추적 로그** / **알림 발송 이력** 화면에서 알림이 매칭/발송됐는지 확인

문제가 있다면 매칭 추적 로그의 결과 사유(rule-not-matched / cooldown / daily-limit-reached 등) 로 원인을 확인할 수 있다.

### 4.5 보안 권고

- 설치가 완료되면 `install.php` / `cleanup.php` 를 삭제하거나 .htaccess 로 외부 접근을 차단할 것을 권장한다.
- cron 인증 토큰은 비공개로 관리한다 (환경설정에서 변경 / 재생성 가능).

### 4.6 업그레이드 (v1.0 → v1.1)

1. 새 버전 파일을 덮어쓰기 업로드
2. `install.php` 재실행 → 자동으로 마이그레이션 (`cf_guardian_*` 컬럼 → `g5_guardian_config` 테이블)
3. 며칠간 운영지킴이가 정상 동작하는지 확인
4. (선택) `cleanup.php` 실행 → g5_config 의 19개 cf_guardian_* 컬럼 일괄 삭제
5. (선택) `cleanup.php` 파일을 FTP 로 삭제 (마커 파일 `.cleanup_done` 으로 재실행 차단되지만 안전)

## 5. 빠른 시작 가이드

### 5.1 첫 알림 받기 (3분 가이드)

1. **활성화** — 운영지킴이 → 환경설정 → "운영지킴이 사용" ON → 저장
2. **수신자 등록** — 운영지킴이 → 수신자 관리 → "+ 수신자 추가" → 이름/이메일 입력 → 저장
3. **첫 알림 규칙 등록** — 운영지킴이 → 알림 규칙 관리 → "+ 규칙 추가"
   - 규칙명: "Fatal/Error 즉시 메일"
   - 등급: FATAL, ERROR, EXCEPTION 체크
   - 모드: 즉시 발송
   - 채널: 이메일
   - 수신자: 위에서 등록한 수신자 체크
   - 저장
4. **테스트** — 운영지킴이 → 설치 테스트 → "WARNING 발생시키기" → 오류 로그 / 알림 발송 이력 확인

이후 사이트에서 발생하는 모든 FATAL/ERROR/EXCEPTION 이 자동으로 등록된 메일로 발송된다.

### 5.2 SMS / 카카오 알림톡 사용

알리고 SMS / 카톡 솔루션이 설치되어 있어야 동작한다.

1. **알리고 SMS 활성화** — 환경설정 → "알리고 연동" 체크 → 저장
2. **수신자에 휴대폰 등록** — 수신자 관리 → 수정 → 휴대폰 입력
3. **카톡 사용 시 추가** — 환경설정의 "카카오 알림톡 사용 전 필수 안내" 박스 → 권장 본문을 카카오 비즈센터에 등록 → 발급된 템플릿 코드를 환경설정에 입력
4. **규칙 채널 변경** — 규칙 등록 화면에서 채널을 SMS 또는 카카오 알림톡으로 선택

### 5.3 일일/주간 요약 메일 받기

1. 알림 규칙 등록 → 모드: "일일 요약" 또는 "주간 요약" 선택
2. 발송 시각 / 요일 설정
3. **트리거 등록** — 다음 두 가지 중 하나:
   - **cron 모드 (권장)** — 환경설정 → "cron 직접 등록" 선택 → cron 가이드 박스의 명령어를 호스팅 cron 에 등록
   - **방문자 트리거 (cron 불가 환경)** — 환경설정 → "방문자 트리거" 선택. 사이트 트래픽으로 자동 트리거됨

## 6. 메뉴 구조

설치 후 관리자 좌측에 **운영지킴이 (진단키트)** 그룹(`menu700`)이 추가된다.

```
운영지킴이 (진단키트)
├── 대시보드               (sub_menu = 700100)
├── 오류 로그              (700200)
├── 알림 규칙 관리          (700300)
├── 규칙 매칭 추적 로그     (700310)
├── 수신자 관리             (700400)
├── 알림 발송 이력          (700500)
├── 테스트 발송             (700600)
├── 환경설정                (700900)
└── 설치 테스트             (700990)
```

## 7. 동작 원리

운영지킴이는 그누보드의 **extend 자동 로드 메커니즘**으로 부트스트랩된다.

```
공통 페이지 요청
   ↓
common.php 로드
   ↓
extend/gb_guardian.extend.php 자동 호출
   ↓
guardian_config 호환 레이어 → $config['cf_guardian_*'] 주입
   ↓
cf_guardian_use 키 자체가 없음? → 즉시 return (install.php 미실행 환경)
   ↓
admin 영역 + 비활성? → guardian.lib.php / brand / partner 만 로드 (메뉴 표시용)
   ↓
일반 페이지 + 비활성? → 즉시 return (성능 영향 0)
   ↓ ON
모든 lib 로드 + 핸들러 등록 + run_event 훅 등록
   ↓
오류 발생 → guardian_capture()
            ↓
            마스킹 / 디바운싱 / DB 저장
            ↓
            run_event('guardian_after_capture', $data)
            ↓
            디스패처 → 매칭 엔진
            ↓
            활성 규칙 priority 순회 → 매칭 검사
            ↓
            매칭 성공 → guardian_apply_rule
            │   ├── instant/timeofday → guardian_notify
            │   │                       ↓
            │   │                       8단계 보호 검증 통과
            │   │                       ↓
            │   │                       채널 라우팅 (메일 / SMS / 카톡)
            │   │                       ↓
            │   │                       발송 결과 → guardian_notify_log
            │   │
            │   └── daily/weekly → guardian_summary_queue 적재
            │                      → cron / visitor 트리거가 시각 도달 시 메일 발송
            ↓
            매칭 결과 → guardian_rule_match_log (디버깅용)
```

**그누보드 코어는 단 한 줄도 수정하지 않는다**. extend / hook 메커니즘만 사용. 그누보드를 업그레이드해도 운영지킴이는 그대로 유지된다.

## 8. 개발자 가이드 — 본인만의 경고 위치 추가하기

운영지킴이는 PHP 표준 핸들러 (`set_error_handler`, `set_exception_handler`, `register_shutdown_function`) 를 등록해 **모든 위치의 오류를 자동 캡처**한다. 따라서 개발자가 "이 시점을 알림 받고 싶다" 면 다음 한 줄을 자기 코드에 추가하기만 하면 된다 — 별도의 운영지킴이 함수 호출이 필요 없다.

### 8.1 사용자 정의 경고 (E_USER_WARNING)

가장 일반적인 패턴. 등급은 `WARNING` 으로 캡처된다.

```php
trigger_error('결제 PG 응답 지연 - 5초 초과', E_USER_WARNING);
```

### 8.2 사용자 정의 알림 (E_USER_NOTICE)

`NOTICE` 등급으로 캡처된다. 환경설정의 "수집 등급" 에서 NOTICE 가 체크돼 있어야 캡처되며, 기본값은 OFF (노이즈 방지).

```php
trigger_error('재고 임박: SKU=' . $sku . ' / 잔여=' . $stock, E_USER_NOTICE);
```

### 8.3 예외로 알림 (Exception)

`EXCEPTION` 등급으로 캡처된다. 사이트 다운 위험이 있는 분기에 사용.

```php
if ($payment_response_code !== '0000') {
    throw new Exception('PG 응답 코드 비정상: ' . $payment_response_code);
}
```

### 8.4 try/catch 안에서도 알림만 받기

페이지 흐름은 유지하면서 알림만 받고 싶을 때:

```php
try {
    $result = call_external_api($payload);
} catch (Exception $e) {
    if (function_exists('guardian_capture_exception')) {
        guardian_capture_exception($e);  // 페이지 진행 + 알림 둘 다
    }
    $result = null;  // 폴백
}
```

### 8.5 권장 사용처

- 결제 PG 응답 시간 임계치 초과 / 응답 코드 비정상
- 외부 API 호출 실패 (재시도 후에도)
- 재고 / 잔액 / 한도 임박
- 관리자만 접근해야 하는 영역에 일반 회원 접근 시도
- 설치형 솔루션의 환경 점검 실패
- cron 작업의 비정상 종료
- 큐/락 충돌

### 8.6 주의사항

- 환경설정의 **"수집 등급"** 에서 해당 등급(WARNING / NOTICE 등) 이 체크돼 있어야 캡처된다.
- 알림으로 받으려면 **"알림 규칙"** 화면에서 매칭 규칙(등급/파일패턴/채널)이 등록돼 있어야 한다.
- 같은 오류가 짧은 시간에 반복되면 디바운싱 / 쿨다운으로 발송이 1번만 일어난다 (의도된 동작 — SMS 비용 폭탄 방지).
- 광고성·마케팅 메시지에 사용하지 말 것 (오류 알림 전용으로 설계됨).

`adm/guardian_test_warning.php` 파일 상단 주석에 더 자세한 사용법이 있다. 이 파일을 직접 읽어 5가지 패턴 코드를 그대로 복사하셔도 된다.

## 9. 요구 사항

| 항목 | 최소 | 권장 |
|---|---|---|
| 그누보드 | 5.5.x | 5.6.x |
| PHP | 5.6 | 7.4 / 8.x |
| MySQL / MariaDB | 5.5 | 5.7 / 10.x |
| PHP 확장 | mbstring 권장 | mbstring, iconv, curl |
| 메일 발송 | 그누보드 mailer (PHP mail) | SMTP Manager v1.0+ |
| SMS 발송 | (옵션) | 알리고 SMS 솔루션 |
| 카카오 알림톡 | (옵션) | 알리고 카톡 솔루션 + 비즈센터 템플릿 |
| 일일/주간 요약 | 방문자 트리거 (자동) | 호스팅 cron |

mbstring 미설치 환경에서는 한글 메시지 길이 컷이 정확하지 않을 수 있다. install.php 가 환경 점검 결과로 안내한다.

## 10. cron 등록 (선택)

일일/주간 요약 메일을 정확한 시각에 받으려면 cron 등록을 권장한다. cron 등록이 어려운 환경(저가 호스팅 등)에서는 환경설정에서 "방문자 트리거" 모드로 둬도 된다.

### 10.1 SSH crontab

```cron
*/10 * * * * /usr/bin/php /var/www/g5/plugin/gb_guardian/batch/summary_cron.php
```

### 10.2 외부 웹 cron 서비스 (cron-job.org 등)

환경설정 페이지의 cron 가이드 박스에 자동 생성된 URL 을 등록.

```
https://yoursite.com/plugin/gb_guardian/batch/summary_cron.php?secret=<TOKEN>
```

`<TOKEN>` 은 v1.1 부터 **랜덤 토큰** (메일주소 prefix 16자 + 랜덤 16자 hex) 이다. 환경설정 페이지에서 자동 표시되며, 사용자가 직접 수정하거나 "🔄 랜덤 재생성" 버튼으로 새로 발급받을 수 있다.

자세한 등록 방법은 환경설정 페이지의 "🕒 cron 등록 가이드" 박스 참고.

## 11. 보안 / 안전성

- 모든 SQL 호출에 `@sql_query(..., false)` 안전망 적용 — DB 오류가 사이트를 죽이지 않음
- 모든 핸들러 try-catch 보호 — 핸들러 자체 오류로 사이트 멎지 않음
- 자기 참조 방지 — 운영지킴이 자체 오류는 무시 (무한 루프 차단)
- shutdown 핸들러 무거운 작업 금지 — 메모리 부족 환경에서도 안전
- CSRF 토큰 검증 — 모든 처리 페이지(`*_action.php` / `*_update.php`) 적용
- SQL Injection 방어 — `sql_escape_string` / `(int)` 캐스팅 / 화이트리스트 일관 적용
- XSS 방어 — 모든 출력 `get_text()` / `htmlspecialchars()` 경유
- 정규식 인젝션 방어 — `file_pattern` 변환 시 `preg_quote()` 적용
- cron 인증 토큰 — `hash_equals` 로 타이밍 공격 방어 (v1.1+)

## 12. 제거 방법

다음 SQL 을 실행한 뒤, 업로드한 파일들을 삭제한다.

```sql
-- 운영지킴이 테이블 제거 (7개)
DROP TABLE IF EXISTS g5_guardian_log;
DROP TABLE IF EXISTS g5_guardian_rule;
DROP TABLE IF EXISTS g5_guardian_recipient;
DROP TABLE IF EXISTS g5_guardian_notify_log;
DROP TABLE IF EXISTS g5_guardian_summary_queue;
DROP TABLE IF EXISTS g5_guardian_rule_match_log;
DROP TABLE IF EXISTS g5_guardian_config;

-- v1.0 환경에서 업그레이드 후 cleanup.php 를 아직 실행하지 않은 경우에만 필요
-- (이미 cleanup.php 를 실행했다면 본 ALTER 들은 모두 [SKIP] 됨)
ALTER TABLE g5_config DROP COLUMN cf_guardian_use;
ALTER TABLE g5_config DROP COLUMN cf_guardian_collect_levels;
ALTER TABLE g5_config DROP COLUMN cf_guardian_log_keep_days;
ALTER TABLE g5_config DROP COLUMN cf_guardian_default_cooldown_min;
ALTER TABLE g5_config DROP COLUMN cf_guardian_aligo_enabled;
ALTER TABLE g5_config DROP COLUMN cf_guardian_emergency_stop;
ALTER TABLE g5_config DROP COLUMN cf_guardian_email_daily_limit;
ALTER TABLE g5_config DROP COLUMN cf_guardian_sms_daily_limit;
ALTER TABLE g5_config DROP COLUMN cf_guardian_kakao_daily_limit;
ALTER TABLE g5_config DROP COLUMN cf_guardian_sms_silent_enabled;
ALTER TABLE g5_config DROP COLUMN cf_guardian_sms_silent_start;
ALTER TABLE g5_config DROP COLUMN cf_guardian_sms_silent_end;
ALTER TABLE g5_config DROP COLUMN cf_guardian_kakao_template_code;
ALTER TABLE g5_config DROP COLUMN cf_guardian_kakao_emphasize_title;
ALTER TABLE g5_config DROP COLUMN cf_guardian_rule_match_logging;
ALTER TABLE g5_config DROP COLUMN cf_guardian_rule_match_keep_days;
ALTER TABLE g5_config DROP COLUMN cf_guardian_summary_mode;
ALTER TABLE g5_config DROP COLUMN cf_guardian_summary_last_daily;
ALTER TABLE g5_config DROP COLUMN cf_guardian_summary_last_weekly;
```

> 테이블 접두사를 변경한 경우 위 SQL 의 `g5_` 부분을 사용 환경에 맞게 수정한다.

## 13. 라이선스

| 허용 / 금지 | 항목 |
|---|---|
| ✅ 허용 | 무제한 사이트 설치 |
| ✅ 허용 | 고객 사이트 설치 / 운영 서비스 제공 |
| ✅ 허용 | 소스 수정 |
| ✅ 허용 | 상업적 이용 |
| ❌ 금지 | 소스파일 재판매 |
| ❌ 금지 | 플러그인 형태로 재패키징하여 판매 |

본 솔루션은 **사이트 식별·라이센스 키 검증 같은 락인 로직을 일절 포함하지 않는다**. 무제한 설치는 K3SOFT 의 핵심 가치다.

### SMS 비용 면책

알리고 SMS / 카카오 알림톡은 건당 비용이 발생한다. 운영지킴이는 비용 보호 시스템(비상정지/일일한도/야간무음/쿨다운/재진입락)을 기본 제공하지만, 환경설정 변경이나 외부 요인으로 발생한 비용에 대해 K3SOFT 는 책임지지 않는다.

## 14. 문의 / 지원

운영지킴이는 **오픈 공개형** 솔루션입니다. 자유롭게 다운로드·수정·재배포하실 수 있습니다 (위 라이선스 범위 내).

설치 지원·오류 수정 의뢰·기능 개선 제안 등 모든 문의는 **카카오톡 채널** 로 연락 주시기 바랍니다.

- 제작: WizardOfCode / K3SOFT
- 카카오톡 채널: [WizardOfCode](https://pf.kakao.com/_mkUxdn)
- 유지보수 의뢰: <https://wizardofcode.kr/?page_id=941>

오류 보고 시 다음 3가지 화면의 스크린샷을 함께 보내주시면 빠르게 진단해 드릴 수 있습니다.

1. 오류 로그 화면
2. 규칙 매칭 추적 로그 화면
3. 알림 발송 이력 화면

---

*(c) 2026 K3SOFT / WizardOfCode*
