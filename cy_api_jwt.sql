-- =====================================================================
--  cilginyazilim.com – Sıfır Bağımlılık JWT REST API
--    mysql -u root -p < cy_api_jwt.sql
-- =====================================================================

-- ---------------------------------------------------------------------
--  ÖNEMLİ: İSTEMCİ KARAKTER SETİ
-- ---------------------------------------------------------------------
--  Bu satır olmadan `mysql -u root -p < dosya.sql` komutu, İSTEMCİNİN
--  varsayılan karakter setini kullanır. Windows'ta bu genellikle latin1
--  ya da cp1254'tür; dosyadaki UTF-8 baytları latin1 sanılıp yeniden
--  kodlanır ve veri ÇİFT KODLANMIŞ (mojibake) olarak girer:
--
--      "savunması"  →  "savunmasÄ±"
--
--  Hata sessizdir: kurulum başarıyla biter, tablolar oluşur, hiçbir
--  uyarı çıkmaz. Sorun ancak ekranda bozuk harfler görününce fark
--  edilir.
--
--  SET NAMES, istemciye "gönderdiğim baytlar utf8mb4" der ve dosyanın
--  hangi istemciyle içe aktarıldığından bağımsız olarak doğru sonucu
--  garanti eder.
-- ---------------------------------------------------------------------
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `cy_api_jwt`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cy_api_jwt`;

DROP TABLE IF EXISTS `api_notes`;
DROP TABLE IF EXISTS `api_keys`;

-- ---------------------------------------------------------------------
--  api_keys — API anahtarları
-- ---------------------------------------------------------------------
--  key_id       : herkese açık tanımlayıcı. URL'de, günlükte, hata
--                 mesajında görünebilir; sır DEĞİLDİR.
--  secret_hash  : password_hash(secret). Ham secret SAKLANMAZ.
--
--  NEDEN HASH? Anahtar tablosu, sızdırılan veritabanı dökümlerinde
--  ilk bakılan yerdir. Ham secret dursaydı, dökümü ele geçiren
--  herkes API'ye tam yetkiyle girerdi. Hash'ten secret geri
--  üretilemez; doğrulama password_verify() ile yapılır.
--
--  Bu, kullanıcı parolasıyla AYNI muameledir — çünkü API secret'ı da
--  bir paroladır, yalnızca kullanıcısı insan değil, bir programdır.
--
--  scopes       : boşlukla ayrılmış izinler ("notes:read notes:write").
--                 Ayrı bir tablo yerine tek sütun: bu örnekte kapsam
--                 sayısı sabit ve az; üçüncü tablo öğretici değil,
--                 yalnızca gürültü olurdu. Kapsamlar dinamik hâle
--                 gelirse (kullanıcı kendi kapsamını seçiyorsa)
--                 api_key_scopes tablosu doğru cevaptır.
--  active       : 0 ise jeton hiç üretilmez. Anahtarı SİLMEK yerine
--                 pasifleştirmek, hangi anahtarın ne zaman kapatıldığını
--                 korur ve yanlışlıkla silmeyi geri alınabilir kılar.
--  last_used_at : jeton üretilen son an. "Bu anahtar hâlâ kullanılıyor
--                 mu?" sorusunun cevabı — kullanılmayan anahtar
--                 kapatılabilir.
-- ---------------------------------------------------------------------
CREATE TABLE `api_keys` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(120) NOT NULL,
  `key_id`       VARCHAR(64)  NOT NULL,
  `secret_hash`  VARCHAR(255) NOT NULL,
  `scopes`       VARCHAR(255) NOT NULL DEFAULT '',
  `active`       TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_used_at` DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  -- Jeton üretimi key_id ile TEK satır bulmak zorundadır. Benzersizlik
  -- kısıtı olmadan, aynı key_id'den ikinci bir satır eklenirse hangi
  -- secret'ın geçerli olduğu sıralamaya kalırdı.
  UNIQUE KEY `uq_api_keys_key_id` (`key_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  api_notes — örnek kaynak
-- ---------------------------------------------------------------------
--  Her anahtar YALNIZCA kendi notlarını görür. owner_key her sorgunun
--  WHERE'inde bulunur; PHP tarafında "sahibi mi?" diye kontrol etmek
--  yerine koşulu SQL'e gömmek, bir yerde unutmayı imkânsız kılar.
--
--  owner_key neden api_keys.id değil de key_id?  Jetonun 'sub' claim'i
--  key_id taşır. id kullansaydık her istekte anahtar tablosuna
--  gidip id'yi bulmak gerekirdi — jetonun varlık sebebi tam olarak
--  bu turu ortadan kaldırmaktır.
-- ---------------------------------------------------------------------
CREATE TABLE `api_notes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_key`  VARCHAR(64) NOT NULL,
  `title`      VARCHAR(150) NOT NULL,
  `body`       TEXT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  -- Liste sorgusu HER ZAMAN owner_key ile filtreler; indeks o sorgunun
  -- indeksidir. id DESC sıralaması için ikinci sütun olarak id eklendi:
  -- MySQL o zaman hem filtreyi hem sıralamayı tek indeksten karşılar.
  KEY `idx_notes_owner_id` (`owner_key`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
--  ÖRNEK ANAHTARLAR
-- ---------------------------------------------------------------------
--  Dördü BİLEREK farklı durumdadır; demo bu dört anahtarla API'nin
--  bütün cevaplarını gösterir:
--
--   key_id          secret                 durum
--   -------------   --------------------   ------------------------------
--   demo_full       demo-secret-123        tam yetki (read+write+profile)
--   demo_readonly   readonly-secret-456    salt okunur → POST/PUT/DELETE 403
--   demo_writer     writer-secret-789      YALNIZCA yazma → GET /notes 403
--                                          ve GET /me 403 (profile:read yok)
--   demo_pasif      pasif-secret-000       active=0 → secret doğru olsa
--                                          bile jeton ÜRETİLMEZ (401)
--
--  demo_writer özellikle öğreticidir: "yazabiliyorsa okuyabilir" diye
--  bir kural YOKTUR. Kapsamlar birbirini kapsamaz.
--
--  secret_hash'ler:  php -r "echo password_hash('...', PASSWORD_DEFAULT);"
--  Bu secret'lar herkese açıktır ve olması gerektiği gibidir: burası bir
--  demo. Kendi kurulumunuzda MUTLAKA yenilerini üretin.
-- =====================================================================
INSERT INTO `api_keys` (`name`, `key_id`, `secret_hash`, `scopes`, `active`, `created_at`, `last_used_at`) VALUES
('Mobil uygulama (tam yetki)', 'demo_full',
 '$2y$10$lmN28UHIyWEcgWNXW3eVvuRnGjSHuXnt29hXfN3dTBII5Nm/uMBVW',
 'notes:read notes:write profile:read', 1,
 NOW() - INTERVAL 96 DAY, NOW() - INTERVAL 7 MINUTE),

('Rapor paneli (salt okunur)', 'demo_readonly',
 '$2y$10$Zfx2TineuaPz9ta8/dVt9OM0IJ5R2Ol40oxvjE2K7HVbmaFcRi.fa',
 'notes:read profile:read', 1,
 NOW() - INTERVAL 61 DAY, NOW() - INTERVAL 3 HOUR),

('Form toplayıcı (yalnızca yazma)', 'demo_writer',
 '$2y$10$8Fz83U9mENdXic16xvRp7e9lecIHD4/LZj03K8B/0iuTkQT3kcz62',
 'notes:write', 1,
 NOW() - INTERVAL 34 DAY, NOW() - INTERVAL 2 DAY),

('Eski entegrasyon (pasif)', 'demo_pasif',
 '$2y$10$BuVz3QFst3j840yUEBP8ie/qMLWEtsJxqAXywaoBlP4H441L3DNU.',
 'notes:read notes:write profile:read', 0,
 NOW() - INTERVAL 210 DAY, NOW() - INTERVAL 45 DAY);

-- =====================================================================
--  ÖRNEK NOTLAR
-- ---------------------------------------------------------------------
--  Zamanlar NOW() - INTERVAL ile üretilir: dosya ne zaman içe
--  aktarılırsa aktarılsın kayıtlar "son birkaç gün" içinde görünür.
--  Sabit tarih yazılsaydı demo birkaç ay sonra "2026'dan beri hiç
--  dokunulmamış" gibi görünürdü.
--
--  updated_at bazı kayıtlarda created_at'ten SONRADIR: liste
--  "güncellenme" sırasına göre sıralandığında sıra gerçekten değişsin.
-- =====================================================================
INSERT INTO `api_notes` (`owner_key`, `title`, `body`, `created_at`, `updated_at`) VALUES

-- --- demo_full (tam yetkili anahtarın notları) ------------------------
('demo_full', 'JWT nedir, ne değildir?',
 'JWT bir OTURUM değil, imzalı bir VERİ PAKETİDİR. Payload şifreli değildir; base64url ile kodlanmıştır ve jetonu ele geçiren herkes okuyabilir. İmza gizliliği değil bütünlüğü sağlar: "bu içerik değiştirilmedi" der. Bu yüzden jetona parola, e-posta, TCKN gibi veri konmaz.',
 NOW() - INTERVAL 12 DAY, NOW() - INTERVAL 2 DAY),

('demo_full', 'Kimlik doğrulama ile yetkilendirme aynı şey değil',
 'Kimlik doğrulama (authentication) "sen kimsin?" sorusudur; jeton bunu cevaplar. Yetkilendirme (authorization) "bunu yapabilir misin?" sorusudur; kapsamlar bunu cevaplar. Geçerli bir jetonla gelen bir istek 403 alabilir: kim olduğu bellidir, yetkisi yoktur.',
 NOW() - INTERVAL 11 DAY, NOW() - INTERVAL 11 DAY),

('demo_full', 'alg:none saldırısı',
 'Klasik hata, imza algoritmasını JETONUN KENDİSİNDEN okumaktır. Saldırgan header''daki alg değerini "none" yapar, imza bölümünü siler ve içeriği istediği gibi düzenler. Kütüphane "algoritma none, demek ki imza doğrulanmayacak" derse jeton kabul edilir. Bu projede alg HS256 değilse jeton daha imza hesaplanmadan reddedilir.',
 NOW() - INTERVAL 10 DAY, NOW() - INTERVAL 4 DAY),

('demo_full', 'Neden hash_equals?',
 'PHP''nin == ve === operatörleri dizeleri bayt bayt kıyaslar ve ilk farklı baytta ÇIKAR. Kıyas süresi, kaç baytın tuttuğuna bağlı olarak değişir. Saldırgan bu farkı ölçerek imzayı bayt bayt bulabilir. hash_equals uzunluktan bağımsız olarak hep aynı süreyi harcar.',
 NOW() - INTERVAL 9 DAY, NOW() - INTERVAL 9 DAY),

('demo_full', 'Jeton neden 15 dakika yaşıyor?',
 'JWT sunucuda saklanmaz; doğrulama yalnızca imzaya bakar. Bu, tek tek iptal edilemeyeceği anlamına gelir — "çıkış yap" düğmesi jetonu geçersiz kılamaz. Kısa ömür bu eksiğin karşılığıdır: çalınan jeton en fazla 15 dakika işe yarar. Uzun oturum gerekiyorsa refresh token deseni kullanılır.',
 NOW() - INTERVAL 8 DAY, NOW() - INTERVAL 1 DAY),

('demo_full', 'iss ve aud ne işe yarar?',
 'Aynı imza sırrını paylaşan iki servisiniz varsa, birinin ürettiği jeton diğerinde de doğrulanır. iss (kim üretti) ve aud (kimin için üretildi) kontrolü bunu engeller. Bu örnekte demo jetonu "bad_audience" ile tam olarak bu durumu gösteriyor.',
 NOW() - INTERVAL 7 DAY, NOW() - INTERVAL 7 DAY),

('demo_full', 'Hız sınırı: kayan pencere mi, sabit pencere mi?',
 'Sabit pencere (dakikanın başında sıfırlanan sayaç) sınırın iki katına izin verir: 59. saniyede 120 istek, 61. saniyede 120 istek daha. Kayan pencere son N saniyedeki istek zamanlarını tutar ve bu boşluğu bırakmaz. Karşılığı biraz daha fazla yer kullanmaktır.',
 NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 5 HOUR),

('demo_full', '404 mü 403 mü?',
 'Başkasının kaydına eriştiğinizde bu API 403 değil 404 döner. 403, "böyle bir kayıt var ama senin değil" bilgisini verir; bu bilgi başkasının kaç kaydı olduğunu saymaya yarar. Yokmuş gibi davranmak daha az şey söyler.',
 NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY),

('demo_full', 'Alışveriş listesi',
 'Süt, ekmek, kahve. Bu not bilerek sıradan: API''nin taşıdığı veri her zaman ders niteliğinde olmak zorunda değil, arama kutusunu denemek için de bir şey lazım.',
 NOW() - INTERVAL 4 DAY, NOW() - INTERVAL 4 DAY),

('demo_full', 'PUT ile PATCH farkı',
 'Kitaba göre PUT kaynağın TAMAMINI değiştirir, PATCH bir bölümünü. Bu API''de PUT kısmi güncellemeye izin verir: gönderilen alanlar güncellenir, gönderilmeyenler korunur. Bu bilinçli bir sadeleştirmedir ve README''de açıkça yazılıdır — sessizce yapılan sapma, doküman ile davranışın ayrılması demektir.',
 NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 3 DAY),

('demo_full', 'Authorization başlığı sunucuya ulaşmıyor',
 'Bazı Apache/CGI kurulumlarında Authorization başlığı PHP''ye hiç ulaşmaz; her istek 401 döner ve sebebi kodda görünmez. Çözüm iki katmanlı: .htaccess içinde SetEnvIf ile başlığı taşımak, PHP tarafında da üç ayrı sunucu değişkenini denemek.',
 NOW() - INTERVAL 2 DAY, NOW() - INTERVAL 20 HOUR),

('demo_full', 'Konsol jetonu neden localStorage''a yazmıyor?',
 'localStorage''a yazılan jetonu sayfadaki herhangi bir XSS açığı okuyabilir ve dışarı sızdırabilir. Bu konsolda jeton sıradan bir JavaScript değişkeninde durur; sayfa yenilenince kaybolur. Bu bir eksiklik değil, bilinçli bir ödünleşim.',
 NOW() - INTERVAL 1 DAY, NOW() - INTERVAL 6 HOUR),

('demo_full', 'Yarın: hata kodları tablosunu gözden geçir',
 'error.code makine içindir ve DEĞİŞMEZ; error.message insan içindir ve değişebilir. İstemci koşullarını message metnine bağlamamalı — çeviri ya da düzeltme yapıldığında istemci kırılır.',
 NOW() - INTERVAL 9 HOUR, NOW() - INTERVAL 40 MINUTE),

-- --- demo_readonly (rapor paneli) -------------------------------------
('demo_readonly', 'Bu anahtar yazamaz',
 'demo_readonly yalnızca notes:read ve profile:read taşır. Bu notu GÖREBİLİR ama değiştiremez; POST /notes çağrısı 403 insufficient_scope döner. Yanıttaki details alanı hangi kapsamın gerektiğini ve jetonun hangi kapsamları taşıdığını yazar.',
 NOW() - INTERVAL 22 DAY, NOW() - INTERVAL 22 DAY),

('demo_readonly', 'Haftalık rapor taslağı',
 'Toplam istek 41.208, ortalama yanıt 63 ms, 429 sayısı 17. En çok çağrılan uç: GET /notes.',
 NOW() - INTERVAL 6 DAY, NOW() - INTERVAL 6 DAY),

('demo_readonly', 'Salt okunur anahtar nerede işe yarar?',
 'Panolar, raporlama araçları, durum sayfaları… Yazma yetkisi olmayan bir anahtar sızdığında verdiği zarar okumakla sınırlıdır. En küçük yetki ilkesi (least privilege) tam olarak budur.',
 NOW() - INTERVAL 3 DAY, NOW() - INTERVAL 2 DAY),

('demo_readonly', 'Notlar anahtarlar arasında paylaşılmaz',
 'Bu notu demo_full anahtarıyla ARAYAMAZSINIZ; listede hiç görünmez, doğrudan id ile istendiğinde de 404 döner. Sahiplik koşulu her sorgunun WHERE''indedir.',
 NOW() - INTERVAL 30 HOUR, NOW() - INTERVAL 30 HOUR),

-- --- demo_writer (form toplayıcı: yazar ama okuyamaz) -----------------
('demo_writer', 'Form gönderimi #1187',
 'Bu notu demo_writer oluşturdu. Ama o anahtarla GET /notes çağrısı 403 döner: notes:write taşıyor, notes:read taşımıyor. Yazabilen her istemcinin okuyabilmesi gerekmez — bir form toplayıcının topladığı veriyi geri okumaya ihtiyacı yoktur.',
 NOW() - INTERVAL 5 DAY, NOW() - INTERVAL 5 DAY),

('demo_writer', 'Form gönderimi #1188',
 'İkinci kayıt. Bu iki notu ekranda görmek için demo_full ya da demo_readonly ile giriş yapmanız YETMEZ — onlar da bu notların sahibi değil. Notlar yalnızca demo_writer''ındır ve o da okuyamaz. Kapsam tasarımının somut sonucu budur.',
 NOW() - INTERVAL 44 HOUR, NOW() - INTERVAL 44 HOUR);

-- ---------------------------------------------------------------------
--  AUTO_INCREMENT'i ileri al.
-- ---------------------------------------------------------------------
--  Demoda notlar silinir (DELETE /notes/{id} bir uçtur ve denenmesi
--  beklenir). Numaralar sürekli boşalır. Sayaç ileri alınmazsa yeni
--  bir not, silinmiş bir notun numarasını devralabilir; o numaraya
--  işaret eden eski bir bağlantı ya da önbellek yanlış kayda gider.
-- ---------------------------------------------------------------------
ALTER TABLE `api_notes` AUTO_INCREMENT = 137;
