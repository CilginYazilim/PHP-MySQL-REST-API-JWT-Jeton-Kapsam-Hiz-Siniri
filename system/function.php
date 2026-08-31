<?php
/**
 * =====================================================================
 *  YARDIMCI FONKSİYONLAR
 *  cilginyazilim.com – Sıfır Bağımlılık JWT REST API
 * ---------------------------------------------------------------------
 *  BÖLÜM 1  JSON zarfı ve hata biçimi (tutarlı!)
 *  BÖLÜM 2  base64url + HS256 JWT: encode / decode
 *  BÖLÜM 3  Hız sınırı (anahtar başına, kayan pencere)
 *  BÖLÜM 4  Kimlik: Bearer jeton çözme, kapsam kontrolü
 *  BÖLÜM 5  İstek gövdesi ve arayüz yardımcıları
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}

/* =====================================================================
 *  BÖLÜM 1 – JSON ZARFI
 * ---------------------------------------------------------------------
 *  Tüm başarılı yanıtlar:  { "data": ... , "meta": {...}? }
 *  Tüm hatalar:            { "error": { "code": "...", "message": "...", "details": {...}? } }
 *
 *  Tek bir çıkış noktası → istemci her zaman aynı biçimi bekler.
 *  "code" makine içindir ve DEĞİŞMEZ; "message" insan içindir ve
 *  değişebilir. İstemci koşullarını message'a bağlamamalıdır.
 * ================================================================== */

/**
 * Yanıtı yazar ve çıkar.
 *
 * JSON_INVALID_UTF8_SUBSTITUTE: not gövdesi ya da hata metni dış bir
 * kaynaktan gelen bozuk bir bayt içerebilir. O bayrak olmadan
 * json_encode() sessizce `false` döner ve istemci BOŞ gövde alır —
 * hata mesajı da dahil her şey kaybolur. Tek bozuk bayt yüzünden
 * yanıtın tamamının yok olmasındansa o bayt "?" olsun.
 */
function api_json(mixed $payload, int $status = 200, array $headers = []): never
{
    if (!headers_sent()) {
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $k => $v) {
            header("$k: $v");
        }

        if ($status !== 204) {
            header('Content-Type: application/json; charset=utf-8');
        }

        /*  DURUM KODU EN SONA YAZILIR — ÖLÇÜLEN HATA.
         *
         *  PHP'nin header() fonksiyonu bazı başlıkları GÖRÜP durum
         *  kodunu KENDİLİĞİNDEN değiştirir: "Location:" 302 yapar,
         *  "WWW-Authenticate:" ise 401 yapar.
         *
         *  Bu yüzden 403 insufficient_scope yanıtları tel üzerinde
         *  401 olarak çıkıyordu: gövde doğru koddan söz ederken HTTP
         *  durumu yanlıştı. İstemci "jetonum geçersiz, yenisini
         *  alayım" diye sonsuz döngüye girerdi — oysa jeton
         *  gayet geçerli, yalnızca yetkisiz.
         *
         *  http_response_code() en sonda çağrılınca son sözü söyler. */
        http_response_code($status);

        /*  204 No Content GÖVDE TAŞIMAZ (RFC 9110). Content-Type
         *  göndermek de yanlıştır: gövde yoksa türü de yoktur.
         *  Eskiden burada "null" dizesi basılıyordu; kimi istemci
         *  kütüphanesi bunu protokol hatası sayar. */
        if ($status === 204) {
            exit;
        }
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function api_ok(mixed $data, int $status = 200, array $meta = [], array $headers = []): never
{
    $out = ['data' => $data];
    if ($meta) {
        $out['meta'] = $meta;
    }
    api_json($out, $status, $headers);
}

function api_fail(string $code, string $message, int $status = 400, array $details = [], array $headers = []): never
{
    $err = ['code' => $code, 'message' => $message];
    if ($details) {
        $err['details'] = $details;
    }
    api_json(['error' => $err], $status, $headers);
}

/* =====================================================================
 *  BÖLÜM 2 – JWT (HS256)  — kütüphane YOK
 * ---------------------------------------------------------------------
 *  Yapı:  base64url(header) . base64url(payload) . base64url(signature)
 *  signature = HMAC-SHA256( header.payload , JWT_SECRET )
 *
 *  ÖNEMLİ: Payload ŞİFRELİ DEĞİLDİR, yalnızca kodlanmıştır. Jetonu ele
 *  geçiren herkes içindeki claim'leri okuyabilir. İmza gizliliği değil
 *  BÜTÜNLÜĞÜ sağlar: "bu içerik değiştirilmedi" der. Jetona parola,
 *  e-posta, TCKN gibi veri koymayın.
 *
 *  Doğrulamada dikkat edilenler:
 *   · İmza SABİT ZAMANDA (hash_equals) kıyaslanır — bayt bayt erken
 *     çıkan bir kıyas, doğru imzayı deneme yanılmayla bulmayı
 *     ölçülebilir biçimde kolaylaştırır.
 *   · alg=none SALDIRISI: header'daki alg 'HS256' değilse REDDEDİLİR.
 *     Kütüphane kullanan kodlarda klasik hata, algoritmayı JETONUN
 *     KENDİSİNDEN okumaktır; saldırgan alg'i "none" yapıp imzayı siler.
 *   · exp / nbf kontrolleri JWT_LEEWAY toleransıyla yapılır.
 *   · iss ve aud beklenen değerlerle eşleşmeli.
 * ================================================================== */

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $pad = strlen($s) % 4;
    if ($pad) {
        $s .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($s, '-_', '+/')) ?: '';
}

/**
 * @param array<string,mixed> $claims  sub, scopes vb.
 *        iat/exp/iss/aud/jti otomatik eklenir; $claims onları EZEBİLİR
 *        (demo jetonları bunu kullanır).
 */
function jwt_encode(array $claims): string
{
    $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
    $now     = time();
    $payload = array_merge([
        'iss' => JWT_ISS,
        'aud' => JWT_AUD,
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + JWT_TTL,
        /*  jti: jetonun tekil kimliği. Bu örnekte kara liste yok ama
         *  alan baştan bulunur — sonradan iptal listesi eklemek
         *  isteyen, dolaşımdaki jetonları kırmadan ekleyebilsin. */
        'jti' => bin2hex(random_bytes(8)),
    ], $claims);

    /*  null değerli claim TÜMÜYLE KALDIRILIR.
     *  Bir claim'i "yok" yapmanın başka yolu yoktur: array_merge
     *  yalnızca ezer, silmez. Demo jetonlarındaki "no_expiry"
     *  senaryosu ('exp' => null) tam olarak buna dayanır — exp'i
     *  boş bir değere çekmek yetmez, alanın hiç bulunmaması gerekir. */
    $payload = array_filter($payload, static fn($v) => $v !== null);

    $segments = [
        b64url_encode((string) json_encode($header, JSON_UNESCAPED_SLASHES)),
        b64url_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)),
    ];
    $signingInput = implode('.', $segments);
    $segments[]   = b64url_encode(hash_hmac('sha256', $signingInput, JWT_SECRET, true));

    return implode('.', $segments);
}

/**
 * @return array{0:?array<string,mixed>,1:?string}  [claims, hataKoduVeyaNull]
 */
function jwt_decode(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return [null, 'malformed'];
    }
    [$h64, $p64, $s64] = $parts;

    $header = json_decode(b64url_decode($h64), true);
    if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
        // "alg: none" ya da RS256/HS256 karışıklığı → reddet.
        return [null, 'bad_alg'];
    }

    /*  İMZA ÖNCE DOĞRULANIR, claim'ler SONRA okunur.
     *  Sıra tersine dönerse doğrulanmamış veriyle iş yapılmış olur. */
    $expectedSig = hash_hmac('sha256', "$h64.$p64", JWT_SECRET, true);
    if (!hash_equals($expectedSig, b64url_decode($s64))) {
        return [null, 'bad_signature'];
    }

    $claims = json_decode(b64url_decode($p64), true);
    if (!is_array($claims)) {
        return [null, 'bad_payload'];
    }

    $now = time();
    if (isset($claims['nbf']) && $now + JWT_LEEWAY < (int) $claims['nbf']) {
        return [null, 'not_yet_valid'];
    }
    if (isset($claims['exp']) && $now - JWT_LEEWAY >= (int) $claims['exp']) {
        return [null, 'expired'];
    }
    /*  exp YOKSA jeton sonsuza dek geçerli olurdu. Bunu kabul etmiyoruz:
     *  süresiz jeton, çalındığında geri alınamaz bir anahtardır. */
    if (!isset($claims['exp'])) {
        return [null, 'no_expiry'];
    }
    if (($claims['iss'] ?? null) !== JWT_ISS || ($claims['aud'] ?? null) !== JWT_AUD) {
        return [null, 'bad_audience'];
    }

    return [$claims, null];
}

/** Jeton doğrulama hata kodlarının Türkçe karşılıkları. */
function jwt_error_message(string $err): string
{
    return [
        'malformed'     => 'Jeton biçimi bozuk: nokta ile ayrılmış üç parça bekleniyor.',
        'bad_alg'       => 'Desteklenmeyen imza algoritması. Yalnızca HS256 kabul edilir.',
        'bad_signature' => 'Jeton imzası geçersiz. Jeton değiştirilmiş ya da başka bir anahtarla imzalanmış.',
        'bad_payload'   => 'Jeton içeriği çözülemedi.',
        'not_yet_valid' => 'Jeton henüz geçerli değil (nbf ileri tarihli).',
        'expired'       => 'Jetonun süresi doldu. Yeni bir jeton alın.',
        'no_expiry'     => 'Jetonda son kullanma (exp) yok; süresiz jeton kabul edilmez.',
        'bad_audience'  => 'Jeton bu servis için üretilmemiş (iss/aud eşleşmiyor).',
    ][$err] ?? 'Jeton doğrulanamadı.';
}

/* =====================================================================
 *  BÖLÜM 3 – HIZ SINIRI  (anahtar başına, kayan pencere)
 * ---------------------------------------------------------------------
 *  Neden dosya? Bu örneğin bağımlılığı yok; Redis şart koşmadan
 *  çalışsın istiyoruz. Tek sunucuda doğru çalışır. Birden çok sunucu
 *  varsa sayaç ortak bir yerde tutulmalıdır (Redis, Memcached) —
 *  yoksa her sunucu kendi payını sayar ve sınır N katına çıkar.
 *
 *  Kayan pencere: son N saniyedeki istek ZAMAN DAMGALARI tutulur.
 *  Sabit pencere (dakikanın başında sıfırlanan sayaç) sınırın iki
 *  katına izin verir: 59. saniyede 120, 61. saniyede 120 daha.
 * ================================================================== */

function rate_limit_dir(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cy_api_jwt_rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

/**
 * @return array{0:bool,1:int,2:int}  [izinVar, kalan, resetSaniye]
 */
function rate_check(string $bucket, int $limit, int $window): array
{
    $file = rate_limit_dir() . DIRECTORY_SEPARATOR . sha1($bucket) . '.json';
    $h = @fopen($file, 'c+');
    if ($h === false) {
        /*  Sayaç yazılamıyorsa isteği ENGELLEMİYORUZ. Bu bilinçli bir
         *  tercihtir: disk sorunu bütün API'yi kapatmasın. Sınırın
         *  kendisi bir güvenlik duvarı değil, kötüye kullanımı
         *  yavaşlatan bir önlemdir. */
        return [true, $limit, $window];
    }

    /*  LOCK_EX: oku-değiştir-yaz üçlüsü bölünemez olmalı. Kilitsiz
     *  yazımda aynı anda gelen iki istek birbirinin sayacını ezer ve
     *  sınır aşılır. */
    flock($h, LOCK_EX);

    $now  = microtime(true);
    $hits = json_decode((string) stream_get_contents($h), true);
    $hits = is_array($hits) ? $hits : [];
    $hits = array_values(array_filter($hits, static fn($t) => is_numeric($t) && ($now - (float) $t) < $window));

    $allowed = count($hits) < $limit;
    if ($allowed) {
        $hits[] = $now;
        ftruncate($h, 0);
        rewind($h);
        fwrite($h, (string) json_encode($hits));
        fflush($h);
    }
    flock($h, LOCK_UN);
    fclose($h);

    $remaining = max(0, $limit - count($hits));
    $reset = $hits ? (int) ceil($window - ($now - (float) $hits[0])) : $window;

    return [$allowed, $remaining, max(1, $reset)];
}

/**
 * Sınırı uygular ve X-RateLimit-* başlıklarını her yanıta ekler.
 * Başlıklar SADECE 429'da değil HER ZAMAN gönderilir: iyi bir istemci
 * sınıra çarpmadan önce yavaşlayabilsin.
 */
function enforce_rate(string $bucket, array $cfg): void
{
    [$allowed, $remaining, $reset] = rate_check($bucket, $cfg[0], $cfg[1]);

    if (!headers_sent()) {
        header('X-RateLimit-Limit: ' . $cfg[0]);
        header('X-RateLimit-Remaining: ' . $remaining);
        header('X-RateLimit-Reset: ' . $reset);
    }

    if (!$allowed) {
        api_fail(
            'rate_limited',
            "Hız sınırı aşıldı. {$reset} saniye sonra tekrar deneyin.",
            429,
            ['retry_after' => $reset],
            /*  Retry-After standart başlıktır; istemci kütüphaneleri
             *  yeniden deneme aralığını buradan okur. */
            ['Retry-After' => (string) $reset]
        );
    }
}

/* =====================================================================
 *  BÖLÜM 4 – KİMLİK
 * ---------------------------------------------------------------------
 *  İki aşama:
 *    1) POST /auth/token : API anahtarı (key_id + secret) → kısa ömürlü
 *       JWT. secret veritabanında HASH'li tutulur.
 *    2) Diğer uçlar : Authorization: Bearer <jwt> → jwt_decode
 *       + kapsam (scope) kontrolü.
 * ================================================================== */

function bearer_token(): ?string
{
    /*  Authorization başlığı bazı Apache/CGI kurulumlarında PHP'ye HİÇ
     *  ulaşmaz (mod_php dışındaki SAPI'lerde başlık düşürülür). Üç
     *  kaynak da denenir; api/.htaccess ayrıca SetEnvIf ile taşır. */
    $hdr = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? (function_exists('apache_request_headers') ? (apache_request_headers()['Authorization'] ?? '') : '');

    if (is_string($hdr) && preg_match('/^Bearer\s+(.+)$/i', trim($hdr), $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Bearer JWT'yi doğrula, geçerliyse claims döndür; değilse 401 ile çık.
 *
 * WWW-Authenticate başlığı RFC 6750'nin istediği biçimdedir; standart
 * istemciler "hangi şema, neden reddedildi" bilgisini oradan okur.
 *
 * @return array<string,mixed>
 */
function require_auth(): array
{
    $jwt = bearer_token();
    if ($jwt === null) {
        api_fail(
            'unauthorized',
            'Authorization: Bearer <token> başlığı gerekli.',
            401,
            [],
            ['WWW-Authenticate' => 'Bearer realm="' . JWT_AUD . '"']
        );
    }

    [$claims, $err] = jwt_decode($jwt);
    if ($err !== null) {
        api_fail(
            'invalid_token',
            jwt_error_message($err),
            401,
            ['reason' => $err],
            ['WWW-Authenticate' => 'Bearer error="invalid_token"']
        );
    }

    return $claims;
}

/** Jetondaki kapsamları dizi olarak verir (dize ya da dizi gelebilir). */
function claim_scopes(array $claims): array
{
    $have = $claims['scopes'] ?? '';
    if (!is_array($have)) {
        $have = explode(' ', (string) $have);
    }
    /*  Beyaz liste: veritabanına elle yazılmış tanınmayan bir kapsam
     *  jetona girse bile burada elenir. */
    return array_values(array_filter(
        array_map('strval', $have),
        static fn($s) => isset(KNOWN_SCOPES[$s])
    ));
}

function require_scope(array $claims, string $scope): void
{
    $have = claim_scopes($claims);
    if (!in_array($scope, $have, true)) {
        api_fail(
            'insufficient_scope',
            "Bu işlem '$scope' kapsamını gerektirir; jetonunuzda yok.",
            403,
            ['required' => $scope, 'granted' => $have],
            ['WWW-Authenticate' => 'Bearer error="insufficient_scope", scope="' . $scope . '"']
        );
    }
}

/* =====================================================================
 *  BÖLÜM 5 – İSTEK GÖVDESİ VE ARAYÜZ
 * ================================================================== */

/**
 * İstek gövdesini JSON ya da form olarak okur.
 *
 * PHP $_POST'u yalnızca POST + form içerikte doldurur; PUT gövdesini
 * hiç ayrıştırmaz. Bu yüzden ham gövde elle okunur ve her iki tür de
 * desteklenir — istemci curl da olabilir, tarayıcı formu da.
 *
 * @return array{0:array,1:?string}  [gövde, ayrıştırmaHatasıVeyaNull]
 */
function read_body(): array
{
    $ctype = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $raw   = (string) file_get_contents('php://input');

    if ($raw === '') {
        return [$_POST ?: [], null];
    }

    if (str_contains($ctype, 'application/json') || str_starts_with(ltrim($raw), '{')) {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            /*  Bozuk JSON'u SESSİZCE boş dizi saymak, "title zorunlu"
             *  gibi yanıltıcı bir doğrulama hatası üretirdi. Gerçek
             *  sebep söylenmeli. */
            return [[], json_last_error_msg()];
        }
        return [$decoded, null];
    }

    parse_str($raw, $form);
    return [is_array($form) ? $form : [], null];
}

/** Gövdeyi okur; ayrıştırma hatası varsa 400 ile çıkar. */
function body_or_fail(): array
{
    [$body, $err] = read_body();
    if ($err !== null) {
        api_fail('invalid_json', 'İstek gövdesi geçerli JSON değil: ' . $err, 400);
    }
    return $body;
}

/** HTML kaçışı — yalnızca arayüz (index.php) için. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
