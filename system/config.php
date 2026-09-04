<?php
/**
 * =====================================================================
 *  YAPILANDIRMA
 *  cilginyazilim.com – Sıfır Bağımlılık JWT REST API
 * ---------------------------------------------------------------------
 *  Bu dosyada üç şey vardır ve üçü de "sır" sayılır:
 *    1) Veritabanı künyesi
 *    2) JWT_SECRET  — imza anahtarı
 *    3) Hız sınırı ve kapsam tanımları
 *
 *  İlk ikisi ASLA depoya yazılmaz; ikisi de config.local.php'den gelir.
 *  Aşağıdaki değerler yalnızca yerel geliştirme içindir.
 * =====================================================================
 */

declare(strict_types=1);

/* ---------------------------------------------------------------------
 *  .env DESTEĞİ
 * ---------------------------------------------------------------------
 *  Veritabanı bilgileri bu dosyanın İÇİNDE durmak zorunda değil.
 *  Depo kökündeki ".env" dosyasına yazarsanız buradaki varsayılanlar
 *  devreye girmez — ve ".env" .gitignore içinde olduğu için parolanız
 *  depoya hiç girmez.
 *
 *  NEDEN AYRI BİR DOSYA?
 *  config.php DEPODA durur ve her dağıtımda depodaki sürümle
 *  DEĞİŞTİRİLİR; içine elle yazdığınız parola bir sonraki deploy'da
 *  silinir. .env ise deploy'un dokunmadığı bir dosyadır: bir kez
 *  oluşturursunuz, kalıcıdır.
 *
 *  DEĞER ARAMA SIRASI
 *      1. config.local.php içinde define() edilmişse o kazanır
 *         (bu dosyada varsa; aşağıdaki "! defined()" kontrolleri)
 *      2. .env dosyası
 *      3. Sunucunun gerçek ortam değişkeni (Apache SetEnv, systemd…)
 *      4. Bu dosyadaki varsayılan
 *
 *  cy_env() bilerek getenv() ile AYNI şeyi döndürür (değer ya da
 *  false). Böylece aşağıdaki satırlar olduğu gibi çalışmaya devam
 *  eder; "?:" ve "!== false" kalıplarının hiçbiri değişmedi.
 * ------------------------------------------------------------------ */
if (! function_exists('cy_env')) {
    /**
     * .env dosyasından (yoksa ortamdan) bir değer okur.
     *
     * @return string|false Değer yoksa false — getenv() ile aynı sözleşme.
     */
    function cy_env(string $key): string|false
    {
        static $env = null;

        if ($env === null) {
            $env  = [];
            $file = dirname(__DIR__) . '/.env';

            if (is_file($file) && is_readable($file)) {
                /* IGNORE_NEW_LINES + SKIP_EMPTY_LINES: satır sonlarını ve
                 * boş satırları baştan eler; ayrıştırma sadeleşir. */
                $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                foreach ($lines as $line) {
                    $line = trim($line);

                    // Yorum satırı ya da "=" içermeyen satır atlanır.
                    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                        continue;
                    }

                    [$name, $value] = explode('=', $line, 2);

                    $name  = trim($name);
                    $value = trim($value);

                    /* Tırnak içindeki değerlerden tırnakları at:
                     * DB_PASS="a b c" → a b c
                     * Tırnak zorunlu değildir; yalnızca boşluk içeren
                     * parolalar için gerekir. */
                    if (strlen($value) >= 2
                        && ($value[0] === '"' || $value[0] === "'")
                        && $value[strlen($value) - 1] === $value[0]
                    ) {
                        $value = substr($value, 1, -1);
                    }

                    if ($name !== '') {
                        $env[$name] = $value;
                    }
                }
            }
        }

        // .env'de varsa o; yoksa sunucunun gerçek ortam değişkeni.
        return $env[$key] ?? getenv($key);
    }
}

/*  Doğrudan çağrılmaya karşı ilk katman. İkinci katman system/.htaccess.
 *  Bu dosya sunulursa JWT_SECRET sızar; o da tüm API'nin ele geçirilmesi
 *  demektir (imza doğrulaması sahte jetonu gerçeğinden ayırt edemez). */
if (!defined('CY_APP')) {
    http_response_code(403);
    exit;
}

/* =====================================================================
 *  YEREL AYAR DOSYASI
 * ---------------------------------------------------------------------
 *  system/config.local.php .gitignore içindedir: depoya gitmez ve
 *  deploy sırasında SİLİNMEZ. Canlı künye ve canlı JWT_SECRET oraya
 *  yazılır; buradaki define'lar yalnızca o dosya YOKSA devreye girer
 *  (define bir kez tanımlanır, ilk tanım kazanır).
 * ================================================================== */
$yerelAyar = __DIR__ . '/config.local.php';
if (is_file($yerelAyar)) {
    require_once $yerelAyar;
}

/* =====================================================================
 *  VERİTABANI
 * ================================================================== */
if (! defined('DB_HOST')) { define('DB_HOST', cy_env('DB_HOST') ?: '127.0.0.1'); }
if (! defined('DB_NAME')) { define('DB_NAME', cy_env('DB_NAME') ?: 'cy_api_jwt'); }
if (! defined('DB_USER')) { define('DB_USER', cy_env('DB_USER') ?: 'root'); }
if (! defined('DB_PASS')) { define('DB_PASS', cy_env('DB_PASS') !== false ? (string) cy_env('DB_PASS') : ''); }
if (! defined('DB_CHARSET')) { define('DB_CHARSET', 'utf8mb4'); }

/* ---------------------------------------------------------------------
 *  ZAMAN DİLİMİ
 * ---------------------------------------------------------------------
 *  ÖLÇÜLEN SORUN: php.ini'de date.timezone çoğu XAMPP kurulumunda
 *  sunucunun coğrafi diliminden farklıdır. Bu makinede PHP
 *  "Europe/Berlin", MySQL ise sistem dilimi (Europe/Istanbul)
 *  kullanıyordu; aynı anı anlatan iki satır BİR SAAT farklı görünüyordu:
 *
 *      worker günlüğü (PHP date)  : 14:03:17
 *      veritabanı  (MySQL NOW())  : 15:03:17
 *
 *  Bu depodaki zaman ARİTMETİĞİ bilinçli olarak SQL tarafında yapılır
 *  (NOW(), INTERVAL, TIMESTAMPDIFF), bu yüzden hesaplar zaten doğrudur.
 *  Kayan şey, PHP'nin ekrana/günlüğe bastığı saatti — ve demoyu
 *  deneyen biri için bu, "sistem yanlış çalışıyor" gibi görünür.
 *
 *  Çözüm: dilimi ORTAMA bırakmak yerine açıkça sabitliyoruz. Kendi
 *  sunucunuzda farklı bir dilim istiyorsanız APP_TIMEZONE ortam
 *  değişkenini tanımlamanız yeterlidir; kod değiştirmenize gerek yok.
 * ------------------------------------------------------------------ */
define('APP_TIMEZONE', cy_env('APP_TIMEZONE') ?: 'Europe/Istanbul');

// @ kullanmıyoruz: geçersiz bir dilim adı sessizce yutulmamalı.
if (in_array(APP_TIMEZONE, timezone_identifiers_list(), true)) {
    date_default_timezone_set(APP_TIMEZONE);
}

/* =====================================================================
 *  HATA AYIKLAMA — ORTAMDAN TÜRETİLİR
 * ---------------------------------------------------------------------
 *  APP_DEBUG'ı elle 'true' bırakmak, canlıya alındığında hata
 *  mesajlarının (SQL metni, dosya yolu, satır numarası) API yanıtında
 *  görünmesi demektir. Burada sunucu adına bakılır: canlı bir alan
 *  adında KENDİLİĞİNDEN kapanır.
 * ================================================================== */
function cy_is_local_host(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = (string) preg_replace('/:\d+$/', '', $host);

    if ($host === '' && PHP_SAPI === 'cli') {
        return true;
    }

    return $host === 'localhost'
        || $host === '127.0.0.1'
        || $host === '::1'
        || str_ends_with($host, '.test')
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.localhost');
}

if (! defined('APP_DEBUG')) {
    $cyDebugEnv = cy_env('APP_DEBUG');
    define('APP_DEBUG', $cyDebugEnv !== false
        ? in_array(strtolower((string) $cyDebugEnv), ['1', 'true', 'on', 'yes'], true)
        : cy_is_local_host());
}

error_reporting(APP_DEBUG ? E_ALL : 0);
ini_set('display_errors', APP_DEBUG ? '1' : '0');

/* =====================================================================
 *  JWT AYARLARI
 * ---------------------------------------------------------------------
 *  JWT_SECRET : HS256 imza anahtarı. Bu anahtarı bilen herkes İSTEDİĞİ
 *    kapsamla (scope), İSTEDİĞİ anahtar adına geçerli jeton üretebilir
 *    ve doğrulama onu gerçeğinden ayırt EDEMEZ. Parola gibi korunur:
 *    üretimde config.local.php'ye ya da ortam değişkenine yazılır,
 *    en az 32 rastgele bayt olur.
 *
 *      php -r "echo bin2hex(random_bytes(32));"
 *
 *  JWT_TTL    : Erişim jetonunun ömrü (saniye). Kısa tutulur; uzun ömür,
 *    çalınan jetonun uzun süre geçerli kalması demektir. Jetonlar
 *    sunucuda saklanmadığı için TEK TEK İPTAL EDİLEMEZLER — kısa ömür
 *    bu eksiğin karşılığıdır (bkz. README, "Kritik Kararlar").
 *  JWT_ISS/AUD: iss (kimin ürettiği) ve aud (kimin için üretildiği).
 *    Aynı sırrı paylaşan iki servis arasında jeton geçişini engeller.
 *  JWT_LEEWAY : Sunucu saatleri arası kayma toleransı (saniye).
 * ================================================================== */
if (! defined('JWT_SECRET')) {
    define('JWT_SECRET', cy_env('JWT_SECRET') ?: 'DEV-ONLY-degistir-bunu-uretimde-en-az-32-bayt-rastgele');
}
define('JWT_TTL', 900);
define('JWT_ISS', 'cy-rest-api');
define('JWT_AUD', 'cy-clients');
define('JWT_LEEWAY', 30);

/* =====================================================================
 *  HIZ SINIRI  [istek, pencere saniyesi]
 * ---------------------------------------------------------------------
 *  Ayrı kovalar, çünkü iki uç noktanın maliyeti ve riski aynı değil:
 *
 *   API   : jetonlu normal trafik. Anahtar başına sayılır.
 *   TOKEN : jeton üretimi. Her çağrı bir password_verify() yani bilerek
 *           YAVAŞ bir bcrypt hesabı çalıştırır. Ayrıca secret deneme
 *           (brute force) saldırısının hedefi tam olarak burasıdır.
 *   DEMO  : yalnızca demo jetonları için; ucuz ama sınırsız olmamalı.
 * ================================================================== */
define('RATE_LIMIT_API',   [180, 60]);
define('RATE_LIMIT_TOKEN', [12, 60]);
define('RATE_LIMIT_DEMO',  [30, 60]);

/* =====================================================================
 *  KAPSAMLAR (scope)
 * ---------------------------------------------------------------------
 *  Bir jeton "kimliği" taşır ama tek başına yetki taşımaz; yetkiyi
 *  kapsamlar taşır. Beyaz liste: veritabanındaki bir anahtara elle
 *  "notes:*" yazılsa bile tanınmayan kapsam yok sayılır.
 *
 *  Anahtar => insan okuyabilir açıklama (arayüzde gösterilir).
 * ================================================================== */
define('KNOWN_SCOPES', [
    'notes:read'   => 'Notları okuma',
    'notes:write'  => 'Not oluşturma, güncelleme, silme',
    'profile:read' => 'Anahtar künyesini okuma',
]);

/* =====================================================================
 *  DEMO JETONLARI
 * ---------------------------------------------------------------------
 *  BİLEREK BOZUK jeton üreten uç noktayı açar (süresi dolmuş, yanlış
 *  aud, kapsamsız …). Bir öğrenme aracıdır: doğrulamanın gerçekten
 *  çalıştığını göstermenin başka yolu yok — bozuk jetonu istemcide
 *  üretmek için sırra ihtiyaç var, o da istemcide olmamalı.
 *
 *  ÜRETİMDE KAPATIN. Kapalıyken /auth/demo-token 404 döner.
 * ================================================================== */
if (! defined('DEMO_TOKENS')) { define('DEMO_TOKENS', true); }

/* Not gövdesi ve liste sayfası için üst sınırlar. */
define('NOTE_TITLE_MAX', 150);
define('NOTE_BODY_MAX', 10000);
define('NOTES_PAGE_MAX', 100);

try {
    $db = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET),
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            /*  Gerçek prepared statement: sorgu metni ile veri
             *  sunucuya AYRI gider, dolayısıyla veri hiçbir koşulda
             *  SQL olarak yorumlanamaz. */
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'error' => [
            'code'    => 'db_unavailable',
            'message' => APP_DEBUG ? $e->getMessage() : 'Veritabanına bağlanılamadı.',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
