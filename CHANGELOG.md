# Değişiklik Günlüğü

Bu dosyanın biçimi [Keep a Changelog](https://keepachangelog.com/tr/1.1.0/) esas alınarak,
sürüm numaralandırması [Semantic Versioning](https://semver.org/lang/tr/) kuralına göre tutulur.

---

## [1.0.0] — 2026-08-31

İlk genel sürüm. JWT çekirdeği, uç noktalar, konsol ve belgelendirme üretime hazır durumda.

### Eklendi

**JWT çekirdeği (kütüphanesiz)**
- HS256 imzalama ve doğrulama; `hash_hmac` + `hash_equals`. İmza **sabit zamanda** kıyaslanır: `===` ilk farklı baytta çıkar ve süre farkı ağ üzerinden bile ölçülebilir; saldırgan imzayı bayt bayt bulabilir.
- **`alg` jetondan okunmaz.** Header'daki değer `HS256` değilse jeton **imza hiç hesaplanmadan** reddedilir. `alg: none` saldırısı, algoritmayı jetonun kendisinden okuyan kodlarda saldırganın kimliğini kendi kendine onaylamasıdır.
- `exp` ve `nbf` kontrolü `JWT_LEEWAY` toleransıyla — sunucu saatlerinin birbirine eşit olmasını beklemek gerçekçi değildir.
- **`exp` claim'i olmayan jeton reddedilir.** Süresiz jeton, çalındığında geri alınamayan bir anahtardır.
- `iss` / `aud` doğrulaması: aynı imza sırrını paylaşan iki servis arasında jeton geçişini engeller.
- `jti` (jeton kimliği) baştan üretilir. Bu örnekte kara liste yok; alan, sonradan iptal listesi eklemek isteyen biri dolaşımdaki jetonları kırmadan ekleyebilsin diye bulunuyor.
- `jwt_encode()` **null değerli claim'i payload'dan tümüyle siler.** `array_merge` yalnızca ezer, silmez; bir claim'i "yok" yapmanın başka yolu yoktur.
- base64url encode/decode elle yazılmış; dolgu (padding) kuralı açıkça belgelenmiş.

**Kimlik ve yetkilendirme**
- API anahtarı (`key_id` + `secret`) → kısa ömürlü JWT akışı.
- `secret` veritabanında **`password_hash()` ile** tutulur; ham hâli hiçbir yerde saklanmaz. Anahtar tablosu sızdırılan dökümlerde ilk bakılan yerdir.
- **Zamanlama saldırısına karşı:** anahtar bulunamasa bile sahte bir hash'le `password_verify()` çalıştırılır, böylece iki yol aynı süreyi harcar.
- **Anahtar sayımına (enumeration) karşı:** yanlış secret ile pasif anahtar **aynı** hatayı alır. Ayırmak, geçerli bir `key_id`'yi doğrulamak olurdu.
- Kapsam (scope) tabanlı yetkilendirme; `require_scope()` eksik kapsamda **403** döner ve `details` alanında hangi kapsamın gerektiğini, jetonun hangilerini taşıdığını yazar.
- Kapsamlar **beyaz listeden** geçer: veritabanındaki bir anahtara elle yazılmış tanınmayan kapsam yok sayılır.
- `active = 0` ile anahtarı **silmeden** kapatma.

**Uç noktalar**
- `GET /` uç listesi (discovery) — kimlik istemez. Uç adresleri sır sayılmaz; gizlilik yetkilendirmeyle sağlanır.
- `POST /auth/token` — jeton üretimi.
- `POST /auth/demo-token` — **bilerek bozuk** jetonlar (`expired`, `future`, `bad_audience`, `no_expiry`, `no_scopes`). Bir öğrenme aracıdır: bozuk ama imzası geçerli bir jeton üretmek için sır gerekir, sır da istemcide olmamalı. `DEMO_TOKENS = false` iken uç **404** döner.
- `GET /me` — anahtar künyesi ve jetonun kalan ömrü (`profile:read`).
- `GET /stats` — sayaçlar (`profile:read`). Not sayısı **yalnızca jetonun sahibi** için sayılır.
- `/notes` CRUD: sayfalama, arama (`?q=`), beyaz listeli sıralama (`?sort=`, `?dir=`).

**API tasarımı**
- Tutarlı JSON zarfı: başarıda `data` (+ gerekirse `meta`), hatada `error`. `error.code` makine içindir ve **değişmez**; `error.message` insan içindir ve değişebilir.
- Doğru durum kodları: 200 / 201 / 204 / 400 / 401 / 403 / 404 / 405 / 422 / 429 / 500.
- **`204 No Content` gövdesizdir** ve `Content-Type` göndermez — gövde yoksa türü de yoktur.
- Standart başlıklar: `Allow` (405'te), `Location` (201'de), `WWW-Authenticate` (401/403'te), `Retry-After` (429'da).
- `X-RateLimit-*` başlıkları **her** yanıta eklenir, yalnızca 429'a değil: iyi bir istemci sınıra çarpmadan yavaşlayabilsin.
- **Kayan pencereli** hız sınırı, anahtar başına. Sabit pencere (dakika başında sıfırlanan sayaç) sınırın iki katına izin verir: 59. saniyede N, 61. saniyede N daha.
- Ayrı kovalar: `token` (her çağrı bilerek yavaş bir bcrypt hesabı çalıştırır ve deneme saldırısının hedefidir), `api`, `demo`.
- **Yetkisiz kayıt için 403 değil 404.** 403, "böyle bir kayıt var ama senin değil" bilgisini verir; o bilgi başkasının kaç kaydı olduğunu saymaya yarar.
- Yol üç kaynaktan çözülür (`?path=`, `PATH_INFO`, `REQUEST_URI`) — `mod_rewrite` kapalı bir sunucuda API tümden ölmesin.
- CORS + preflight (`OPTIONS` → 204) ve `Access-Control-Expose-Headers` (yoksa tarayıcı `X-RateLimit-*` başlıklarını okutmaz).

**Konsol arayüzü**
- Marka tasarım kalıbına taşındı: gradyan başlıklı kartlar, sayaç şeridi, toast bildirimleri, `aria-live` alanları.
- **Sayaç şeridi:** jetonun kalan ömrü (saniyede bir geri sayar, son 60 saniyede uyarı rengine geçer), kapsam sayısı, not sayısı, kalan istek hakkı, aktif anahtar sayısı.
- **Dört demo anahtarı kart olarak**, her birinin altında ne olacağını önceden söyleyen bir not: tam yetki, salt okunur, yalnızca yazma, pasif.
- **Jetonun üç parçası ayrı renklerde** gösterilir; header ve payload **tarayıcıda** çözülür. Payload'ın şifreli olmadığını göstermenin en doğrudan yolu budur. İmza aynı yerde doğrulanamaz — doğrulamak için sır gerekir.
- **8 senaryo düğmesi**: normal akış (200/201), jetonsuz istek, imza kurcalama, `alg: none`, süresi dolmuş jeton, yanlış `aud`, yetkisiz yazma. İkisi tümüyle tarayıcıda üretilir.
- Her yanıtın üstünde **durum kodunu açıklayan** bir kutu. 401 ile 403'ü karıştırmak en yaygın API hatasıdır: 403 alan bir istemcinin yeni jeton alması işe yaramaz, sonsuz döngüye girer.
- **İstek geçmişi** ve detay penceresi: gönderilen başlıklar, gövde, dönen başlıklar, dönen gövde ve **curl karşılığı** — API'nin tarayıcıya bağlı olmadığını göstermenin en kısa yolu.
- Paylaşılabilir derin bağlantı: `#dene-<senaryo>` çalıştırır, `#detay-<senaryo>` çalıştırıp detayı açar.
- Jeton `localStorage`'a **yazılmaz**, yalnızca bellekte tutulur: oraya yazılan bir jetonu sayfadaki herhangi bir XSS açığı okuyup dışarı sızdırabilir.

**Veri**
- `cy_api.sql` veritabanını kendisi oluşturur, başında `SET NAMES utf8mb4` bulunur.
- **4 anahtar + 19 not.** Anahtarlar dört farklı yetki durumunu temsil eder; `demo_writer` özellikle öğreticidir: yazabilir ama **okuyamaz** — "yazabiliyorsa okuyabilir" diye bir kural yoktur.
- Zamanlar `NOW() - INTERVAL` ile üretilir; dosya ne zaman içe aktarılırsa aktarılsın kayıtlar "son birkaç gün" içinde görünür.
- `idx_notes_owner_id (owner_key, id)`: liste sorgusu hem `owner_key` ile filtreler hem `id` ile sıralar; tek indeks ikisini birden karşılar.
- `AUTO_INCREMENT` ileri alındı — demoda not silinir, numaralar boşalır; yeni bir kayıt silinmiş bir numarayı devralmamalı.

**Mobil ve erişilebilirlik**
- Dar ekranda **yatay kaydırma yok** (360px'te ölçüldü: `scrollWidth == clientWidth`, taşan eleman yok). İkincil sütunlar gizlenir, bilgi detay penceresinde korunur.
- Durum kodu ve HTTP metot rozetleri renk **ve** metin taşır — renk tek başına anlam taşımaz.
- Geçmiş satırları `tabindex="0"` taşır ve klavyeyle açılabilir; dokunma hedefleri en az 32–44px.

**Altyapı**
- `system/config.local.php` desteği. Bu projede iki sır birden korunur: veritabanı künyesi **ve** `JWT_SECRET`.
- `APP_DEBUG` sunucu adından türetilir; canlı bir alan adında kendiliğinden kapanır.
- `JSON_INVALID_UTF8_SUBSTITUTE`: hata metinleri ya da not gövdesi dış bir kaynaktan gelen bozuk bir bayt içerebilir; o bayrak olmadan `json_encode()` sessizce `false` döner ve istemci **boş** gövde alır.
- Bozuk JSON gövdesi artık sessizce boş dizi sayılmıyor; `400 invalid_json` döner. Eskisi "title zorunlu" gibi yanıltıcı bir doğrulama hatası üretirdi.

**Belgelendirme**
- Türkçe ve İngilizce README (canlı demo bölümü, 60 saniyelik deneme tablosu, beş kritik karar, güvenlik tablosu, API referansı, şema kararları, SSS, üretim kontrol listesi, sorun giderme).
- Ekran görüntüleri: API konsolu, istek detayı (403), mobil görünüm.

### Düzeltildi

- **403 yanıtları tel üzerinde 401 olarak çıkıyordu.** PHP'nin `header()` fonksiyonu bazı başlıkları görüp durum kodunu kendiliğinden değiştirir: `Location:` 302 yapar, **`WWW-Authenticate:` ise 401 yapar**. `insufficient_scope` yanıtları bu başlığı gönderdiği için gövde "403" derken HTTP durumu 401 oluyordu — istemci "jetonum geçersiz, yenisini alayım" diye sonsuz döngüye girerdi. `http_response_code()` artık başlıklardan **sonra** çağrılıyor.
- **`SQLSTATE[HY093] Invalid parameter number`** — arama sorgusunda `:q` yer tutucusu iki kez kullanılıyordu (`title LIKE :q OR body LIKE :q`). `EMULATE_PREPARES = false` iken sorgu MySQL'e gerçek prepared statement olarak gider ve adlar konumsal `?`'lere çevrilir; aynı ad ikinci kez geçemez. Emülasyon **açıkken çalışıp kapalıyken patlayan**, bu yüzden gözden kaçması kolay bir hatadır. `:q1` / `:q2` olarak ayrıldı.
- **`no_expiry` demo jetonu geçerli sayılıyordu.** `exp => null` göndermek yetmiyordu: `array_merge` claim'i null'a çekiyor, ama `jwt_encode` varsayılan `exp`i zaten eklemiş oluyordu. Null claim'ler artık payload'dan tümüyle siliniyor.
- **`204 No Content` gövdesinde "null" dizesi vardı.** 204 gövde taşıyamaz; kimi istemci kütüphanesi bunu protokol hatası sayar.
- **`api/.htaccess` içindeki `RewriteBase` sabit bir yol yazıyordu.** Proje başka bir klasöre kopyalandığı anda bütün uçlar 404 dönüyor ve sebebi görünmüyordu. Satır kaldırıldı; Apache tabanı dizinden kendisi türetiyor.
- **Kök `.htaccess` bütün `.md` dosyalarını kapatıyordu**, README dahil. Kütüphane vitrini README'yi içerik olarak okuduğu için örneğin anlatımı boş görünürdü. `README*.md` için istisna eklendi; `CHANGELOG.md` kapalı kaldı.
- **Koleksiyona `PUT`/`DELETE` atmak 404 dönüyordu**; artık `405` + `Allow: GET, POST`. 404 istemciyi yanlış yere bakmaya yollar.
- `PUT` gövdesi form biçiminde gönderildiğinde okunamıyordu: PHP `$_POST`'u yalnızca POST + form içerikte doldurur, `PUT` gövdesini hiç ayrıştırmaz. Ham gövde artık elle okunuyor.
- İstek detayında `Authorization` etiketi **`AUTHORİZATİON`** görünüyordu: sayfa `lang="tr"` ve CSS'teki `text-transform: uppercase` Türkçe kuralına göre "i" harfini "İ" yapıyor. Bir HTTP başlığının adı çevrilmez.
- Yanıt kartının durum rozeti, kartın **gradyan başlığında** okunmuyordu (yeşil metin koyu maviye karışıyordu). Başlıkta zemin opak beyaza çekildi.
- `JWT_TTL` 3600'den **900**'e indirildi. Jetonlar tek tek iptal edilemediği için kısa ömür tek savunmadır.

### Güvenlik

- **JWT payload'ı şifreli değildir**, yalnızca base64url ile kodlanmıştır. İmza gizliliği değil **bütünlüğü** sağlar. Jetona parola, e-posta, TCKN gibi veri konmaz.
- **SQL Injection:** tüm sorgular prepared statement, `EMULATE_PREPARES = false`; `ORDER BY` sütunu beyaz listeden geçer; `LIKE` jokerleri (`%`, `_`) kaçışlanır.
- **Sahiplik koşulu SQL'dedir:** `owner_key` her sorgunun `WHERE`'inde bulunur. PHP'de kontrol etmek, beş uçtan birinde unutulması demektir — ve o bir uç bütün verinin sızması için yeter.
- **XSS:** not başlığı, gövde ve hata metni kullanıcı verisidir; sunucuda `e()`, istemcide `esc()`. Yanıt gövdesi asla `.html()` ile basılmaz.
- **CSRF bu tasarımda konu değildir:** API çerez kullanmaz, kimlik `Authorization` başlığıyla taşınır. Saldırganın sayfası isteği atabilir ama jetonu ekleyemez.
- **CORS** `*` ile açıktır ve bu bir demo için bilinçlidir; README'de kendi projenizde nasıl sınırlanacağı yazılıdır.
- `system/` klasörü **tümüyle kapalıdır**: içinde `JWT_SECRET` durur ve o sır sızarsa saldırgan istediği kapsamla istediği anahtar adına **geçerli** jeton üretebilir; imza doğrulaması onu ayırt edemez. İkinci katman dosyaların içindeki `CY_APP` kontrolüdür — `.htaccess`'in okunmadığı bir sunucuda (nginx) o çalışır.
- `.htaccess`: dizin listeleme kapalı; `.sql`, `.md`, `.json`, `.log`, `.ini`, `.bak`, `.example` kapalı — `README*.md` bilinçli istisnadır. `DirectoryIndex index.php` eklendi.

[1.0.0]: https://github.com/CilginYazilim/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri/releases/tag/v1.0.0
