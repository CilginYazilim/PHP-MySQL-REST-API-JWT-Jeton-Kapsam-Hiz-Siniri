/* =====================================================================
 *  API KONSOLU
 *  cilginyazilim.com – Sıfır Bağımlılık JWT REST API
 * ---------------------------------------------------------------------
 *  DİKKAT: BU DOSYA API'NİN PARÇASI DEĞİLDİR.
 *  API'yi denemek için yazılmış küçük bir istemcidir; kendi projenize
 *  alırken index.php ile birlikte silebilirsiniz. API onsuz da tam
 *  çalışır — curl ya da başka bir istemci aynı işi yapar.
 *
 *  BU DOSYANIN ÖĞRETTİĞİ ÜÇ KAVRAM
 *
 *  1) JETON TARAYICI DEPOSUNA DEĞİL BELLEĞE YAZILIR.
 *     Aşağıda jeton basit bir değişkende (state.token) tutulur;
 *     localStorage ya da sessionStorage KULLANILMAZ:
 *       · localStorage'a yazılan jetonu, sayfadaki HERHANGİ bir XSS
 *         açığı okuyup dışarı sızdırabilir. Saldırgan o jetonla,
 *         ömrü boyunca kullanıcı adına istek atabilir.
 *       · Bellekteki değişken sayfa yenilenince kaybolur. Bu bir
 *         "eksiklik" değil, bilinçli bir ödünleşimdir.
 *     Gerçek bir SPA'da da jeton bellekte tutulur; kalıcılık
 *     gerekiyorsa HttpOnly çerezle taşınan refresh token deseni
 *     kullanılır — yani JavaScript'in okuyamadığı bir yer.
 *
 *  2) JETON UÇ NOKTASI KENDİSİ JETON İSTEMEZ.
 *     /auth/token çağrısı Authorization başlığı GÖNDERMEZ; zaten
 *     jetonu almak için çağrılıyor.
 *
 *  3) PAYLOAD ŞİFRELİ DEĞİLDİR.
 *     decodeJwt() jetonun içini SUNUCUYA HİÇ SORMADAN çözer. İmzayı
 *     burada doğrulayamayız: doğrulamak için sır gerekir, sır da
 *     istemcide olmamalı. İstemci jetonun içini OKUYABİLİR ama ona
 *     GÜVENEMEZ; güvenilecek tek yer sunucudur.
 * ================================================================== */

/* global jQuery, bootstrap */

(function ($) {
    'use strict';

    /* =================================================================
     *  DURUM — hepsi bellekte
     * ================================================================= */
    var state = {
        token: null,       // erişim jetonu (ham JWT)
        claims: null,      // çözülmüş payload
        keyId: null,       // jetonun ait olduğu anahtar
        rate: null,        // son yanıttaki X-RateLimit-Remaining
        history: [],       // atılan istekler (en yenisi başta)
        seq: 0
    };

    var modal = null;

    /* =================================================================
     *  BÖLÜM 1 – KÜÇÜK YARDIMCILAR
     * ================================================================= */

    /** HTML kaçışı. Yanıt gövdesi API'den gelen VERİDİR; asla ham basılmaz. */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function pretty(v) {
        if (typeof v === 'string') { return v; }
        try { return JSON.stringify(v, null, 2); } catch (e) { return String(v); }
    }

    function toast(msg, kind) {
        var $t = $('<div>')
            .addClass('cy-toast cy-toast--' + (kind || 'info'))
            .attr('role', 'status')
            .text(msg)
            .appendTo('#cy-toast-area');

        setTimeout(function () {
            $t.addClass('cy-toast--out');
            setTimeout(function () { $t.remove(); }, 320);
        }, 3200);
    }

    /* base64url çözme — Türkçe karakterler için UTF-8 farkındalığı şart.
     * atob() ham bayt verir; decodeURIComponent(escape(...)) kalıbı o
     * baytları UTF-8 olarak yorumlar. Bu adım atlanırsa "Çılgın"
     * ekranda "Ã‡Ä±lgÄ±n" görünür. */
    function b64urlDecode(s) {
        s = String(s).replace(/-/g, '+').replace(/_/g, '/');
        while (s.length % 4) { s += '='; }
        try {
            return decodeURIComponent(escape(window.atob(s)));
        } catch (e) {
            return window.atob(s);
        }
    }

    function b64urlEncode(str) {
        var bytes = unescape(encodeURIComponent(str));
        return window.btoa(bytes).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    /**
     * Jetonun header ve payload bölümlerini çözer.
     * İMZAYI DOĞRULAMAZ — doğrulayamaz da (bkz. dosya başı, 3. kavram).
     */
    function decodeJwt(jwt) {
        var p = String(jwt || '').split('.');
        if (p.length !== 3) { return null; }
        try {
            return {
                header: JSON.parse(b64urlDecode(p[0])),
                payload: JSON.parse(b64urlDecode(p[1])),
                parts: p
            };
        } catch (e) {
            return null;
        }
    }

    function fmtSeconds(sec) {
        if (sec == null) { return '—'; }
        if (sec <= 0) { return 'doldu'; }
        var m = Math.floor(sec / 60), s = sec % 60;
        return m > 0 ? (m + ' dk ' + (s < 10 ? '0' : '') + s + ' sn') : (s + ' sn');
    }

    /* =================================================================
     *  BÖLÜM 2 – İSTEK GÖNDERİCİ
     * -----------------------------------------------------------------
     *  jQuery.ajax yerine fetch() kullanıyoruz çünkü:
     *    · Yanıt BAŞLIKLARINI (X-RateLimit-*, Location, WWW-Authenticate)
     *      okumamız gerekiyor — bu API'nin anlattığı şeyin yarısı
     *      başlıklarda.
     *    · Hata durumlarında (401/403/429) da gövdeyi okumak istiyoruz;
     *      jQuery bunları .fail()'e atar ve akış ikiye bölünür.
     * ================================================================= */

    /**
     * @param {string}  method
     * @param {string}  path    "/notes" gibi
     * @param {?string} body    ham JSON metni
     * @param {?string} auth    gönderilecek jeton; null ise başlık HİÇ eklenmez
     */
    async function call(method, path, body, auth) {
        var headers = {};
        var sentBody = null;

        /* GET ve DELETE gövde taşımaz; eklemek bazı sunucularda hataya
         * yol açar ve ara sunucular gövdeli GET'i düşürebilir. */
        if (method === 'POST' || method === 'PUT') {
            headers['Content-Type'] = 'application/json';
            sentBody = body || '';
        }

        if (auth) {
            /* Standart taşıyıcı (bearer) şeması. Sunucu tarafında
             * bearer_token() bu başlığı üç farklı kaynaktan okumayı
             * dener — bazı Apache/CGI kurulumlarında başlık PHP'ye
             * hiç ulaşmaz. */
            headers['Authorization'] = 'Bearer ' + auth;
        }

        var t0 = performance.now();
        var res, text;

        try {
            res = await fetch(window.API_BASE + path, {
                method: method,
                headers: headers,
                body: sentBody
            });
            text = await res.text();
        } catch (e) {
            return {
                status: 0, ok: false, ms: Math.round(performance.now() - t0),
                json: 'Ağ hatası: ' + e.message, headers: {}, raw: ''
            };
        }

        var ms = Math.round(performance.now() - t0);

        /* Önce .text() okuyup sonra JSON.parse deniyoruz. Doğrudan
         * .json() çağırsaydık, sunucu bir PHP fatal error döndürdüğünde
         * (HTML çıktı) hata mesajını HİÇ göremezdik. Bu yaklaşım bozuk
         * yanıtı da ekranda gösterir. */
        var json;
        try { json = text === '' ? null : JSON.parse(text); }
        catch (e) { json = text; }

        /* İlgilendiğimiz başlıklar. Tarayıcı bunları ancak sunucu
         * Access-Control-Expose-Headers ile izin verdiyse okutur —
         * api/index.php o başlığı gönderiyor. */
        var wanted = ['content-type', 'x-ratelimit-limit', 'x-ratelimit-remaining',
                      'x-ratelimit-reset', 'retry-after', 'location', 'www-authenticate', 'allow'];
        var hdrs = {};
        wanted.forEach(function (h) {
            var v = res.headers.get(h);
            if (v !== null) { hdrs[h] = v; }
        });

        if (hdrs['x-ratelimit-remaining'] != null) {
            state.rate = hdrs['x-ratelimit-remaining'];
        }

        return { status: res.status, ok: res.ok, ms: ms, json: json, headers: hdrs, raw: text };
    }

    /* =================================================================
     *  BÖLÜM 3 – EKRANA YAZMA
     * ================================================================= */

    function statusClass(code) {
        if (code === 0) { return 'cy-status--fail'; }
        if (code < 300) { return 'cy-status--ok'; }
        if (code < 400) { return 'cy-status--info'; }
        if (code < 500) { return 'cy-status--warn'; }
        return 'cy-status--fail';
    }

    var STATUS_TEXT = {
        200: 'OK', 201: 'Created', 204: 'No Content',
        400: 'Bad Request', 401: 'Unauthorized', 403: 'Forbidden',
        404: 'Not Found', 405: 'Method Not Allowed',
        422: 'Unprocessable Content', 429: 'Too Many Requests',
        500: 'Internal Server Error'
    };

    function showResponse(entry) {
        $('#resp-status')
            .removeClass('cy-status--ok cy-status--info cy-status--warn cy-status--fail')
            .addClass(statusClass(entry.status))
            .text(entry.status === 0 ? 'ağ hatası' : (entry.status + ' ' + (STATUS_TEXT[entry.status] || '')));

        $('#resp-time').text(entry.ms + ' ms');
        $('#resp-line').text(entry.method + ' ' + entry.path + (entry.keyId ? ' · ' + entry.keyId : ' · jetonsuz'));

        var hdrText = Object.keys(entry.headers).length
            ? Object.keys(entry.headers).map(function (k) { return k + ': ' + entry.headers[k]; }).join('\n')
            : '(ilgilenilen başlık dönmedi)';
        $('#resp-headers').text(hdrText);

        $('#response').text(entry.status === 204 ? '(204 No Content — gövde yok)' : pretty(entry.json));

        var $ex = $('#resp-explain');
        if (entry.note) {
            $ex.removeClass('d-none').html(entry.note);
        } else {
            $ex.addClass('d-none').empty();
        }
    }

    function pushHistory(entry) {
        entry.id = ++state.seq;
        state.history.unshift(entry);
        if (state.history.length > 25) { state.history.pop(); }
        renderHistory();
    }

    function renderHistory() {
        var $tb = $('#history').empty();

        if (!state.history.length) {
            $tb.append(
                '<tr><td colspan="4" class="cy-empty-cell">Henüz istek atılmadı. ' +
                'Yukarıdan bir jeton alın ya da bir senaryo çalıştırın.</td></tr>'
            );
            return;
        }

        state.history.forEach(function (h) {
            $tb.append(
                '<tr tabindex="0" data-id="' + h.id + '">' +
                    '<td><span class="cy-status cy-status--sm ' + statusClass(h.status) + '">' +
                        esc(h.status === 0 ? 'ağ' : h.status) + '</span></td>' +
                    '<td><span class="cy-verb cy-verb--' + esc(h.method.toLowerCase()) + '">' + esc(h.method) + '</span> ' +
                        '<code class="cy-path">' + esc(h.path) + '</code></td>' +
                    '<td class="cy-nowrap cy-muted">' + esc(h.ms) + ' ms</td>' +
                    '<td class="cy-muted">' + esc(h.keyId || '—') + '</td>' +
                '</tr>'
            );
        });
    }

    function renderToken() {
        var $badge = $('#token-badge');

        if (!state.token) {
            $badge.text('jeton yok');
            $('#panel-token').addClass('d-none');
            $('#stat-ttl').text('—');
            $('#stat-scopes').text('0');
            $('#stat-notes').text('—');
            return;
        }

        var d = decodeJwt(state.token);
        $('#panel-token').removeClass('d-none');

        /* Üç parça üç ayrı renkte. JWT'yi ilk kez gören biri için
         * "nokta ile ayrılmış üç bölüm" fikri en hızlı böyle oturuyor. */
        $('#jwt-raw').html(
            '<span class="cy-jwt__h">' + esc(d.parts[0]) + '</span>' +
            '<span class="cy-jwt__dot">.</span>' +
            '<span class="cy-jwt__p">' + esc(d.parts[1]) + '</span>' +
            '<span class="cy-jwt__dot">.</span>' +
            '<span class="cy-jwt__s">' + esc(d.parts[2]) + '</span>'
        );

        $('#jwt-header').text(pretty(d.header));
        $('#jwt-payload').text(pretty(d.payload));

        var scopes = d.payload.scopes || [];
        $('#stat-scopes').text(scopes.length);
        $badge.text(state.keyId + ' · ' + scopes.length + ' kapsam');

        tick();
    }

    /** Jetonun kalan ömrünü saniyede bir tazeler. */
    function tick() {
        var $ttl = $('#stat-ttl');
        var $stat = $('.cy-stat--token');

        if (!state.claims || !state.claims.exp) {
            $ttl.text('—');
            $stat.removeClass('is-warn');
            return;
        }

        var kalan = state.claims.exp - Math.floor(Date.now() / 1000);
        $ttl.text(fmtSeconds(kalan));

        /* Son 60 saniye: uyarı rengi. Jetonun bitmek üzere olduğunu
         * 401 alınca değil ÖNCE görmek gerekir. */
        $stat.toggleClass('is-warn', kalan <= 60);
    }

    function renderRate() {
        $('#stat-rate').text(state.rate == null ? '—' : state.rate);
    }

    /* =================================================================
     *  BÖLÜM 4 – JETON ALMA
     * ================================================================= */

    /**
     * @param {boolean} sessiz  true ise ekrana yanıt basılmaz (senaryolar
     *                          hazırlık adımını göstermesin diye)
     */
    async function getToken(keyId, secret, sessiz) {
        var body = JSON.stringify({ key_id: keyId, secret: secret });

        /* useAuth YOK: jeton almak için jeton gönderilmez. */
        var r = await call('POST', '/auth/token', body, null);

        var entry = {
            method: 'POST', path: '/auth/token', keyId: null,
            sent: body, status: r.status, ok: r.ok, ms: r.ms,
            json: r.json, headers: r.headers
        };

        if (r.ok && r.json && r.json.data && r.json.data.token) {
            state.token  = r.json.data.token;
            state.keyId  = keyId;
            var d = decodeJwt(state.token);
            state.claims = d ? d.payload : null;
            renderToken();
            loadStats();
            if (!sessiz) { toast('Jeton alındı: ' + keyId, 'success'); }
        } else {
            state.token = null; state.claims = null; state.keyId = null;
            renderToken();
            entry.note = '<b>401 invalid_client.</b> Sunucu "secret yanlış" ile "anahtar pasif" ' +
                         'arasındaki farkı SÖYLEMEZ. Söyleseydi, geçerli bir <code>key_id</code>\'yi ' +
                         'doğrulamış olurdu — saldırgan da geçerli anahtarların listesini böyle çıkarırdı.';
            if (!sessiz) { toast('Jeton alınamadı', 'danger'); }
        }

        pushHistory(entry);
        if (!sessiz) { showResponse(entry); }
        renderRate();
        return r;
    }

    /** Sayaç şeridindeki "Notlarım" ve "Aktif anahtar" değerleri. */
    async function loadStats() {
        if (!state.token) { return; }

        var r = await call('GET', '/stats', null, state.token);

        if (r.ok && r.json && r.json.data) {
            $('#stat-notes').text(r.json.data.notlarim);
            $('#stat-keys').text(r.json.data.anahtar_aktif + ' / ' + r.json.data.anahtar_toplam);
        } else {
            /* demo_writer'da profile:read yok → 403. Sayaç boş kalır;
             * bu bir hata değil, kapsam tasarımının sonucudur. */
            $('#stat-notes').text('—');
            $('#stat-keys').text('—');
        }
        renderRate();
    }

    /* =================================================================
     *  BÖLÜM 5 – İSTEK GÖNDER
     * ================================================================= */

    async function send(method, path, bodyText, auth, keyLabel, note) {
        var $btn = $('#btn-send').addClass('is-busy').prop('disabled', true);

        try {
            var r = await call(method, path, bodyText, auth);

            var entry = {
                method: method, path: path, keyId: keyLabel || null,
                sent: (method === 'POST' || method === 'PUT') ? bodyText : null,
                auth: auth, status: r.status, ok: r.ok, ms: r.ms,
                json: r.json, headers: r.headers, note: note || autoNote(r)
            };

            pushHistory(entry);
            showResponse(entry);
            renderRate();

            /* Yazma işlemleri not sayısını değiştirir; sayacı tazele. */
            if (r.ok && (method === 'POST' || method === 'DELETE')) { loadStats(); }

            return entry;
        } finally {
            $btn.removeClass('is-busy').prop('disabled', false);
        }
    }

    /**
     * Yanıta bakıp bir cümlelik açıklama üretir. Amaç, durum kodunun
     * NE ANLAMA GELDİĞİNİ ekranda tutmak — 401 ile 403'ü karıştırmak
     * en yaygın API hatasıdır.
     */
    function autoNote(r) {
        var code = r.json && r.json.error ? r.json.error.code : null;

        if (r.status === 401) {
            return '<b>401 Unauthorized — kimlik yok ya da geçersiz.</b> ' +
                   'Yapılacak şey yeni bir jeton almaktır.';
        }
        if (r.status === 403) {
            return '<b>403 Forbidden — kimlik geçerli, yetki yok.</b> ' +
                   'Jeton gayet sağlam; eksik olan <b>kapsam</b>. Yeni jeton almak işe YARAMAZ, ' +
                   'çünkü aynı anahtar aynı kapsamları verir.';
        }
        if (r.status === 429) {
            return '<b>429 Too Many Requests.</b> <code>Retry-After</code> başlığı kaç saniye ' +
                   'beklemeniz gerektiğini söyler; iyi bir istemci onu okur ve bekler.';
        }
        if (r.status === 404 && code === 'not_found') {
            return 'Başkasının kaydına 403 değil <b>404</b> dönüyoruz. 403, "böyle bir kayıt var ' +
                   'ama senin değil" bilgisini verirdi; o bilgi başkasının kaç kaydı olduğunu ' +
                   'saymaya yarar.';
        }
        if (r.status === 405) {
            return '<b>405.</b> <code>Allow</code> başlığı bu kaynağın hangi metotları kabul ' +
                   'ettiğini söyler — 404 dönseydik istemci yanlış yere bakardı.';
        }
        if (r.status === 422) {
            return '<b>422.</b> Gövde okundu ama doğrulamadan geçmedi. <code>details</code> ' +
                   'alanı hangi alanın neden reddedildiğini alan adıyla söyler.';
        }
        if (r.status === 201) {
            return '<b>201 Created.</b> Yeni kaynağın adresi <code>Location</code> başlığındadır; ' +
                   'gövdeye gömülü bir alan değil, standart başlık.';
        }
        if (r.status === 204) {
            return '<b>204 No Content.</b> Silme başarılı ve dönecek gövde yok. 204 gövde ' +
                   'taşıyamaz — "null" bile yazılmaz.';
        }
        return null;
    }

    /* =================================================================
     *  BÖLÜM 6 – SENARYOLAR
     * -----------------------------------------------------------------
     *  Her senaryo doğrulamanın bir katmanını BİLEREK kırar.
     *  İkisi tümüyle tarayıcıda üretilir (imza kurcalama, alg:none).
     *  Üçü sunucudaki /auth/demo-token ucundan gelir: imzası GEÇERLİ
     *  ama içeriği bozuk bir jeton üretmek için sır gerekir.
     * ================================================================= */

    var SENARYO = {
        'notes-listesi': {
            baslik: 'Not listesi',
            calistir: async function () {
                await ensureToken('demo_full', 'demo-secret-123');
                return send('GET', '/notes?limit=5', null, state.token, 'demo_full');
            }
        },

        'not-olustur': {
            baslik: 'Not oluştur',
            calistir: async function () {
                await ensureToken('demo_full', 'demo-secret-123');
                return send('POST', '/notes', $('#body').val(), state.token, 'demo_full');
            }
        },

        'jetonsuz': {
            baslik: 'Jetonsuz istek',
            calistir: function () {
                return send('GET', '/notes', null, null, null,
                    '<b>Authorization başlığı hiç gönderilmedi.</b> Sunucu 401 ve ' +
                    '<code>WWW-Authenticate: Bearer</code> döner — bu başlık istemciye ' +
                    '"hangi kimlik şemasını beklediğimi" söyler. Kimlik olmadan hiçbir ' +
                    'kaynağa bakılmaz; sorgu bile çalıştırılmaz.');
            }
        },

        'imza-kurcalanmis': {
            baslik: 'İmza kurcalanmış jeton',
            calistir: async function () {
                await ensureToken('demo_full', 'demo-secret-123');

                /* Geçerli jetonun imzasının SON KARAKTERİNİ değiştiriyoruz.
                 * Payload'a hiç dokunmuyoruz — yani "içerik doğru, imza
                 * bozuk" durumu. HMAC'te tek bitlik değişiklik imzanın
                 * tamamını tutmaz hâle getirir. */
                var p = state.token.split('.');
                var son = p[2].slice(-1);
                p[2] = p[2].slice(0, -1) + (son === 'A' ? 'B' : 'A');

                return send('GET', '/me', null, p.join('.'), 'kurcalanmış',
                    '<b>İmzanın tek karakteri değişti.</b> HMAC-SHA256\'da bir bitlik ' +
                    'değişiklik imzayı tümüyle geçersiz kılar. Kıyas <code>hash_equals()</code> ' +
                    'ile SABİT ZAMANDA yapılır: <code>===</code> ilk farklı baytta çıkardı ve ' +
                    'saldırgan süre farkını ölçerek imzayı bayt bayt bulabilirdi.');
            }
        },

        'alg-none': {
            baslik: 'alg: none saldırısı',
            calistir: function () {
                /* Kendi jetonumuzu uyduruyoruz: header'da alg "none",
                 * payload'da istediğimiz kapsamlar, imza bölümü BOŞ.
                 * Sırra ihtiyaç yok — saldırının bütün mesele ettiği
                 * şey de bu. */
                var header  = b64urlEncode(JSON.stringify({ alg: 'none', typ: 'JWT' }));
                var payload = b64urlEncode(JSON.stringify({
                    iss: 'cy-rest-api',
                    aud: 'cy-clients',
                    sub: 'demo_full',
                    scopes: ['notes:read', 'notes:write', 'profile:read'],
                    exp: Math.floor(Date.now() / 1000) + 3600
                }));

                return send('GET', '/me', null, header + '.' + payload + '.', 'sahte',
                    '<b>Bu jetonu tarayıcı uydurdu; imza yok.</b> Klasik hata, imza ' +
                    'algoritmasını <b>jetonun kendisinden</b> okumaktır: saldırgan ' +
                    '<code>alg</code> değerini "none" yapar, imzayı siler ve payload\'ı ' +
                    'istediği gibi yazar. Bu projede <code>alg</code> HS256 değilse jeton ' +
                    '<b>imza hiç hesaplanmadan</b> reddedilir.');
            }
        },

        'suresi-dolmus': {
            baslik: 'Süresi dolmuş jeton',
            calistir: async function () {
                var t = await demoToken('expired');
                if (!t) { return; }
                return send('GET', '/notes', null, t, 'süresi dolmuş',
                    '<b>İmza doğru, jeton gerçek — ama <code>exp</code> geçmişte.</b> ' +
                    'JWT sunucuda saklanmaz; tek tek iptal edilemez. Kısa ömür (' +
                    window.CY_JWT_TTL + ' sn) bu eksiğin karşılığıdır: çalınan bir jeton ' +
                    'en fazla o kadar süre işe yarar.');
            }
        },

        'yanlis-servis': {
            baslik: 'Başka servisin jetonu',
            calistir: async function () {
                var t = await demoToken('bad_audience');
                if (!t) { return; }
                return send('GET', '/notes', null, t, 'yanlış aud',
                    '<b>İmza geçerli, süre dolmamış — ama jeton başka bir servis için ' +
                    'üretilmiş.</b> Aynı imza sırrını paylaşan iki servisiniz varsa, birinin ' +
                    'jetonu diğerinde de doğrulanır. <code>iss</code> ve <code>aud</code> ' +
                    'kontrolü tam olarak bunu engeller.');
            }
        },

        'yetkisiz-yazma': {
            baslik: 'Yetkisiz yazma',
            calistir: async function () {
                await ensureToken('demo_readonly', 'readonly-secret-456');
                return send('POST', '/notes', $('#body').val(), state.token, 'demo_readonly',
                    '<b>403, 401 değil.</b> Jeton kusursuz: imza doğru, süre dolmamış, ' +
                    'kim olduğu belli. Eksik olan tek şey <code>notes:write</code> kapsamı. ' +
                    'Yanıttaki <code>details</code> alanı hangi kapsamın gerektiğini ve ' +
                    'jetonun hangilerini taşıdığını yazar — istemci ne yapacağını bilsin diye.');
            }
        }
    };

    /** Gerekiyorsa istenen anahtarla sessizce jeton alır. */
    async function ensureToken(keyId, secret) {
        if (state.token && state.keyId === keyId && state.claims &&
            state.claims.exp > Math.floor(Date.now() / 1000) + 5) {
            return;
        }
        $('#key_id').val(keyId);
        $('#secret').val(secret);
        $('.js-key').removeClass('is-active').filter('[data-key="' + keyId + '"]').addClass('is-active');
        await getToken(keyId, secret, true);
    }

    async function demoToken(fault) {
        var r = await call('POST', '/auth/demo-token', JSON.stringify({ fault: fault }), null);
        if (r.ok && r.json && r.json.data) { return r.json.data.token; }
        toast('Demo jetonu üretilemedi (DEMO_TOKENS kapalı olabilir)', 'danger');
        return null;
    }

    /* =================================================================
     *  BÖLÜM 7 – MODAL
     * ================================================================= */

    function openDetail(id) {
        var h = state.history.filter(function (x) { return x.id === id; })[0];
        if (!h) { return; }

        $('#modal-req-title').text(h.method + ' ' + h.path);

        $('#req-badges').html(
            '<span class="cy-status ' + statusClass(h.status) + '">' +
                esc(h.status === 0 ? 'ağ hatası' : h.status + ' ' + (STATUS_TEXT[h.status] || '')) + '</span>' +
            '<span class="cy-badge cy-badge--soft">' + esc(h.ms) + ' ms</span>' +
            '<span class="cy-badge cy-badge--soft">anahtar: <code>' + esc(h.keyId || 'yok') + '</code></span>'
        );

        $('#req-meta').html(
            '<dt>Metot</dt><dd><span class="cy-verb cy-verb--' + esc(h.method.toLowerCase()) + '">' +
                esc(h.method) + '</span></dd>' +
            '<dt>Yol</dt><dd><code>' + esc(window.API_BASE + h.path) + '</code></dd>' +
            /*  Başlık adı BÜYÜK HARFLE yazılıyor: sayfa lang="tr" ve
             *  CSS'teki text-transform:uppercase Türkçe kuralına göre
             *  "i" harfini "İ" yapıyordu — ekranda "AUTHORİZATİON"
             *  görünüyordu. Bir HTTP başlığının adı çevrilmez. */
            '<dt>AUTHORIZATION</dt><dd>' +
                (h.auth ? '<code>Bearer ' + esc(h.auth.slice(0, 24)) + '…</code>' : '<span class="cy-muted">gönderilmedi</span>') +
            '</dd>'
        );

        if (h.note) {
            $('#req-note-wrap').removeClass('d-none');
            $('#req-note').html(h.note);
        } else {
            $('#req-note-wrap').addClass('d-none');
        }

        $('#req-sent').text(h.sent || '(gövde gönderilmedi)');
        $('#req-resp-headers').text(
            Object.keys(h.headers).length
                ? Object.keys(h.headers).map(function (k) { return k + ': ' + h.headers[k]; }).join('\n')
                : '(ilgilenilen başlık dönmedi)'
        );
        $('#req-resp-body').text(h.status === 204 ? '(204 No Content — gövde yok)' : pretty(h.json));

        /* curl karşılığı: konsolda denenen her şey terminalde de
         * denenebilsin. API'nin tarayıcıya bağlı OLMADIĞINI göstermenin
         * en kısa yolu. */
        var curl = 'curl -i -X ' + h.method + " '" + location.origin + window.API_BASE + h.path + "'";
        if (h.auth) { curl += " \\\n  -H 'Authorization: Bearer " + h.auth.slice(0, 24) + "…'"; }
        if (h.sent) {
            curl += " \\\n  -H 'Content-Type: application/json'";
            curl += " \\\n  -d '" + h.sent.replace(/\s*\n\s*/g, ' ') + "'";
        }
        $('#req-curl').text(curl);

        modal.show();
    }

    /* =================================================================
     *  BÖLÜM 8 – OLAYLAR
     * ================================================================= */

    $('.js-key').on('click', function () {
        var $b = $(this);
        $('.js-key').removeClass('is-active');
        $b.addClass('is-active');
        $('#key_id').val($b.data('key'));
        $('#secret').val($b.data('secret'));
    });

    $('#btn-token, #add_button').on('click', function () {
        getToken($('#key_id').val(), $('#secret').val(), false);
    });

    $('#btn-send').on('click', function () {
        var method = $('#method').val();
        var path = String($('#path').val()).trim();
        if (path.charAt(0) !== '/') { path = '/' + path; }

        /* /auth/* uçları jeton İSTEMEZ; sunucudaki yönlendirme
         * mantığının aynası. */
        var acikUc = path.indexOf('/auth/') === 0 || path === '/';

        if (!acikUc && !state.token) {
            toast('Önce bir jeton alın', 'info');
            return;
        }

        send(method, path, $('#body').val(), acikUc ? null : state.token,
             acikUc ? null : state.keyId);
    });

    $('.js-preset').on('click', function () {
        $('#method').val($(this).data('m'));
        $('#path').val($(this).data('p'));
    });

    $('.js-run').on('click', function () {
        var id = $(this).data('run');
        if (SENARYO[id]) {
            /* Adres çubuğunu güncelliyoruz: senaryo PAYLAŞILABİLİR olsun.
             * pushState değil replaceState — geri düğmesi senaryolar
             * arasında dolaşmasın. */
            history.replaceState(null, '', '#dene-' + id);
            SENARYO[id].calistir();
        }
    });

    $('#history').on('click keydown', 'tr[data-id]', function (ev) {
        if (ev.type === 'keydown' && ev.key !== 'Enter' && ev.key !== ' ') { return; }
        ev.preventDefault();
        openDetail($(this).data('id'));
    });

    $('#btn-clear-history').on('click', function () {
        state.history = [];
        renderHistory();
        toast('Geçmiş temizlendi', 'info');
    });

    /* =================================================================
     *  BÖLÜM 9 – AÇILIŞ
     * ================================================================= */

    $(function () {
        modal = new bootstrap.Modal(document.getElementById('modal-req'));

        renderHistory();
        renderRate();
        setInterval(tick, 1000);

        if (!window.CY_DEMO_TOKEN) {
            /* DEMO_TOKENS kapalıysa üç senaryo çalışamaz; düğmeleri
             * çalışıyormuş gibi göstermek yanıltıcı olurdu. */
            $('.js-run[data-run="suresi-dolmus"], .js-run[data-run="yanlis-servis"]')
                .prop('disabled', true)
                .attr('title', 'DEMO_TOKENS kapalı');
        }

        /* PAYLAŞILABİLİR DERİN BAĞLANTI — iki biçim:
         *
         *   #dene-<senaryo>    senaryoyu çalıştırır
         *   #detay-<senaryo>   çalıştırır VE istek detayını açar
         *
         * Bir hata durumunu birine göstermenin en kısa yolu "şu
         * bağlantıya bak" demektir; detaylı biçim gönderilen başlıkları
         * ve curl karşılığını da doğrudan açar. */
        var m = /^#(dene|detay)-([a-z0-9-]+)$/.exec(location.hash);
        if (m && SENARYO[m[2]]) {
            setTimeout(async function () {
                var entry = await SENARYO[m[2]].calistir();
                if (entry && m[1] === 'detay') { openDetail(entry.id); }
            }, 250);
        } else {
            /* İlk açılışta jeton alınmaz: ziyaretçi "jetonsuzken ne
             * oluyor?" sorusunu görsün, sonra düğmeye bassın. */
            $('#response').text('Başlamak için sağ üstteki "Jeton al" düğmesine basın.\n\n' +
                'Jeton olmadan /notes çağrısı 401 döner — aşağıdaki "Jetonsuz istek" ' +
                'senaryosuyla deneyebilirsiniz.');
        }
    });

})(jQuery);
