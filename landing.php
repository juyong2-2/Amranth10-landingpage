<?php
declare(strict_types=1);

function q(string $key): string {
  $v = filter_input(INPUT_GET, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  return is_string($v) ? $v : '';
}

$contactEmail = 'iyjy@duzon119.co.kr';
$formErrors = [];
$formSuccess = false;
$smtpHost = getenv('SMTP_HOST') ?: '';
$smtpPort = (int) (getenv('SMTP_PORT') ?: 587);
$smtpUser = getenv('SMTP_USER') ?: '';
$smtpPass = getenv('SMTP_PASS') ?: '';
$smtpSecure = strtolower(getenv('SMTP_SECURE') ?: 'tls');
$smtpFrom = getenv('SMTP_FROM') ?: '';
$smtpFromName = getenv('SMTP_FROM_NAME') ?: 'Amaranth10 Landing';

$utm = [
  'utm_source'   => q('utm_source'),
  'utm_medium'   => q('utm_medium'),
  'utm_campaign' => q('utm_campaign'),
  'utm_content'  => q('utm_content'),
  'utm_term'     => q('utm_term'),
  'ref'          => q('ref'),
];

$postValue = static function (string $key): string {
  $value = filter_input(INPUT_POST, $key, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  return is_string($value) ? trim($value) : '';
};

$postArray = static function (string $key): array {
  $value = filter_input(INPUT_POST, $key, FILTER_DEFAULT, FILTER_REQUIRE_ARRAY);
  return is_array($value) ? array_map('trim', array_map('strval', $value)) : [];
};

$smtpSend = static function (
  string $host,
  int $port,
  string $user,
  string $pass,
  string $secure,
  string $from,
  string $fromName,
  string $to,
  string $subject,
  string $body
): bool {
  if ($host === '' || $from === '') {
    return false;
  }

  $socket = @fsockopen($host, $port, $errno, $errstr, 10);
  if (!$socket) {
    return false;
  }

  $read = static function () use ($socket): string {
    $data = '';
    while (!feof($socket)) {
      $line = fgets($socket, 515);
      if ($line === false) {
        break;
      }
      $data .= $line;
      if (preg_match('/^\d{3} /', $line)) {
        break;
      }
    }
    return $data;
  };

  $write = static function (string $command) use ($socket): void {
    fwrite($socket, $command . "\r\n");
  };

  $read();
  $write('EHLO localhost');
  $read();

  if ($secure === 'tls') {
    $write('STARTTLS');
    $response = $read();
    if (substr($response, 0, 3) !== '220') {
      fclose($socket);
      return false;
    }
    if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
      fclose($socket);
      return false;
    }
    $write('EHLO localhost');
    $read();
  }

  if ($user !== '' && $pass !== '') {
    $write('AUTH LOGIN');
    $read();
    $write(base64_encode($user));
    $read();
    $write(base64_encode($pass));
    $authResponse = $read();
    if (substr($authResponse, 0, 3) !== '235') {
      fclose($socket);
      return false;
    }
  }

  $write('MAIL FROM:<' . $from . '>');
  if (substr($read(), 0, 3) !== '250') {
    fclose($socket);
    return false;
  }
  $write('RCPT TO:<' . $to . '>');
  if (substr($read(), 0, 3) !== '250') {
    fclose($socket);
    return false;
  }
  $write('DATA');
  if (substr($read(), 0, 3) !== '354') {
    fclose($socket);
    return false;
  }

  $encodedSubject = function_exists('mb_encode_mimeheader')
    ? mb_encode_mimeheader($subject, 'UTF-8')
    : '=?UTF-8?B?' . base64_encode($subject) . '?=';
  $headers = [
    'From: ' . $fromName . ' <' . $from . '>',
    'To: <' . $to . '>',
    'Subject: ' . $encodedSubject,
    'Content-Type: text/plain; charset=UTF-8',
  ];
  $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.";
  $write($message);
  $response = $read();
  $write('QUIT');
  fclose($socket);

  return substr($response, 0, 3) === '250';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $company = $postValue('company');
  $bizno = $postValue('bizno');
  $name = $postValue('name');
  $phone = $postValue('phone');
  $email = $postValue('email');
  $message = $postValue('message');
  $modules = $postArray('modules');
  $budgetNonprofit = $postValue('budget_nonprofit');
  $prodOutsource = $postValue('prod_outsource');
  $prodCost = $postValue('prod_cost');

  if ($company === '' || $bizno === '' || $name === '' || $phone === '' || $email === '' || $message === '') {
    $formErrors[] = '필수 항목을 모두 입력해주세요.';
  }

  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $formErrors[] = '이메일 형식이 올바르지 않습니다.';
  }

  if ($formErrors === []) {
    $subject = 'Amaranth 10 도입 상담 요청 - ' . $company;
    $emailBodyLines = [
      "회사명: {$company}",
      "사업자번호: {$bizno}",
      "담당자: {$name}",
      "연락처: {$phone}",
      "이메일: {$email}",
      "모듈: " . ($modules !== [] ? implode(', ', $modules) : '미선택'),
      "예산 모듈 비영리 여부: " . ($budgetNonprofit !== '' ? $budgetNonprofit : '미응답'),
      "생산 모듈 외주 사용: " . ($prodOutsource !== '' ? $prodOutsource : '미응답'),
      "생산 모듈 원가 사용: " . ($prodCost !== '' ? $prodCost : '미응답'),
      "문의내용:",
      $message,
      '',
      'UTM 정보:',
    ];

    foreach ($utm as $key => $value) {
      if ($value !== '') {
        $emailBodyLines[] = "{$key}: {$value}";
      }
    }

    $emailBody = implode("\n", $emailBodyLines);
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromAddress = $smtpFrom !== '' ? $smtpFrom : 'no-reply@' . $host;
    if ($smtpHost !== '') {
      $mailSent = $smtpSend(
        $smtpHost,
        $smtpPort,
        $smtpUser,
        $smtpPass,
        $smtpSecure,
        $fromAddress,
        $smtpFromName,
        $contactEmail,
        $subject,
        $emailBody
      );
    } else {
      $headers = [
        'From: ' . $smtpFromName . ' <' . $fromAddress . '>',
        'Reply-To: ' . $email,
        'Content-Type: text/plain; charset=UTF-8',
      ];
      $encodedSubject = function_exists('mb_encode_mimeheader')
        ? mb_encode_mimeheader($subject, 'UTF-8')
        : '=?UTF-8?B?' . base64_encode($subject) . '?=';
      $mailSent = mail($contactEmail, $encodedSubject, $emailBody, implode("\r\n", $headers));
    }
    if ($mailSent) {
      $formSuccess = true;
    } else {
      if (!$mailSent) {
        $formErrors[] = '이메일 전송에 실패했습니다. 잠시 후 다시 시도해주세요.';
      }
    }
  }
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>더존 Amaranth 10 | 도입 상담·데모</title>
  <meta name="description" content="ERP·그룹웨어·문서중앙화 통합 플랫폼 Amaranth 10. 도입 상담/데모 요청." />
  <meta property="og:title" content="더존 Amaranth 10 | 도입 상담·데모" />
  <meta property="og:description" content="ERP·그룹웨어·문서중앙화 통합 플랫폼 Amaranth 10. 도입 상담/데모 요청." />
  <meta property="og:type" content="website" />
  <meta property="og:url" content="https://ione119.co.kr/contact/landing.php" />

  <link rel="stylesheet" href="/contact/css/landing.css?v=120" />
</head>

<body>
  <a class="skip" href="#content">본문 바로가기</a>

  <!-- Header: 스크롤 전 숨김, 스크롤하면 등장 -->
  <header class="header" id="siteHeader" role="banner" aria-label="상단 메뉴">
    <div class="container header__inner">
      <a class="brand" href="#top" aria-label="상단으로">
        <span class="brand__mark" aria-hidden="true"></span>
        <span class="brand__text">Amaranth 10</span>
      </a>

      <nav class="nav" aria-label="페이지 이동">
        <a class="nav__link" href="#highlights">핵심</a>
        <a class="nav__link" href="#value">가치</a>
        <a class="nav__link" href="#features">기능</a>
        <a class="nav__link" href="#faq">FAQ</a>
      </nav>

      <button
        class="btn btn--primary btn--sm"
        type="button"
        data-drawer-open
        aria-controls="drawer"
        aria-expanded="false"
      >
        도입 상담
      </button>
    </div>
  </header>

  <!-- Push stage -->
  <div class="stage" id="stage">
    <main id="content" class="main" role="main">
      <!-- FIRST SCREEN -->
      <section class="hero" id="top" aria-label="첫 화면">
        <div class="container hero__inner">
          <h1 class="hero__title">
            ERP·그룹웨어·문서중앙화,<br />
            한 번에 연결하는 업무 플랫폼
          </h1>

          <div class="hero__cta">
            <button
              class="btn btn--primary btn--lg"
              type="button"
              data-drawer-open
              aria-controls="drawer"
              aria-expanded="false"
            >
              도입 상담/데모 요청
            </button>
          </div>

          <div class="hero__hint" aria-hidden="true">
            <span class="hero__hintText">Scroll</span>
            <span class="hero__hintLine"></span>
          </div>
        </div>
      </section>

      <!-- Content (blur until revealed) -->
      <section id="highlights" class="section" data-reveal>
        <div class="container">
          <h2 class="section__title">핵심</h2>
          <p class="section__desc">도입 검토에 필요한 포인트만 먼저 압축합니다.</p>

          <div class="grid4">
            <article class="card">
              <h3 class="card__title">통합</h3>
              <p class="card__desc">ERP·그룹웨어·문서를 하나의 기준으로 묶습니다.</p>
            </article>
            <article class="card">
              <h3 class="card__title">통합검색</h3>
              <p class="card__desc">찾고 → 바로 처리, 업무 이동을 줄입니다.</p>
            </article>
            <article class="card">
              <h3 class="card__title">ONE AI</h3>
              <p class="card__desc">요약/초안/정리 등 반복 업무를 줄입니다.</p>
            </article>
            <article class="card">
              <h3 class="card__title">문서·증빙</h3>
              <p class="card__desc">보전/추적/통제의 운영 기준을 세웁니다.</p>
            </article>
          </div>

          <div class="section__cta">
            <button class="btn btn--primary" type="button" data-drawer-open aria-controls="drawer" aria-expanded="false">
              핵심 중심 데모 요청
            </button>
          </div>
        </div>
      </section>

      <section id="value" class="section section--tint" data-reveal>
        <div class="container">
          <h2 class="section__title">가치</h2>
          <p class="section__desc">“통합”은 IT 구성이 아니라, 현업의 UX를 바꾸는 방식입니다.</p>

          <div class="grid3">
            <article class="tile">
              <h3 class="tile__title">업무 표준화</h3>
              <p class="tile__desc">프로세스/권한/이력을 기준화해 운영 리스크를 낮춥니다.</p>
            </article>
            <article class="tile">
              <h3 class="tile__title">의사결정 속도</h3>
              <p class="tile__desc">결재·협업의 병목을 줄여 처리 시간을 단축합니다.</p>
            </article>
            <article class="tile">
              <h3 class="tile__title">증빙/감사 대응</h3>
              <p class="tile__desc">문서가 중앙에서 관리되어 추적/보전이 쉬워집니다.</p>
            </article>
          </div>
        </div>
      </section>

      <section id="features" class="section" data-reveal>
        <div class="container">
          <h2 class="section__title">주요 기능</h2>
          <p class="section__desc">현업이 바로 체감하는 UX 중심으로 구성합니다.</p>

          <div class="grid3">
            <article class="tile">
              <h3 class="tile__title">사용자 맞춤 포털</h3>
              <p class="tile__desc">알림/업무 흐름을 한 화면에서 파악합니다.</p>
            </article>
            <article class="tile">
              <h3 class="tile__title">전자결재</h3>
              <p class="tile__desc">결재 흐름을 표준화하고 처리 속도를 높입니다.</p>
            </article>
            <article class="tile">
              <h3 class="tile__title">메일·협업</h3>
              <p class="tile__desc">커뮤니케이션 이력을 업무 맥락 안에 유지합니다.</p>
            </article>
            <article class="tile">
              <h3 class="tile__title">문서중앙화</h3>
              <p class="tile__desc">보전/추적/통제의 운영 기준을 세웁니다.</p>
            </article>
            <article class="tile">
              <h3 class="tile__title">권한/이력</h3>
              <p class="tile__desc">역할 기반 통제와 이력 관리를 단순화합니다.</p>
            </article>
            <article class="tile">
              <h3 class="tile__title">ONE AI</h3>
              <p class="tile__desc">요약/초안/정리로 반복 업무를 줄입니다.</p>
            </article>
          </div>

          <div class="section__cta">
            <button class="btn btn--primary" type="button" data-drawer-open aria-controls="drawer" aria-expanded="false">
              기능 범위 포함 상담
            </button>
          </div>
        </div>
      </section>

      <section id="faq" class="section section--tint" data-reveal>
        <div class="container">
          <h2 class="section__title">FAQ</h2>
          <p class="section__desc">도입 검토 단계에서 자주 묻는 질문입니다.</p>

          <div class="faq">
            <details class="qa">
              <summary class="qa__q">부분 도입도 가능한가요?</summary>
              <div class="qa__a">가능합니다. 현재 사용 중인 시스템 기준으로 범위를 설계합니다.</div>
            </details>

            <details class="qa">
              <summary class="qa__q">데모는 어떤 방식으로 진행되나요?</summary>
              <div class="qa__a">현업 시나리오(검색/결재/문서/협업) 중심으로 진행합니다.</div>
            </details>

            <details class="qa">
              <summary class="qa__q">신청 후 프로세스는?</summary>
              <div class="qa__a">요청 확인 → 현황 파악 → 범위/일정/데모 방식 제안 순으로 안내합니다.</div>
            </details>
          </div>

          <footer class="footer">
            <div class="footer__row">
              <div class="footer__fine">© <?= date('Y') ?> ione119. All rights reserved.</div>
              <button class="btn btn--ghost btn--sm" type="button" data-drawer-open aria-controls="drawer" aria-expanded="false">
                도입 상담/데모 요청
              </button>
            </div>
          </footer>
        </div>
      </section>

      <!-- mobile bottom CTA -->
      <div class="mcta" role="region" aria-label="모바일 도입 상담">
        <button class="btn btn--primary btn--block" type="button" data-drawer-open aria-controls="drawer" aria-expanded="false">
          도입 상담/데모 요청
        </button>
      </div>
    </main>
  </div>

  <!-- Drawer -->
  <aside
    class="drawer"
    id="drawer"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="drawerTitle"
  >
    <div class="drawer__head">
      <div>
        <div class="drawer__title" id="drawerTitle">도입 상담/데모 요청</div>
        <div class="drawer__sub">필수만 입력하면 접수됩니다.</div>
      </div>
      <button class="icon" type="button" data-drawer-close aria-label="닫기">×</button>
    </div>

    <div class="drawer__body">
      <?php if ($formSuccess): ?>
        <div class="formNotice">요청이 정상적으로 접수되었습니다. 빠르게 연락드리겠습니다.</div>
      <?php elseif ($formErrors !== []): ?>
        <div class="formNotice formNotice--error">
          <?= htmlspecialchars(implode("\n", $formErrors), ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form class="form" action="" method="post" novalidate>
        <?php foreach ($utm as $k => $v): ?>
          <input type="hidden" name="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>" />
        <?php endforeach; ?>

        <div class="field">
          <label for="company">회사명 *</label>
          <input id="company" name="company" type="text" autocomplete="organization" required />
        </div>

        <div class="field">
          <label for="bizno">사업자번호 *</label>
          <input id="bizno" name="bizno" type="text" inputmode="numeric" autocomplete="off" required
                 placeholder="예: 123-45-67890" />
          <p class="hint">하이픈(-) 포함/미포함 모두 가능합니다.</p>
        </div>

        <div class="row2">
          <div class="field">
            <label for="name">담당자 *</label>
            <input id="name" name="name" type="text" autocomplete="name" required />
          </div>
          <div class="field">
            <label for="phone">연락처 *</label>
            <input id="phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" required />
          </div>
        </div>

        <div class="field">
          <label for="email">이메일 *</label>
          <input id="email" name="email" type="email" autocomplete="email" required />
        </div>

        <div class="moduleBlock">
          <div class="moduleTitle">도입을 원하시는 모듈을 선택하세요</div>

          <div class="moduleGrid">
            <label class="chip">
              <input type="checkbox" name="modules[]" value="회계" />
              <span>회계</span>
            </label>
            <label class="chip">
              <input type="checkbox" name="modules[]" value="인사" />
              <span>인사</span>
            </label>
            <label class="chip">
              <input type="checkbox" name="modules[]" value="예산" data-module="budget" />
              <span>예산</span>
            </label>
            <label class="chip">
              <input type="checkbox" name="modules[]" value="영업" />
              <span>영업</span>
            </label>
            <label class="chip">
              <input type="checkbox" name="modules[]" value="구매/자재" />
              <span>구매/자재</span>
            </label>
            <label class="chip">
              <input type="checkbox" name="modules[]" value="생산" data-module="production" />
              <span>생산</span>
            </label>
            <label class="chip">
              <input type="checkbox" name="modules[]" value="그룹웨어" />
              <span>그룹웨어</span>
            </label>
          </div>

          <!-- 예산 선택 시 -->
          <div class="follow" id="followBudget" hidden>
            <div class="follow__q">예산 모듈: 비영리 기관이신가요?</div>
            <div class="follow__row" role="radiogroup" aria-label="비영리 여부">
              <label class="radio">
                <input type="radio" name="budget_nonprofit" value="Y" />
                <span>예</span>
              </label>
              <label class="radio">
                <input type="radio" name="budget_nonprofit" value="N" />
                <span>아니오</span>
              </label>
            </div>
          </div>

          <!-- 생산 선택 시 -->
          <div class="follow" id="followProduction" hidden>
            <div class="follow__q">생산 모듈: 외주와 원가를 사용하시나요?</div>
            <div class="follow__grid">
              <label class="checkLine">
                <input type="checkbox" name="prod_outsource" value="Y" />
                <span>외주 사용</span>
              </label>
              <label class="checkLine">
                <input type="checkbox" name="prod_cost" value="Y" />
                <span>원가 사용</span>
              </label>
            </div>
          </div>
        </div>

        <div class="field">
          <label for="msg">문의내용 *</label>
          <textarea id="msg" name="message" rows="4" required placeholder="문의하실 내용을 입력해주세요."></textarea>
        </div>

        <button class="btn btn--primary btn--block" type="submit">요청 접수</button>
        <p class="hint">요청 접수 후 담당자가 연락드립니다.</p>
      </form>
    </div>
  </aside>

  <div class="backdrop" data-drawer-close aria-hidden="true"></div>

  <script src="/contact/js/ui.js?v=120" defer></script>
</body>
</html>
