<?php
/**
 * =====================================================================
 *  API ÖN DENETLEYİCİ (front controller)
 *  cilginyazilim.com – Sıfır Bağımlılık JWT REST API
 * ---------------------------------------------------------------------
 *  Tüm /api/* istekleri buraya düşer (bkz. api/.htaccess). Yol üç
 *  kaynaktan çözülür ve üçü de çalışır — mod_rewrite kapalı bir
 *  sunucuda API'nin tümden ölmemesi için:
 *
 *      /api/notes              (rewrite ile)
 *      /api/index.php/notes    (PATH_INFO ile)
 *      /api/index.php?path=/notes
 *
 *  UÇLAR
 *    GET    /                      uç listesi (jeton gerektirmez)
 *    POST   /auth/token            key_id + secret  → { token, expires_in, scopes }
 *    POST   /auth/demo-token       BİLEREK BOZUK jeton (yalnızca DEMO_TOKENS açıkken)
 *    GET    /me                    (scope: profile:read)
 *    GET    /stats                 (scope: profile:read)
 *    GET    /notes                 (scope: notes:read)   ?page= ?limit= ?q= ?sort= ?dir=
 *    POST   /notes                 (scope: notes:write)  { title, body }
 *    GET    /notes/{id}            (scope: notes:read)
 *    PUT    /notes/{id}            (scope: notes:write)  { title?, body? }
 *    DELETE /notes/{id}            (scope: notes:write)
 *
 *  YETKİLENDİRME SIRASI — bilinçlidir:
 *      1) yol çözümü  2) kimlik (401)  3) hız sınırı  4) kapsam (403)
 *  Hız sınırı kimlikten SONRA gelir çünkü sayaç anahtar başınadır;
 *  önce gelseydi IP başına saymak zorunda kalırdık ve tek IP'nin
 *  ardındaki bütün istemciler birbirinin hakkını yerdi.
 * =====================================================================
 */

declare(strict_types=1);

define('CY_APP', true);
require __DIR__ . '/../system/config.php';
require __DIR__ . '/../system/function.php';

/* ---------------------------------------------------------------------
 *  CORS
 * ---------------------------------------------------------------------
 *  Bu API çerez KULLANMAZ; kimlik Authorization başlığıyla taşınır.
 *  Dolayısıyla tarayıcının kendiliğinden gönderdiği bir kimlik yoktur
 *  ve CSRF bu tasarımda konu değildir — saldırganın sayfası isteği
 *  atabilir ama jetonu ekleyemez.
 *
 *  "*" burada bilinçlidir: bu bir herkese açık demo API'dir. Kendi
 *  projenizde origin'i SINIRLAYIN; ve Allow-Credentials açacaksanız
 *  "*" kullanmak zaten yasaktır.
 * ------------------------------------------------------------------ */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, Location');
header('Access-Control-Max-Age: 600');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    /*  Ön uçuş (preflight) isteği: tarayıcı asıl isteği göndermeden
     *  önce "bu başlıklarla çağırabilir miyim?" diye sorar. Gövdesiz
     *  204 döner; kimlik istemez, çünkü henüz kimlik gönderilmemiştir. */
    http_response_code(204);
    exit;
}

/**
 * İstek yolunu çözer: ?path= → PATH_INFO → REQUEST_URI.
 * Her zaman "/" ile başlar, sonda "/" bulunmaz.
 */
function resolve_path(): string
{
    $raw = (string) ($_GET['path'] ?? '');

    if ($raw === '') {
        $raw = (string) ($_SERVER['PATH_INFO'] ?? '');
    }

    if ($raw === '' && isset($_SERVER['REQUEST_URI'])) {
        // /rest-api-jwt/api/notes/12?limit=5  →  /notes/12
        $uri = (string) strtok((string) $_SERVER['REQUEST_URI'], '?');
        $raw = (string) preg_replace('#^.*/api(?:/index\.php)?#', '', $uri);
    }

    $raw = '/' . trim($raw, '/');

    /*  Yol yalnızca yönlendirme için kullanılır, SQL'e girmez; yine de
     *  hata mesajlarında yankılandığı için temizliyoruz. */
    return (string) preg_replace('#[^A-Za-z0-9/_.\-]#', '', $raw);
}

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path   = resolve_path();

try {
    /* ---- GET /  → uç listesi (discovery) --------------------------- */
    if ($path === '/' || $path === '/index.php') {
        if ($method !== 'GET') {
            method_not_allowed($method, ['GET']);
        }
        enforce_rate('disc:' . client_ip_fallback(), RATE_LIMIT_DEMO);
        handle_index();
    }

    /* ---- POST /auth/token ------------------------------------------ */
    if ($path === '/auth/token') {
        if ($method !== 'POST') {
            method_not_allowed($method, ['POST']);
        }
        handle_token($db);
    }

    /* ---- POST /auth/demo-token ------------------------------------- */
    if ($path === '/auth/demo-token') {
        if (!DEMO_TOKENS) {
            /*  Kapalıyken 404: "burada bir şey var ama kapalı" demek
             *  bile gereksiz bilgi verir. */
            api_fail('not_found', "Uç nokta yok: $method $path", 404);
        }
        if ($method !== 'POST') {
            method_not_allowed($method, ['POST']);
        }
        handle_demo_token($db);
    }

    /* =================================================================
     *  BURADAN SONRASI JETON İSTER.
     * ============================================================== */
    $claims = require_auth();

    // Anahtar başına hız sınırı (jetondaki sub = key_id).
    enforce_rate('key:' . (string) ($claims['sub'] ?? 'anon'), RATE_LIMIT_API);

    if ($path === '/me') {
        if ($method !== 'GET') {
            method_not_allowed($method, ['GET']);
        }
        require_scope($claims, 'profile:read');
        handle_me($db, $claims);
    }

    if ($path === '/stats') {
        if ($method !== 'GET') {
            method_not_allowed($method, ['GET']);
        }
        require_scope($claims, 'profile:read');
        handle_stats($db, $claims);
    }

    if ($path === '/notes') {
        if ($method === 'GET') {
            require_scope($claims, 'notes:read');
            handle_notes_list($db, $claims);
        }
        if ($method === 'POST') {
            require_scope($claims, 'notes:write');
            handle_notes_create($db, $claims);
        }
        /*  Koleksiyona PUT/DELETE atmak "yok" değil "burada olmaz"dır.
         *  404 döndürmek istemciyi yanlış yere bakmaya yollar. */
        method_not_allowed($method, ['GET', 'POST']);
    }

    if (preg_match('#^/notes/(\d+)$#', $path, $m)) {
        $id = (int) $m[1];
        if ($method === 'GET') {
            require_scope($claims, 'notes:read');
            handle_notes_get($db, $claims, $id);
        }
        if ($method === 'PUT') {
            require_scope($claims, 'notes:write');
            handle_notes_update($db, $claims, $id);
        }
        if ($method === 'DELETE') {
            require_scope($claims, 'notes:write');
            handle_notes_delete($db, $claims, $id);
        }
        method_not_allowed($method, ['GET', 'PUT', 'DELETE']);
    }

    api_fail('not_found', "Uç nokta yok: $method $path", 404);

} catch (PDOException $e) {
    /*  Hata METNİ istemciye yalnızca APP_DEBUG açıkken gider; kayda
     *  her zaman tam hâliyle yazılır. Canlıda SQL metni, tablo adı ve
     *  dosya yolu sızdırmak saldırgana bedava harita vermektir. */
    error_log('[API] DB: ' . $e->getMessage());
    api_fail('server_error', APP_DEBUG ? $e->getMessage() : 'Sunucu hatası.', 500);
} catch (Throwable $e) {
    error_log('[API] Hata: ' . $e->getMessage());
    api_fail('server_error', APP_DEBUG ? $e->getMessage() : 'Sunucu hatası.', 500);
}

/* =====================================================================
 *  ORTAK
 * ================================================================== */

/**
 * 405 yanıtı. Allow başlığı ZORUNLUDUR (RFC 9110): istemciye hangi
 * metotların kabul edildiğini söyler.
 */
function method_not_allowed(string $method, array $allowed): never
{
    api_fail(
        'method_not_allowed',
        "'$method' bu kaynak için desteklenmiyor. İzin verilenler: " . implode(', ', $allowed) . '.',
        405,
        ['allowed' => $allowed],
        ['Allow' => implode(', ', $allowed)]
    );
}

function client_ip_fallback(): string
{
    /*  DİKKAT: X-Forwarded-For BİLEREK okunmuyor. O başlığı istemci
     *  uydurabilir; güvenilir olması için hangi vekil sunucunun
     *  eklediğini bilmek gerekir. Uydurulabilir bir değere göre hız
     *  sınırı saymak, sınırı hiç koymamakla aynı şeydir. */
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'anon');
}

/* =====================================================================
 *  GET /  — uç listesi
 * ---------------------------------------------------------------------
 *  Kimlik istemez. Neden güvenli? Uç adresleri zaten sırdır sayılmaz;
 *  gizlilik "kimse yolu bilmesin" ile değil, yetkilendirmeyle sağlanır.
 * ================================================================== */
function handle_index(): never
{
    api_ok([
        'name'    => 'Çılgın Yazılım · JWT REST API',
        'version' => '1.0.0',
        'auth'    => [
            'type'       => 'Bearer JWT (HS256)',
            'token_url'  => '/auth/token',
            'expires_in' => JWT_TTL,
            'scopes'     => KNOWN_SCOPES,
        ],
        'endpoints' => array_filter([
            'GET /'                  => 'Bu liste',
            'POST /auth/token'       => 'key_id + secret → jeton',
            'POST /auth/demo-token'  => DEMO_TOKENS ? 'Bilerek bozuk demo jetonu' : null,
            'GET /me'                => 'Anahtar künyesi (profile:read)',
            'GET /stats'             => 'Sayaçlar (profile:read)',
            'GET /notes'             => 'Not listesi (notes:read)',
            'POST /notes'            => 'Not oluştur (notes:write)',
            'GET /notes/{id}'        => 'Tek not (notes:read)',
            'PUT /notes/{id}'        => 'Not güncelle (notes:write)',
            'DELETE /notes/{id}'     => 'Not sil (notes:write)',
        ]),
        'rate_limits' => [
            'token' => RATE_LIMIT_TOKEN[0] . '/' . RATE_LIMIT_TOKEN[1] . 's',
            'api'   => RATE_LIMIT_API[0] . '/' . RATE_LIMIT_API[1] . 's',
        ],
    ]);
}

/* =====================================================================
 *  POST /auth/token
 * ================================================================== */
function handle_token(PDO $db): never
{
    $body   = body_or_fail();
    $keyId  = trim((string) ($body['key_id'] ?? ''));
    $secret = (string) ($body['secret'] ?? '');

    /*  Sınır DOĞRULAMADAN ÖNCE uygulanır: sınırın amacı zaten geçersiz
     *  denemeleri yavaşlatmaktır. Sayaç anahtar kimliği başınadır;
     *  key_id boşsa IP'ye düşülür — yoksa saldırgan key_id'yi boş
     *  bırakarak sınırdan kaçardı. */
    enforce_rate('token:' . ($keyId !== '' ? $keyId : client_ip_fallback()), RATE_LIMIT_TOKEN);

    if ($keyId === '' || $secret === '') {
        api_fail('invalid_request', 'key_id ve secret zorunludur.', 422, array_filter([
            'key_id' => $keyId === '' ? 'Zorunlu alan.' : null,
            'secret' => $secret === '' ? 'Zorunlu alan.' : null,
        ]));
    }

    $stmt = $db->prepare('SELECT id, name, key_id, secret_hash, scopes, active FROM api_keys WHERE key_id = :k LIMIT 1');
    $stmt->execute([':k' => $keyId]);
    $row = $stmt->fetch();

    /*  ZAMANLAMA SALDIRISI: anahtar yoksa hemen dönmek, "bu key_id var
     *  ama secret yanlış" ile "bu key_id hiç yok" arasındaki farkı
     *  ölçülebilir kılar. Sahte bir hash'le doğrulama yine de
     *  çalıştırılır ki iki yol da aynı süreyi harcasın. */
    $hash = is_array($row) ? (string) $row['secret_hash'] : '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinv';
    $secretOk = password_verify($secret, $hash);
    $ok = $secretOk && is_array($row) && (int) $row['active'] === 1;

    if (!$ok) {
        /*  Pasif anahtar ile yanlış secret AYNI mesajı alır. "Anahtar
         *  doğru ama pasif" demek, geçerli bir key_id'yi doğrulamış
         *  olurdu (enumeration). */
        api_fail('invalid_client', 'API anahtarı ya da secret hatalı; anahtar pasif de olabilir.', 401);
    }

    /*  Kapsamlar veritabanından gelir ama beyaz listeden geçer. */
    $scopes = array_values(array_filter(
        explode(' ', (string) $row['scopes']),
        static fn($s) => $s !== '' && isset(KNOWN_SCOPES[$s])
    ));

    $db->prepare('UPDATE api_keys SET last_used_at = NOW() WHERE id = :id')->execute([':id' => $row['id']]);

    $token = jwt_encode(['sub' => $row['key_id'], 'scopes' => $scopes]);

    api_ok([
        'token'      => $token,
        'token_type' => 'Bearer',
        'expires_in' => JWT_TTL,
        'expires_at' => gmdate('c', time() + JWT_TTL),
        'scopes'     => $scopes,
        'key_name'   => $row['name'],
    ]);
}

/* =====================================================================
 *  POST /auth/demo-token   { key_id, fault }
 * ---------------------------------------------------------------------
 *  BİLEREK BOZUK jeton üretir. Bir öğrenme aracıdır: doğrulamanın
 *  gerçekten çalıştığını göstermenin başka yolu yok — bozuk jetonu
 *  istemcide üretmek için imza sırrı gerekir, o da istemcide olmamalı.
 *
 *  secret İSTEMEZ, çünkü ürettiği jetonların hepsi ZATEN geçersizdir;
 *  hiçbiri bir yetki taşımaz. DEMO_TOKENS=false iken uç yoktur.
 * ================================================================== */
function handle_demo_token(PDO $db): never
{
    $body  = body_or_fail();
    $fault = (string) ($body['fault'] ?? '');
    $keyId = trim((string) ($body['key_id'] ?? 'demo_full'));

    enforce_rate('demo:' . client_ip_fallback(), RATE_LIMIT_DEMO);

    $now = time();

    /*  Beyaz liste: hangi bozukluk hangi claim'i nasıl kırıyor?
     *  Değerler doğrudan claim olarak yazılır ve varsayılanları ezer. */
    $faults = [
        'expired' => [
            'claims' => ['iat' => $now - 7200, 'nbf' => $now - 7200, 'exp' => $now - 3600],
            'beklenen' => 'invalid_token / expired',
            'aciklama' => 'exp geçmişte. Jetonu üreten sunucu doğru, imza doğru — ama süresi dolmuş.',
        ],
        'future' => [
            'claims' => ['iat' => $now + 3600, 'nbf' => $now + 3600, 'exp' => $now + 7200],
            'beklenen' => 'invalid_token / not_yet_valid',
            'aciklama' => 'nbf ileri tarihli. Saati ileri alınmış bir sunucunun ürettiği jeton böyle görünür.',
        ],
        'bad_audience' => [
            'claims' => ['aud' => 'baska-bir-servis'],
            'beklenen' => 'invalid_token / bad_audience',
            'aciklama' => 'İmza geçerli ama jeton BAŞKA bir servis için üretilmiş. Aynı sırrı paylaşan iki servis arasında jeton taşınmasını bu kontrol engeller.',
        ],
        'no_expiry' => [
            'claims' => ['exp' => null],
            'beklenen' => 'invalid_token / no_expiry',
            'aciklama' => 'exp claim\'i yok. Süresiz jeton, çalındığında geri alınamayan bir anahtardır; reddedilir.',
        ],
        'no_scopes' => [
            'claims' => ['scopes' => []],
            'beklenen' => '403 insufficient_scope',
            'aciklama' => 'Jeton tümüyle GEÇERLİ: imza doğru, süre dolmamış. Kimlik var ama YETKİ yok. Kimlik doğrulama ile yetkilendirmenin farkı tam olarak budur.',
        ],
    ];

    if (!isset($faults[$fault])) {
        api_fail('invalid_request', 'Bilinmeyen fault türü.', 422, ['allowed' => array_keys($faults)]);
    }

    $stmt = $db->prepare('SELECT key_id, scopes FROM api_keys WHERE key_id = :k LIMIT 1');
    $stmt->execute([':k' => $keyId]);
    $row = $stmt->fetch();
    if (!$row) {
        api_fail('not_found', 'Anahtar bulunamadı.', 404);
    }

    $claims = array_merge(
        [
            'sub'    => $row['key_id'],
            'scopes' => array_values(array_filter(explode(' ', (string) $row['scopes']), static fn($s) => $s !== '')),
        ],
        $faults[$fault]['claims']
    );

    /*  'exp' => null gönderiyoruz; jwt_encode null claim'leri
     *  payload'dan tümüyle SİLER (bkz. oradaki açıklama). Alanı
     *  boş bir değere çekmek yetmezdi — jwt_decode "exp yok" ile
     *  "exp boş" arasındaki farkı görmeli. */

    api_ok([
        'token'      => jwt_encode($claims),
        'token_type' => 'Bearer',
        'fault'      => $fault,
        'beklenen'   => $faults[$fault]['beklenen'],
        'aciklama'   => $faults[$fault]['aciklama'],
    ]);
}

/* =====================================================================
 *  GET /me
 * ================================================================== */
function handle_me(PDO $db, array $claims): never
{
    $stmt = $db->prepare('SELECT name, key_id, scopes, active, created_at, last_used_at FROM api_keys WHERE key_id = :k');
    $stmt->execute([':k' => $claims['sub'] ?? '']);
    $row = $stmt->fetch();

    if (!$row) {
        /*  Jeton geçerli ama anahtar silinmiş olabilir. JWT sunucuda
         *  saklanmadığı için bu durum ancak BURADA fark edilir —
         *  jetonu iptal edemeyiz, kaynağa erişimi keseriz. */
        api_fail('not_found', 'Jeton geçerli ama anahtar artık yok.', 404);
    }

    api_ok([
        'name'         => $row['name'],
        'key_id'       => $row['key_id'],
        'active'       => (bool) $row['active'],
        'scopes'       => claim_scopes($claims),
        'key_scopes'   => array_values(array_filter(explode(' ', (string) $row['scopes']), static fn($s) => $s !== '')),
        'created_at'   => $row['created_at'],
        'last_used_at' => $row['last_used_at'],
        'token'        => [
            'jti' => $claims['jti'] ?? null,
            'iat' => $claims['iat'] ?? null,
            'exp' => $claims['exp'] ?? null,
            'kalan_saniye' => isset($claims['exp']) ? max(0, (int) $claims['exp'] - time()) : null,
        ],
    ]);
}

/* =====================================================================
 *  GET /stats
 * ---------------------------------------------------------------------
 *  Arayüzdeki sayaç şeridi buradan beslenir. Not sayısı YALNIZCA
 *  jetonun sahibi için sayılır; başka anahtarın not sayısı bile
 *  paylaşılmaz.
 * ================================================================== */
function handle_stats(PDO $db, array $claims): never
{
    $owner = (string) ($claims['sub'] ?? '');

    $s = $db->prepare(
        'SELECT COUNT(*) AS toplam,
                SUM(updated_at > (NOW() - INTERVAL 1 DAY)) AS son_gun
         FROM api_notes WHERE owner_key = :k'
    );
    $s->execute([':k' => $owner]);
    $notes = $s->fetch() ?: ['toplam' => 0, 'son_gun' => 0];

    $keys = $db->query('SELECT COUNT(*) AS toplam, SUM(active = 1) AS aktif FROM api_keys')->fetch();

    api_ok([
        'notlarim'       => (int) $notes['toplam'],
        'son_gun'        => (int) $notes['son_gun'],
        'anahtar_toplam' => (int) $keys['toplam'],
        'anahtar_aktif'  => (int) $keys['aktif'],
        'kapsamlarim'    => claim_scopes($claims),
        'kalan_saniye'   => isset($claims['exp']) ? max(0, (int) $claims['exp'] - time()) : null,
    ]);
}

/* =====================================================================
 *  /notes CRUD
 * ---------------------------------------------------------------------
 *  SAHİPLİK HER SORGUDA: owner_key koşulu WHERE'den asla düşmez.
 *  "Önce kaydı çek, sonra PHP'de sahibi mi diye bak" kalıbı, bir
 *  yerde unutulduğunda başkasının kaydını sızdırır. Koşulu SQL'e
 *  gömmek, unutmayı imkânsız kılar.
 *
 *  Ayrıca not: yetkisiz kayıt için 403 değil 404 dönüyoruz. 403,
 *  "böyle bir kayıt var ama senin değil" bilgisini verir; başkasının
 *  kaç kaydı olduğunu saymaya yarar.
 * ================================================================== */
function handle_notes_list(PDO $db, array $claims): never
{
    $page  = max(1, (int) ($_GET['page'] ?? 1));
    $limit = min(NOTES_PAGE_MAX, max(1, (int) ($_GET['limit'] ?? 10)));
    $off   = ($page - 1) * $limit;
    $q     = trim((string) ($_GET['q'] ?? ''));

    /*  SIRALAMA BEYAZ LİSTEDEN GEÇER. Sütun adı yer tutucuyla
     *  gönderilemez; istemciden gelen metni doğrudan ORDER BY'a
     *  yazmak klasik enjeksiyon kapısıdır. */
    $sortMap = ['id' => 'id', 'title' => 'title', 'updated_at' => 'updated_at', 'created_at' => 'created_at'];
    $sort = $sortMap[(string) ($_GET['sort'] ?? 'id')] ?? 'id';
    $dir  = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $where  = 'owner_key = :k';
    $params = [':k' => (string) ($claims['sub'] ?? '')];

    if ($q !== '') {
        /*  LIKE jokerleri kaçışlanır: "%" arayan bir kullanıcı
         *  bütün tabloyu eşleştirmesin. */
        /*  İKİ AYRI YER TUTUCU (:q1, :q2) — aynı ad iki kez kullanılamaz.
         *  PDO::ATTR_EMULATE_PREPARES = false iken sorgu MySQL'e gerçek
         *  prepared statement olarak gider ve adlar konumsal ?'lere
         *  çevrilir; aynı ad ikinci kez geçtiğinde PDO "Invalid parameter
         *  number" (HY093) atar. Emülasyon AÇIKKEN çalışıp KAPALIYKEN
         *  patlayan, bu yüzden de gözden kaçması kolay bir hatadır. */
        $where .= " AND (title LIKE :q1 ESCAPE '!' OR body LIKE :q2 ESCAPE '!')";
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $params[':q1'] = $like;
        $params[':q2'] = $like;
    }

    $c = $db->prepare("SELECT COUNT(*) FROM api_notes WHERE $where");
    $c->execute($params);
    $total = (int) $c->fetchColumn();

    $stmt = $db->prepare(
        "SELECT id, title, body, created_at, updated_at
         FROM api_notes WHERE $where ORDER BY $sort $dir, id DESC LIMIT :off, :lim"
    );
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':off', $off, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    api_ok($stmt->fetchAll(), 200, [
        'page'  => $page,
        'limit' => $limit,
        'total' => $total,
        'pages' => (int) ceil(max(1, $total) / $limit),
        'sort'  => $sort,
        'dir'   => strtolower($dir),
        'q'     => $q !== '' ? $q : null,
    ]);
}

function handle_notes_get(PDO $db, array $claims, int $id): never
{
    $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM api_notes WHERE id = :id AND owner_key = :k');
    $stmt->execute([':id' => $id, ':k' => $claims['sub'] ?? '']);
    $row = $stmt->fetch();

    if (!$row) {
        api_fail('not_found', "Not bulunamadı: #$id", 404);
    }
    api_ok($row);
}

/**
 * @param bool $partial  PUT'ta true: yalnızca GÖNDERİLEN alanlar
 *                       doğrulanır. false: title zorunludur.
 * @return array{0:array,1:array}  [temizlenmişVeri, hatalar]
 */
function validate_note_body(array $body, bool $partial): array
{
    $errors = [];
    $out = [];

    if (!$partial || array_key_exists('title', $body)) {
        $title = trim((string) ($body['title'] ?? ''));
        if (mb_strlen($title) < 2 || mb_strlen($title) > NOTE_TITLE_MAX) {
            $errors['title'] = 'Başlık 2-' . NOTE_TITLE_MAX . ' karakter olmalıdır.';
        }
        $out['title'] = $title;
    }

    if (!$partial || array_key_exists('body', $body)) {
        $text = (string) ($body['body'] ?? '');
        if (mb_strlen($text) > NOTE_BODY_MAX) {
            $errors['body'] = 'İçerik en fazla ' . number_format(NOTE_BODY_MAX, 0, ',', '.') . ' karakter olabilir.';
        }
        $out['body'] = $text;
    }

    return [$out, $errors];
}

function handle_notes_create(PDO $db, array $claims): never
{
    [$data, $errors] = validate_note_body(body_or_fail(), false);
    if ($errors) {
        api_fail('validation_failed', 'Girdi doğrulanamadı.', 422, $errors);
    }

    $stmt = $db->prepare('INSERT INTO api_notes (owner_key, title, body) VALUES (:k, :t, :b)');
    $stmt->execute([
        ':k' => $claims['sub'] ?? '',
        ':t' => $data['title'],
        ':b' => $data['body'] ?? '',
    ]);
    $id = (int) $db->lastInsertId();

    $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM api_notes WHERE id = :id');
    $stmt->execute([':id' => $id]);

    /*  201 + Location: yeni kaynağın adresi başlıkta durur. REST
     *  istemcileri bunu okur; gövdeye gömülü "location" alanı
     *  standart değildir. */
    api_ok($stmt->fetch(), 201, ['location' => "/notes/$id"], ['Location' => "/notes/$id"]);
}

function handle_notes_update(PDO $db, array $claims, int $id): never
{
    $stmt = $db->prepare('SELECT id FROM api_notes WHERE id = :id AND owner_key = :k');
    $stmt->execute([':id' => $id, ':k' => $claims['sub'] ?? '']);
    if ($stmt->fetchColumn() === false) {
        api_fail('not_found', "Not bulunamadı: #$id", 404);
    }

    [$data, $errors] = validate_note_body(body_or_fail(), true);
    if ($errors) {
        api_fail('validation_failed', 'Girdi doğrulanamadı.', 422, $errors);
    }
    if (!$data) {
        api_fail('invalid_request', 'Güncellenecek alan gönderilmedi (title ve/veya body).', 422);
    }

    /*  Alan adları BEYAZ LİSTEDEN gelir: validate_note_body yalnızca
     *  'title' ve 'body' üretir, dolayısıyla SET metnine istemci
     *  girdisi karışamaz. */
    $set = [];
    $params = [':id' => $id, ':k' => $claims['sub'] ?? ''];
    foreach ($data as $field => $val) {
        $set[] = "`$field` = :$field";
        $params[":$field"] = $val;
    }

    $db->prepare('UPDATE api_notes SET ' . implode(', ', $set) . ' WHERE id = :id AND owner_key = :k')
       ->execute($params);

    $stmt = $db->prepare('SELECT id, title, body, created_at, updated_at FROM api_notes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    api_ok($stmt->fetch());
}

function handle_notes_delete(PDO $db, array $claims, int $id): never
{
    $stmt = $db->prepare('DELETE FROM api_notes WHERE id = :id AND owner_key = :k');
    $stmt->execute([':id' => $id, ':k' => $claims['sub'] ?? '']);

    if ($stmt->rowCount() === 0) {
        api_fail('not_found', "Not bulunamadı: #$id", 404);
    }

    /*  204 No Content: silme başarılı, dönecek gövde yok. */
    api_json(null, 204);
}
