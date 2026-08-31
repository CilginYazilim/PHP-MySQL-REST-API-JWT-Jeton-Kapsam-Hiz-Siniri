<?php
/**
 * =====================================================================
 *  ARAYÜZ – API KONSOLU
 *  cilginyazilim.com – Sıfır Bağımlılık JWT REST API
 * ---------------------------------------------------------------------
 *  BU SAYFA API'NİN PARÇASI DEĞİLDİR.
 *
 *  API'nin tek giriş kapısı api/index.php'dir ve o kapı bu sayfayı
 *  hiç tanımaz: kimlik Authorization başlığıyla gelir, oturum yoktur,
 *  çerez yoktur. Buradaki her şey tarayıcıdan fetch() ile atılan
 *  sıradan HTTP istekleridir — aynısını curl ile de yapabilirsiniz
 *  (README'de her uç için curl karşılığı vardır).
 *
 *  Kendi projenize alırken index.php ve assets/js/console.js
 *  silinebilir; API onlarsız da tam çalışır.
 *
 *  EKRAN DÜZENİ — sırası bilinçlidir, API'yi kullanma sırasıdır:
 *    1) SAYAÇ ŞERİDİ  → jetonun ve hesabın o anki hâli
 *    2) ANAHTAR       → dört demo anahtarı, dört farklı yetki durumu
 *    3) JETON         → alınan JWT'nin üç parçası, çözülmüş hâliyle
 *    4) İSTEK         → uç noktayı çağır
 *    5) YANIT         → durum kodu, süre, başlıklar, gövde
 *    6) SENARYOLAR    → doğrulamanın çalıştığını gösteren bozuk istekler
 *    7) GEÇMİŞ        → atılan her istek, tıklanabilir
 *
 *  Bu dosya veritabanına DOKUNMAZ; yalnızca yapılandırma sabitlerini
 *  okur (kapsam listesi, jeton ömrü) ve onları istemciye taşır.
 * =====================================================================
 */

declare(strict_types=1);

define('CY_APP', true);
require __DIR__ . '/system/config.php';
require __DIR__ . '/system/function.php';

$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

/*  Demo anahtarları. Secret'lar burada AÇIK duruyor ve olması gereken
 *  bu: bunlar herkese açık bir demonun anahtarlarıdır, cy_api.sql
 *  içinde de aynen yazılıdır. Kendi kurulumunuzda yenilerini üretin. */
$demoKeys = [
    ['id' => 'demo_full',     'secret' => 'demo-secret-123',     'ad' => 'Tam yetki',    'not' => 'okur + yazar + künye'],
    ['id' => 'demo_readonly', 'secret' => 'readonly-secret-456', 'ad' => 'Salt okunur',  'not' => 'yazma denemesi 403 döner'],
    ['id' => 'demo_writer',   'secret' => 'writer-secret-789',   'ad' => 'Yalnızca yazma', 'not' => 'okuma denemesi 403 döner'],
    ['id' => 'demo_pasif',    'secret' => 'pasif-secret-000',    'ad' => 'Pasif anahtar', 'not' => 'secret doğru, jeton yok'],
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="author" content="Çılgın Yazılım - cilginyazilim.com">
    <meta name="description" content="PHP ile sıfır bağımlılık JWT REST API: HS256 imza, API anahtarı, kapsam (scope) tabanlı yetkilendirme, anahtar başına hız sınırı ve tutarlı JSON hata biçimi.">
    <meta name="theme-color" content="#0b5cb5">

    <title>JWT ile REST API | Çılgın Yazılım</title>

    <link rel="icon" type="image/png" href="assets/images/logo.png">

    <!--
        CSS YÜKLEME SIRASI ÖNEMLİDİR:
          1) bootstrap      → temel çatı
          2) cilginyazilim  → MARKA TASARIM KALIBI (Bootstrap'i ezer)
          3) style          → yalnızca bu sayfaya özel eklemeler
    -->
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/cilginyazilim.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body class="cy-app">

<!-- Sayfanın en üstündeki ince marka şeridi -->
<div class="cy-topbar"></div>

<div class="container py-4 py-lg-5">

    <div class="cy-card mb-4">

        <div class="cy-card__header">
            <div class="cy-header-top d-flex flex-wrap justify-content-between align-items-center gap-3">

                <a class="cy-brand" href="https://cilginyazilim.com" target="_blank" rel="noopener">
                    <span class="cy-brand__mark">
                        <img src="assets/images/logo.png" alt="Çılgın Yazılım logosu">
                    </span>
                    <div>
                        <h1 class="cy-brand__title">JWT ile REST API</h1>
                        <p class="cy-brand__subtitle">
                            HS256 &middot; Kütüphanesiz &middot; Kapsam tabanlı yetki &middot; Anahtar başına hız sınırı
                        </p>
                    </div>
                </a>

                <div class="cy-header-controls d-flex align-items-center gap-2 flex-wrap">
                    <span class="cy-badge cy-badge--glass" id="token-badge">jeton yok</span>

                    <a class="btn cy-btn cy-btn--glass"
                       href="https://github.com/CilginYazilim/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri"
                       target="_blank" rel="noopener" title="Projeyi GitHub'da aç">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" style="vertical-align:-2px">
                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8Z"/>
                        </svg>
                        <span class="cy-header-controls__label">GitHub</span>
                    </a>

                    <!-- id="add_button" — marka CSS'inin mobil kuralları bu
                         kimliği hedefler (dar ekranda tam genişliğe geçer).

                         Demonun giriş kapısı: seçili anahtarla jeton alır.
                         Jeton olmadan aşağıdaki hiçbir uç çalışmaz. -->
                    <button type="button" id="add_button" class="btn cy-btn cy-btn--onbrand">
                        <span aria-hidden="true">🔑</span> Jeton al
                    </button>
                </div>
            </div>
        </div>

        <div class="cy-card__body">

            <!-- ---------------------------------------------------------
                 1) SAYAÇ ŞERİDİ
                 ---------------------------------------------------------
                 "Kalan süre" ve "kalan istek" canlı sayaçlardır; ikisi de
                 API'nin gerçekten döndürdüğü değerlerden beslenir
                 (exp claim'i ve X-RateLimit-Remaining başlığı).
                 --------------------------------------------------------- -->
            <div class="cy-stats" id="cy-stats">
                <div class="cy-stat cy-stat--token">
                    <span class="cy-stat__value" id="stat-ttl">—</span>
                    <span class="cy-stat__label">Jeton kalan süre</span>
                </div>
                <div class="cy-stat cy-stat--scope">
                    <span class="cy-stat__value" id="stat-scopes">0</span>
                    <span class="cy-stat__label">Kapsam</span>
                </div>
                <div class="cy-stat cy-stat--notes">
                    <span class="cy-stat__value" id="stat-notes">—</span>
                    <span class="cy-stat__label">Notlarım</span>
                </div>
                <div class="cy-stat cy-stat--rate">
                    <span class="cy-stat__value" id="stat-rate">—</span>
                    <span class="cy-stat__label">Kalan istek</span>
                </div>
                <div class="cy-stat cy-stat--keys">
                    <span class="cy-stat__value" id="stat-keys">—</span>
                    <span class="cy-stat__label">Aktif anahtar</span>
                </div>
            </div>

            <!-- ---------------------------------------------------------
                 2) ANAHTAR SEÇİMİ
                 --------------------------------------------------------- -->
            <section class="cy-panel">
                <h2 class="cy-panel__title">1 &middot; Anahtar</h2>

                <div class="cy-keys" role="group" aria-label="Demo API anahtarları">
                    <?php foreach ($demoKeys as $i => $k): ?>
                        <button type="button"
                                class="cy-key js-key<?= $i === 0 ? ' is-active' : '' ?>"
                                data-key="<?= e($k['id']) ?>"
                                data-secret="<?= e($k['secret']) ?>">
                            <span class="cy-key__name"><?= e($k['ad']) ?></span>
                            <code class="cy-key__id"><?= e($k['id']) ?></code>
                            <span class="cy-key__note"><?= e($k['not']) ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="row g-2 align-items-end mt-2">
                    <div class="col-sm-4">
                        <label class="form-label" for="key_id">key_id</label>
                        <input id="key_id" class="form-control" value="demo_full" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label" for="secret">secret</label>
                        <input id="secret" class="form-control" value="demo-secret-123" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="col-sm-3 d-grid">
                        <button type="button" id="btn-token" class="btn cy-btn cy-btn--primary">Jeton al</button>
                    </div>
                </div>

                <p class="cy-hint mt-2 mb-0">
                    Secret veritabanında <b>hash'li</b> tutulur (<code>password_hash</code>); ham hâli hiçbir
                    yerde saklanmaz. Yanlış secret ile pasif anahtar <b>aynı</b> hatayı alır — hangisinin
                    doğru olduğunu söylemek, geçerli bir <code>key_id</code>'yi doğrulamak olurdu.
                </p>
            </section>

            <!-- ---------------------------------------------------------
                 3) JETON
                 ---------------------------------------------------------
                 Jetonun üç parçası ayrı renklerle gösterilir. Payload
                 tarayıcıda çözülür — ŞİFRELİ OLMADIĞINI göstermenin en
                 doğrudan yolu budur. İmza aynı yerde doğrulanamaz:
                 doğrulamak için sır gerekir, sır da istemcide olmamalı.
                 --------------------------------------------------------- -->
            <section class="cy-panel d-none" id="panel-token">
                <h2 class="cy-panel__title">2 &middot; Jeton</h2>

                <pre class="cy-jwt" id="jwt-raw" aria-label="Ham JWT"></pre>

                <div class="row g-3 mt-1">
                    <div class="col-md-5">
                        <h3 class="cy-section-title">Header <span class="cy-dot cy-dot--h"></span></h3>
                        <pre class="cy-log cy-log--payload mb-0" id="jwt-header"></pre>
                    </div>
                    <div class="col-md-7">
                        <h3 class="cy-section-title">Payload <span class="cy-dot cy-dot--p"></span></h3>
                        <pre class="cy-log cy-log--payload mb-0" id="jwt-payload"></pre>
                    </div>
                </div>

                <p class="cy-hint mt-2 mb-0">
                    Bu çözümü <b>tarayıcı</b> yaptı; sunucuya hiç sorulmadı. Payload şifreli değildir,
                    yalnızca base64url ile <b>kodlanmıştır</b>. İmza gizliliği değil <b>bütünlüğü</b>
                    sağlar: "bu içerik değiştirilmedi" der. Bu yüzden jetona parola, e-posta, TCKN
                    gibi veri konmaz.
                </p>
            </section>

            <!-- ---------------------------------------------------------
                 4) İSTEK
                 --------------------------------------------------------- -->
            <section class="cy-panel">
                <h2 class="cy-panel__title">3 &middot; İstek</h2>

                <div class="row g-2 align-items-end">
                    <div class="col-4 col-sm-3 col-lg-2">
                        <label class="form-label" for="method">Metot</label>
                        <select id="method" class="form-select">
                            <option>GET</option><option>POST</option><option>PUT</option><option>DELETE</option>
                        </select>
                    </div>
                    <div class="col-8 col-sm-6 col-lg-7">
                        <label class="form-label" for="path">Yol</label>
                        <input id="path" class="form-control" value="/notes" autocomplete="off" spellcheck="false">
                    </div>
                    <div class="col-sm-3 d-grid">
                        <button type="button" id="btn-send" class="btn cy-btn cy-btn--primary">Gönder</button>
                    </div>
                </div>

                <div class="cy-chips mt-2">
                    <button type="button" class="cy-chip js-preset" data-m="GET"    data-p="/">GET /</button>
                    <button type="button" class="cy-chip js-preset" data-m="GET"    data-p="/me">GET /me</button>
                    <button type="button" class="cy-chip js-preset" data-m="GET"    data-p="/stats">GET /stats</button>
                    <button type="button" class="cy-chip js-preset" data-m="GET"    data-p="/notes">GET /notes</button>
                    <button type="button" class="cy-chip js-preset" data-m="GET"    data-p="/notes?q=jeton">GET /notes?q=</button>
                    <button type="button" class="cy-chip js-preset" data-m="POST"   data-p="/notes">POST /notes</button>
                    <button type="button" class="cy-chip js-preset" data-m="GET"    data-p="/notes/1">GET /notes/1</button>
                    <button type="button" class="cy-chip js-preset" data-m="PUT"    data-p="/notes/1">PUT /notes/1</button>
                    <button type="button" class="cy-chip js-preset" data-m="DELETE" data-p="/notes/1">DELETE /notes/1</button>
                </div>

                <label class="form-label mt-3" for="body">İstek gövdesi &mdash; JSON (POST / PUT için)</label>
                <textarea id="body" class="form-control cy-code" rows="4" spellcheck="false">{
  "title": "Konsoldan eklenen not",
  "body": "Bu not POST /notes ile oluşturuldu."
}</textarea>

                <p class="cy-hint mt-2 mb-0">
                    Çipler yolu ve metodu <b>doldurur, göndermez</b>. <code>DELETE</code> gibi geri
                    alınamaz bir işlemin tek tıkla çalışmasını istemiyoruz.
                </p>
            </section>
        </div>

        <div class="cy-card__footer d-flex flex-wrap justify-content-between gap-2">
            <span>Uç kök: <code id="api-base"><?= e($base) ?>/api</code> &middot; jeton ömrü <b><?= (int) JWT_TTL ?> sn</b></span>
            <span>PHP <?= e(PHP_VERSION) ?></span>
        </div>
    </div>


    <!-- =================================================================
         5) YANIT
         ================================================================= -->
    <div class="cy-card mb-4" id="yanit">
        <div class="cy-card__header">
            <div class="cy-header-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="cy-brand__title mb-0">Yanıt</h2>
                    <p class="cy-brand__subtitle mb-0" id="resp-line">Henüz istek atılmadı</p>
                </div>
                <div class="cy-badges">
                    <span class="cy-status" id="resp-status">—</span>
                    <span class="cy-badge cy-badge--soft" id="resp-time">—</span>
                </div>
            </div>
        </div>
        <div class="cy-card__body">
            <div id="resp-explain" class="cy-explain d-none" role="status"></div>

            <h3 class="cy-section-title">Yanıt başlıkları</h3>
            <pre class="cy-log cy-log--payload" id="resp-headers">—</pre>

            <h3 class="cy-section-title mt-3">Gövde</h3>
            <pre class="cy-log" id="response" aria-live="polite" aria-label="API yanıtı">—</pre>
        </div>
    </div>


    <!-- =================================================================
         6) SENARYOLAR
         ---------------------------------------------------------------
         Her düğme, doğrulamanın bir katmanını BİLEREK kırar ve API'nin
         ne dediğini gösterir. İkisi (imza kurcalama, alg:none) tümüyle
         tarayıcıda üretilir; üçü sunucudaki /auth/demo-token ucundan
         gelir, çünkü bozuk ama İMZASI GEÇERLİ bir jeton üretmek için
         sırra ihtiyaç var — o da istemcide olmamalı.
         ================================================================= -->
    <div class="cy-card mb-4">
        <div class="cy-card__header">
            <div class="cy-header-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="cy-brand__title mb-0">Senaryolar</h2>
                    <p class="cy-brand__subtitle mb-0">Doğrulamayı bilerek kır, API'nin cevabını gör</p>
                </div>
                <span class="cy-badge cy-badge--glass">8 senaryo</span>
            </div>
        </div>
        <div class="cy-card__body">
            <div class="cy-scenarios">
                <button type="button" class="cy-scenario js-run" data-run="notes-listesi">
                    <span class="cy-scenario__code cy-scenario__code--ok">200</span>
                    <span class="cy-scenario__title">Not listesi</span>
                    <span class="cy-scenario__desc">Tam yetkili anahtarla normal akış</span>
                </button>

                <button type="button" class="cy-scenario js-run" data-run="not-olustur">
                    <span class="cy-scenario__code cy-scenario__code--ok">201</span>
                    <span class="cy-scenario__title">Not oluştur</span>
                    <span class="cy-scenario__desc">Location başlığıyla birlikte</span>
                </button>

                <button type="button" class="cy-scenario js-run" data-run="jetonsuz">
                    <span class="cy-scenario__code cy-scenario__code--warn">401</span>
                    <span class="cy-scenario__title">Jetonsuz istek</span>
                    <span class="cy-scenario__desc">Authorization başlığı hiç yok</span>
                </button>

                <button type="button" class="cy-scenario js-run" data-run="imza-kurcalanmis">
                    <span class="cy-scenario__code cy-scenario__code--warn">401</span>
                    <span class="cy-scenario__title">İmza kurcalanmış</span>
                    <span class="cy-scenario__desc">Geçerli jetonun son baytı değiştirildi</span>
                </button>

                <button type="button" class="cy-scenario js-run" data-run="alg-none">
                    <span class="cy-scenario__code cy-scenario__code--warn">401</span>
                    <span class="cy-scenario__title">alg: none saldırısı</span>
                    <span class="cy-scenario__desc">İmza silindi, payload yeniden yazıldı</span>
                </button>

                <button type="button" class="cy-scenario js-run" data-run="suresi-dolmus">
                    <span class="cy-scenario__code cy-scenario__code--warn">401</span>
                    <span class="cy-scenario__title">Süresi dolmuş jeton</span>
                    <span class="cy-scenario__desc">İmza doğru, exp geçmişte</span>
                </button>

                <button type="button" class="cy-scenario js-run" data-run="yanlis-servis">
                    <span class="cy-scenario__code cy-scenario__code--warn">401</span>
                    <span class="cy-scenario__title">Başka servisin jetonu</span>
                    <span class="cy-scenario__desc">aud eşleşmiyor</span>
                </button>

                <button type="button" class="cy-scenario js-run" data-run="yetkisiz-yazma">
                    <span class="cy-scenario__code cy-scenario__code--deny">403</span>
                    <span class="cy-scenario__title">Yetkisiz yazma</span>
                    <span class="cy-scenario__desc">Salt okunur anahtarla POST /notes</span>
                </button>
            </div>

            <p class="cy-hint mt-3 mb-0">
                Son iki senaryonun farkına dikkat edin: <b>401</b> "kim olduğunu bilmiyorum",
                <b>403</b> ise "kim olduğunu biliyorum ama bunu yapamazsın" demektir. 403 alan bir
                istemcinin yeni jeton almasının hiçbir faydası yoktur — ikisini karıştıran istemci
                sonsuz döngüye girer.
            </p>
        </div>
    </div>


    <!-- =================================================================
         7) İSTEK GEÇMİŞİ
         ================================================================= -->
    <div class="cy-card">
        <div class="cy-card__header">
            <div class="cy-header-top d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h2 class="cy-brand__title mb-0">İstek geçmişi</h2>
                    <p class="cy-brand__subtitle mb-0">Bu oturumda atılan istekler &mdash; satıra tıklayın</p>
                </div>
                <button type="button" id="btn-clear-history" class="btn cy-btn cy-btn--glass">Temizle</button>
            </div>
        </div>
        <div class="cy-card__body p-0">
            <div class="table-responsive">
                <table class="table cy-table cy-history mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Kod</th>
                            <th scope="col">İstek</th>
                            <th scope="col">Süre</th>
                            <th scope="col">Anahtar</th>
                        </tr>
                    </thead>
                    <tbody id="history"></tbody>
                </table>
            </div>
        </div>
        <div class="cy-card__footer">
            <span>Geçmiş yalnızca <b>bellekte</b> tutulur; sayfa yenilenince kaybolur &mdash; jeton gibi.</span>
        </div>
    </div>

    <div class="cy-footer-note mt-4">
        <p class="mb-1">
            Bu açık kaynak örnek, <a href="https://cilginyazilim.com" target="_blank" rel="noopener">cilginyazilim.com</a>
            tarafından geliştirilmiştir. MIT lisanslıdır; dilediğiniz gibi indirip kullanabilirsiniz.
        </p>
        <p class="mb-1">
            Katkı sağlamak ister misiniz? Depoyu çatallayın (fork) ve pull request gönderin:
            <a href="https://github.com/CilginYazilim/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri"
               target="_blank" rel="noopener">github.com/CilginYazilim</a>
        </p>
        <p class="mb-0">
            Aynı tasarım kalıbıyla hazırlanmış diğer açıklamalı örnekler:
            <a href="https://cilginyazilim.com/kutuphane" target="_blank" rel="noopener">cilginyazilim.com/kutuphane</a>
        </p>
    </div>
</div>


<!-- =====================================================================
     MODAL – İSTEK DETAYI
     ---------------------------------------------------------------------
     Geçmişteki bir satıra tıklanınca açılır: gönderilen başlıklar,
     gövde, dönen kod, dönen başlıklar ve gövde. Bir API konsolunda en
     sık sorulan soru "ne gönderdim de bunu aldım?" sorusudur.
     ===================================================================== -->
<div class="modal fade" id="modal-req" tabindex="-1" aria-labelledby="modal-req-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content cy-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-req-title">İstek</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Kapat"></button>
            </div>
            <div class="modal-body">
                <div id="req-badges" class="cy-badges mb-3"></div>
                <dl class="cy-detail" id="req-meta"></dl>

                <div id="req-note-wrap" class="d-none">
                    <div class="cy-explain" id="req-note"></div>
                </div>

                <h3 class="cy-section-title mt-3">Gönderilen</h3>
                <pre class="cy-log cy-log--payload" id="req-sent"></pre>

                <h3 class="cy-section-title mt-3">Dönen başlıklar</h3>
                <pre class="cy-log cy-log--payload" id="req-resp-headers"></pre>

                <h3 class="cy-section-title mt-3">Dönen gövde</h3>
                <pre class="cy-log" id="req-resp-body"></pre>

                <h3 class="cy-section-title mt-3">curl karşılığı</h3>
                <pre class="cy-log cy-log--payload" id="req-curl"></pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn cy-btn" data-bs-dismiss="modal">Kapat</button>
            </div>
        </div>
    </div>
</div>


<div id="cy-toast-area" class="cy-toast-area" aria-live="polite" aria-atomic="true"></div>

<script>
/*  Sunucudaki sabitleri istemciye TAŞIYORUZ, elle kopyalamıyoruz.
 *  İki taraf ayrışırsa ekranda yazan sayı ile davranış birbirini
 *  tutmaz — ve hangisinin doğru olduğu belli olmaz. */
window.API_BASE      = <?= json_encode($base . '/api', JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
window.CY_JWT_TTL    = <?= (int) JWT_TTL ?>;
window.CY_SCOPES     = <?= json_encode(KNOWN_SCOPES, JSON_UNESCAPED_UNICODE) ?>;
window.CY_DEMO_TOKEN = <?= DEMO_TOKENS ? 'true' : 'false' ?>;
</script>
<script src="assets/js/jquery-3.7.0.js"></script>
<script src="assets/js/bootstrap.bundle.js"></script>
<script src="assets/js/console.js"></script>
</body>
</html>
