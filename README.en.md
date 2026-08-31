<div align="center">

<img src="assets/images/logo.png" alt="Çılgın Yazılım" width="90">

# REST API with JWT

### PHP 8 · HS256 · Zero Dependencies · Scope-Based Authorization · Bootstrap 5 · Çılgın Yazılım Design Pattern

**No Composer, no firebase/php-jwt — 60 lines of PHP. But done right.**

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-5.7%2B-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://mysql.com)
[![JWT](https://img.shields.io/badge/JWT-HS256-000000?style=flat-square&logo=jsonwebtokens&logoColor=white)](https://jwt.io)
[![Dependencies](https://img.shields.io/badge/Dependencies-Zero-16a34a?style=flat-square)](#installation)
[![License](https://img.shields.io/badge/License-MIT-16a34a?style=flat-square)](LICENSE)

[🇹🇷 Türkçe](README.md) · **🇬🇧 English**

[**▶ Live Demo**](https://cilginyazilim.com/kutuphane/uygulama/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri-main/) · [Source Library](https://cilginyazilim.com/kutuphane/php-rest-api-jwt) · [cilginyazilim.com](https://cilginyazilim.com)

</div>

---

<div align="center">

## Live Demo

**No setup, no signup, no download — try it in your browser in 3 seconds.**

<a href="https://cilginyazilim.com/kutuphane/uygulama/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri-main/"><img src="https://img.shields.io/badge/OPEN_LIVE_DEMO-0b5cb5?style=for-the-badge&logo=googlechrome&logoColor=white&labelColor=061321" alt="Open Live Demo" height="42"></a>
<a href="https://cilginyazilim.com/kutuphane/php-rest-api-jwt"><img src="https://img.shields.io/badge/BROWSE_SOURCE-0ea5e9?style=for-the-badge&logo=readthedocs&logoColor=white&labelColor=061321" alt="Browse Source" height="42"></a>
<a href="https://github.com/CilginYazilim/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri/archive/refs/heads/main.zip"><img src="https://img.shields.io/badge/DOWNLOAD_ZIP-16a34a?style=for-the-badge&logo=github&logoColor=white&labelColor=061321" alt="Download ZIP" height="42"></a>

<br><br>

<a href="https://cilginyazilim.com/kutuphane/uygulama/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri-main/" title="Click to open the live demo">
  <img src="docs/screenshots/01-api-konsolu.png" alt="JWT REST API console live demo preview" width="860">
</a>

<sub>▲ Click the image to open the demo</sub>

</div>

<br>

### What can you try in 60 seconds?

| # | Try this | What happens behind the scenes |
|:-:|----------|-------------------------------|
| **1** | Press **🔑 Jeton al** (Get token) at the top right | `POST /auth/token` runs. The `secret` is compared against a stored **hash** via `password_verify()`; the raw secret is never stored anywhere. The response is a short-lived JWT |
| **2** | Look at the three coloured segments under **2 · Jeton** | A JWT is `header.payload.signature`. The payload is decoded **in the browser** — the server is never asked. Because it isn't encrypted, only base64url **encoded** |
| **3** | Read the `exp` and `scopes` claims in the payload | `exp` is when the token dies, `scopes` is **authority**. This is where identity and permission visibly part ways |
| **4** | Pick the **Salt okunur** (read-only) key, get a new token, then send `POST /notes` | **403 `insufficient_scope`.** The token is flawless: valid signature, not expired. The only thing missing is the `notes:write` scope |
| **5** | Try `GET /notes` with the **Yalnızca yazma** (write-only) key | **403** again. There is no rule saying "if you can write, you can read"; scopes do not imply one another |
| **6** | Try to get a token with the **Pasif anahtar** (inactive key) | **401.** The server does **not** distinguish "wrong secret" from "key disabled" — doing so would confirm that a `key_id` is valid |
| **7** | Run the **alg: none saldırısı** scenario | The browser forges its own token: `alg` = `none`, empty signature. The API rejects it **without ever computing a signature** |
| **8** | Run the **İmza kurcalanmış** (tampered signature) scenario | One character of a valid token's signature was changed. A single bit flip invalidates an HMAC entirely |
| **9** | Run **Süresi dolmuş jeton** (expired) and **Başka servisin jetonu** (wrong audience) | Both have a **valid signature**. One fails on `exp`, the other on `aud` — signature verification alone is not enough |
| **10** | Watch the **Kalan istek** (remaining) counter | Read from the `X-RateLimit-Remaining` **header**. The counter is per key and uses a sliding window |
| **11** | Click a row in the request history | Sent headers, body, response headers and the **curl equivalent**, all in one panel. The URL becomes `#detay-yetkisiz-yazma` — **shareable** |

> **Tip:** Open **F12 → Network** while using the demo. This page's only link to the server is `/api/...`; you can watch the `Authorization: Bearer …` request header and the `X-RateLimit-*` response headers live. Issue the same requests from a terminal with curl and you get the **same** answers — the API is not tied to a browser.

### About the demo environment

| Topic | Status |
|-------|--------|
| **Keys** | The **four keys** in `cy_api.sql` represent four permission states: full access, read-only, write-only, disabled. The secrets are public on purpose — this is a demo. |
| **Data** | **19 notes** spread across three keys. Each key sees **only its own** notes; someone else's note never appears in the list and returns `404` when requested by id. |
| **Reset** | The demo database returns to its initial state **periodically**; notes you delete come back. |
| **Token lifetime** | **900 seconds (15 min).** It counts down in the stats strip and turns amber in the final 60 seconds. |
| **`DEMO_TOKENS`** | **On** in the demo: `/auth/demo-token` mints deliberately broken tokens. Turn it **off** in your own install (see [Configuration](#configuration)). |
| **`APP_DEBUG`** | Automatically **`false`** in production — derived from the host name, stays `true` locally. |
| **Dependencies** | **Zero.** No Composer, no npm, no JWT library. |

> If the demo is temporarily down, don't worry: cloning the repo and importing `cy_api.sql` gets the same screen running on your machine in **2 minutes** → [Installation](#installation)

---

## What Is This Project?

A mobile app, an SPA or another service needs access to data in your database. You can't use a session cookie — cookies belong to browsers, not to mobile apps. The answer is well known: **a token-based API.**

The hard part is writing the token layer itself. Most examples on the internet do this:

```php
// The "easy" call you'll find in tutorials
$claims = JWT::decode($token, $key, ['HS256', 'RS256', 'none']);
if ($claims->user_id) { /* let them in */ }
```

Those three lines contain **three separate holes**:

1. `none` is accepted → an attacker strips the signature and rewrites the payload
2. `exp` is never checked → a stolen token is valid **forever**
3. Identity is confused with **authority** → anyone with a token can do anything

This project answers those three questions and five more that every API has to face — all **without a library**, in roughly 60 lines of `jwt_encode` / `jwt_decode`:

1. **What if the token is tampered with?** → constant-time HMAC check via `hash_equals()`
2. **What if `alg: none` arrives?** → the algorithm is **fixed**, never read from the token
3. **What if a token is stolen?** → short lifetime (`JWT_TTL`) plus `iss`/`aud` checks
4. **Can anyone with a token do anything?** → **scope**-based authorization
5. **What if the secrets table leaks?** → secrets are stored **hashed** (`password_hash`)
6. **What if someone hammers the endpoints?** → per-key sliding-window rate limiting
7. **How does the client understand an error?** → a consistent JSON envelope with a stable `error.code`
8. **What about someone else's records?** → the ownership condition lives in **every** `WHERE`

**Who is it for?**

- Anyone writing an API for a mobile app or SPA
- Anyone who wants to learn JWT by **seeing its insides**, not behind a library
- Anyone who wants to get the `401` vs `403` distinction right
- Anyone setting up scope-based authorization for the first time
- Anyone on shared hosting who can't run Composer
- Anyone looking for a reusable Bootstrap 5 design pattern

> **Clone, import `cy_api.sql`, run.** There is no other setup step. No Composer, no npm, not even an internet connection — every library ships inside the project.

This project is one of the annotated, production-ready examples published under the **[Çılgın Yazılım Library](https://cilginyazilim.com/kutuphane)**.

---

## Table of Contents

- [Live Demo](#live-demo)
- [What Is This Project?](#what-is-this-project)
- [Screenshots](#screenshots)
- [Five Critical Decisions](#five-critical-decisions)
- [What's Included?](#whats-included)
- [Security: What Did We Close, and How?](#security-what-did-we-close-and-how)
- [Installation](#installation)
- [Configuration](#configuration)
- [Adding It to Your Own Project](#adding-it-to-your-own-project)
- [Design Pattern](#design-pattern)
- [File Structure](#file-structure)
- [How Does It Work?](#how-does-it-work)
- [API Reference](#api-reference)
- [Database Schema](#database-schema)
- [FAQ](#faq)
- [Going to Production](#going-to-production)
- [Troubleshooting](#troubleshooting)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [License](#license)

---

## Screenshots

### API console

The token's three segments, the decoded header and payload, the endpoint call and the response — on one screen.

<img src="docs/screenshots/01-api-konsolu.png" alt="API console: token, endpoints and response" width="900">

### Request detail

Sent headers, body, response headers, response body and the **curl equivalent**. The explanation box at the top spells out the `403` vs `401` difference at exactly the right moment.

<img src="docs/screenshots/02-istek-detayi.png" alt="Request detail dialog: 403 insufficient_scope" width="900">

### Mobile view

**No horizontal scrolling** at 390px. Secondary columns are hidden; the information is preserved in the detail dialog.

<img src="docs/screenshots/03-mobil.png" alt="Mobile view" width="380">

---

## Five Critical Decisions

### 1. The algorithm is never read from the token

```php
// TYPICAL BROKEN CODE — the attacker picks the algorithm
$header = json_decode(base64_decode($h64), true);
$alg = $header['alg'];               // could be "none"!
if ($alg === 'none') { /* no signature, pass */ }

// IN THIS PROJECT — the algorithm is FIXED
if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
    return [null, 'bad_alg'];        // rejected before any signature is computed
}
```

The `alg: none` attack lived inside real libraries for years and still lurks in copy-pasted examples today. The attacker sets `alg` to `none`, deletes the signature segment and writes whatever `sub` and `scopes` they like. If the code reads the algorithm **from the token itself**, the attacker has just approved their own identity.

The correct approach is for the server to **know in advance** which algorithm it accepts. The demo's "alg: none saldırısı" scenario forges such a token in the browser and shows it being rejected.

### 2. Signatures are compared with `hash_equals`

```php
// TYPICAL BROKEN CODE
if ($expectedSig === $incomingSig) { /* ... */ }

// IN THIS PROJECT
if (!hash_equals($expectedSig, b64url_decode($s64))) {
    return [null, 'bad_signature'];
}
```

PHP's `===` compares strings byte by byte and **exits at the first difference**. The comparison time therefore depends on how many bytes matched — a difference measurable even over a network. An attacker can guess the signature one byte at a time.

`hash_equals()` always takes the **same** time regardless. This is the non-negotiable part of token verification.

### 3. Authentication and authorization are separate layers

```
Authorization: Bearer <jwt>
        │
        ├── require_auth()   → is the token valid?        if not → 401
        │                       (signature, exp, nbf, iss, aud)
        │
        └── require_scope()  → may it do this?            if not → 403
                                (scopes claim)
```

**401 means "I don't know who you are"; 403 means "I know exactly who you are, and you may not do this."** A client that confuses the two will, on receiving a 403, try to fetch a new token, get the same 403, and spin in an **infinite loop**.

The four demo keys make this concrete: `demo_writer` can write but **cannot read**. There is no rule saying "if you can write, you can read."

### 4. Secrets are stored hashed

```sql
-- TYPICAL BROKEN SCHEMA
`secret` VARCHAR(64) NOT NULL,        -- raw secret; a leaked dump = a captured API

-- IN THIS PROJECT
`secret_hash` VARCHAR(255) NOT NULL,  -- password_hash(); cannot be reversed
```

An API key's secret is a password too — its user just happens to be a program rather than a person. The key table is the first place anyone looks in a leaked database dump. If raw secrets were stored there, whoever obtained the dump would walk straight into the API with full privileges.

Verification uses `password_verify()` and **runs even when the key doesn't exist**: otherwise the timing difference between "this `key_id` exists but the secret is wrong" and "this `key_id` doesn't exist" would be measurable.

### 5. Ownership lives in SQL, not in PHP

```php
// TYPICAL BROKEN CODE — forgotten in one place eventually
$note = $db->query("SELECT * FROM api_notes WHERE id = $id")->fetch();
if ($note['owner_key'] !== $claims['sub']) { /* 403 */ }

// IN THIS PROJECT — IMPOSSIBLE to forget
$stmt = $db->prepare('SELECT … FROM api_notes WHERE id = :id AND owner_key = :k');
```

Leaving the ownership check to PHP means forgetting it in one of five endpoints — and that one endpoint is enough to leak everything. Once the condition is embedded in the `WHERE` clause, it stops being a step that can be skipped.

One more detail: unauthorized records get **404, not 403**. A 403 would confirm "such a record exists but isn't yours" — and that confirmation is exactly what someone counting another user's records needs.

---

## What's Included?

<table>
<tr><td width="50%" valign="top">

**JWT core (no library)**
- HS256 signing with `hash_hmac` + `hash_equals`
- Fixed `alg` — `none` and algorithm confusion closed
- `exp` and `nbf` checks with a `JWT_LEEWAY` tolerance
- Tokens **without** `exp` are rejected
- `iss` / `aud` verification
- `jti` (token id) present from day one
- base64url encode/decode written by hand

**Identity and authority**
- API key → short-lived token flow
- Secrets stored with `password_hash()`
- Dummy-hash verification against timing attacks
- Single error type against key enumeration
- Scope-based authorization
- Scopes pass through a **whitelist**
- `active = 0` disables a key without deleting it

</td><td width="50%" valign="top">

**API design**
- Consistent JSON envelope: `data` / `meta` / `error`
- Stable `error.code` plus human-readable `error.message`
- Correct status codes: 200/201/204/400/401/403/404/405/422/429/500
- `Allow`, `Location`, `WWW-Authenticate`, `Retry-After` headers
- Pagination, search (`?q=`), whitelisted sorting
- Per-key **sliding-window** rate limiting
- `GET /` endpoint discovery
- CORS with preflight (`OPTIONS` → 204)

**Console and design**
- The token's three segments in colour; payload decoded in the browser
- **8 scenarios** that deliberately break verification
- Request history, detail dialog and **curl equivalent**
- Shareable deep links (`#dene-…`, `#detay-…`)
- Stats strip: remaining lifetime, scopes, notes, remaining requests, keys
- Mobile: **no horizontal scrolling** at 360px
- The token is **never** written to `localStorage` — memory only

</td></tr>
</table>

---

## Security: What Did We Close, and How?

| Hole | Typical broken code | In this project |
|------|--------------------|-----------------|
| **`alg: none` attack** | `$alg = $header['alg'];` — algorithm read from the token | Rejected **before any signature is computed** if `alg !== 'HS256'` |
| **Timing attack (signature)** | `if ($sig === $expected)` | `hash_equals()` — constant-time comparison |
| **Timing attack (secret)** | Early `return` when the key is missing | `password_verify()` runs **anyway** against a dummy hash |
| **Never-expiring tokens** | `exp` never checked | Tokens without `exp` are **rejected**; with it, checked using `JWT_LEEWAY` |
| **Cross-service token reuse** | No `iss`/`aud` check | Both compared against expected values |
| **Assuming identity == authority** | Any token holder can do anything | `require_scope()` — **403** plus `details` naming the required scope |
| **Key enumeration** | Separate "key not found" / "wrong secret" messages | A single `401 invalid_client` for both |
| **Storing raw secrets** | `secret VARCHAR(64)` | `secret_hash` — `password_hash()`, irreversible |
| **SQL injection** | String-concatenated queries | Prepared statements everywhere, `EMULATE_PREPARES = false` |
| **`ORDER BY` injection** | `ORDER BY $_GET['sort']` | Whitelist; unknown values fall back to `id` |
| **`LIKE` wildcard abuse** | `LIKE '%$q%'` | `%` and `_` escaped (`ESCAPE '!'`) |
| **Accessing another user's record** | Ownership checked in PHP | `owner_key` in **every** `WHERE`; unauthorized records return **404** |
| **Brute-forcing secrets** | Unlimited `POST /auth/token` | **12 requests / 60 s** per key or IP, plus `Retry-After` |
| **Information-leaking errors** | SQL text printed in production | `APP_DEBUG` derived from the host name; `false` in production |
| **Token theft via XSS** | Token written to `localStorage` | The console keeps the token in **memory** only |
| **Configuration leak** | `config.php` downloadable | `system/` **fully denied** plus an in-file `CY_APP` guard |
| **Schema/data leak** | `/cy_api.sql` → HTTP 200 | `.sql`, `.md`, `.json`, `.log`, `.ini`, `.bak`, `.example` denied (`README*.md` a deliberate exception) |
| **Clickjacking** | No header | `X-Frame-Options: SAMEORIGIN` |
| **MIME sniffing** | No header | `X-Content-Type-Options: nosniff` |

> **Why no CSRF protection?** This API uses no cookies; identity travels in the `Authorization` header. Since the browser sends no credentials automatically, an attacker's page can issue the request but **cannot attach the token**. CSRF is a problem of cookie-borne sessions.

---

## Installation

**Requirements:** PHP 8.0+ · MySQL 5.7+ / MariaDB 10.3+ · Apache (mod_rewrite recommended)

```bash
# 1) Get the repository
git clone https://github.com/CilginYazilim/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri.git
cd PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri

# 2) Create the database (the file runs CREATE DATABASE itself)
mysql -u root -p < cy_api.sql

# 3) Create local settings (optional; defaults suit XAMPP)
cp system/config.local.php.example system/config.local.php
#    → fill in the DB_* and JWT_SECRET lines

# 4) Open it in a browser
#    http://localhost/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri/
```

**No Composer, no npm.** jQuery and Bootstrap ship in the repo; it works offline.

### A 30-second curl try-out

```bash
BASE=http://localhost/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri/api

# Get a token
TOKEN=$(curl -s -X POST "$BASE/auth/token" \
  -H 'Content-Type: application/json' \
  -d '{"key_id":"demo_full","secret":"demo-secret-123"}' \
  | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["data"]["token"];')

# Use it
curl -s "$BASE/notes?limit=3" -H "Authorization: Bearer $TOKEN"
curl -s "$BASE/me"            -H "Authorization: Bearer $TOKEN"

# See the scope boundary (writing with a read-only key → 403)
RO=$(curl -s -X POST "$BASE/auth/token" -H 'Content-Type: application/json' \
  -d '{"key_id":"demo_readonly","secret":"readonly-secret-456"}' \
  | php -r 'echo json_decode(file_get_contents("php://stdin"),true)["data"]["token"];')
curl -i -s -X POST "$BASE/notes" -H "Authorization: Bearer $RO" \
  -H 'Content-Type: application/json' -d '{"title":"nope"}'
```

### Without mod_rewrite

Clean URLs (`/api/notes`) rely on the rewrite in `api/.htaccess`. If rewriting is unavailable, the API is still reachable:

```
/api/index.php/notes        (PATH_INFO)
/api/index.php?path=/notes  (query string)
```

All three forms land in the same router; `resolve_path()` tries all of them.

---

## Configuration

Every setting lives in `system/config.php`. **Secrets do not go there** — they go into `system/config.local.php`, which is in `.gitignore`, never reaches the repo and is not wiped by a deploy.

| Constant | Default | Purpose |
|----------|---------|---------|
| `JWT_SECRET` | *(development value)* | HS256 signing key. **Always change it in production.** |
| `JWT_TTL` | `900` | Token lifetime in seconds. Keep it short. |
| `JWT_ISS` / `JWT_AUD` | `cy-rest-api` / `cy-clients` | Who issued the token and who it is for. |
| `JWT_LEEWAY` | `30` | Clock-skew tolerance between servers, in seconds. |
| `RATE_LIMIT_TOKEN` | `[12, 60]` | Token minting: 12 requests / 60 s. |
| `RATE_LIMIT_API` | `[180, 60]` | Normal traffic: 180 requests / 60 s. |
| `KNOWN_SCOPES` | 3 scopes | Recognised scopes. Anything not listed is ignored. |
| `DEMO_TOKENS` | `true` | The deliberately-broken-token endpoint. **Set it to `false` in production.** |
| `APP_DEBUG` | *(automatic)* | Derived from the host name; switches itself off on a live domain. |
| `NOTE_TITLE_MAX` / `NOTE_BODY_MAX` | `150` / `10000` | Validation limits. |
| `NOTES_PAGE_MAX` | `100` | Maximum records per page. |

### Generating a new signing key

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Changing the key invalidates **every** token currently in circulation. That is not a side effect — it is the only bulk revocation mechanism you have.

### Adding a new API key

```bash
php -r "echo password_hash('your-secret', PASSWORD_DEFAULT), PHP_EOL;"
```

```sql
INSERT INTO api_keys (name, key_id, secret_hash, scopes)
VALUES ('Mobile app', 'mobile_v1', '$2y$10$…', 'notes:read notes:write profile:read');
```

---

## Adding It to Your Own Project

Three files carry over from this repo:

| File | What it carries |
|------|-----------------|
| `system/function.php` | JWT core, JSON envelope, rate limiter, identity/scope helpers |
| `system/config.php` | The configuration pattern and the `config.local.php` mechanism |
| `api/index.php` | Routing and endpoints — replace with your own resources |

`index.php` and `assets/js/console.js` are **not part of the API**; delete them if you like.

### Adding a new endpoint

```php
// in api/index.php, AFTER the $claims = require_auth(); line

if ($path === '/products' && $method === 'GET') {
    require_scope($claims, 'products:read');      // authorization check
    $stmt = $db->prepare('SELECT id, name, price FROM products WHERE owner_key = :k');
    $stmt->execute([':k' => $claims['sub']]);
    api_ok($stmt->fetchAll());                    // { "data": [...] }
}
```

Don't forget to add the new scope to `KNOWN_SCOPES` — a scope that isn't whitelisted is **ignored** even if it is written on the key row in the database.

### Using the JWT core on its own

```php
require 'system/function.php';

$jwt = jwt_encode(['sub' => 'user-42', 'scopes' => ['notes:read']]);

[$claims, $err] = jwt_decode($jwt);
if ($err !== null) {
    echo jwt_error_message($err);   // "The token has expired." etc.
}
```

---

## Design Pattern

The interface uses the design pattern shared by every Çılgın Yazılım example:

| File | Scope | Should you edit it? |
|------|-------|---------------------|
| `assets/css/cilginyazilim.css` | **Brand pattern** — cards, buttons, tables, badges, modal | **No.** It is shared across projects. |
| `assets/css/style.css` | Only what is specific to this page (key cards, JWT display, scenario grid) | Yes |

Load order: `bootstrap` → `cilginyazilim` → `style`. Colours are never hard-coded; they come from CSS variables (`--cy-brand-600`, `--cy-danger`, …).

Other examples built on the same pattern: [cilginyazilim.com/kutuphane](https://cilginyazilim.com/kutuphane)

---

## File Structure

```
.
├── api/
│   ├── .htaccess          → clean-URL rewrite + Authorization header pass-through
│   └── index.php          → API FRONT CONTROLLER: routing and every endpoint
├── system/
│   ├── .htaccess          → directory FULLY denied (Require all denied)
│   ├── config.php         → configuration + PDO connection
│   ├── config.local.php   → (you create it; in .gitignore)
│   ├── config.local.php.example
│   └── function.php       → JWT, JSON envelope, rate limiter, identity/scopes
├── assets/
│   ├── css/               → bootstrap.min · cilginyazilim (brand) · style
│   ├── js/                → jquery · bootstrap.bundle · console.js
│   └── images/logo.png
├── docs/screenshots/
├── .htaccess              → no directory listing, file-type rules, security headers
├── cy_api.sql             → schema + 4 keys + 19 notes (timestamps via NOW() - INTERVAL)
├── index.php              → API CONSOLE (not part of the API)
├── CHANGELOG.md
├── LICENSE
├── README.md
└── README.en.md
```

---

## How Does It Work?

```
  CLIENT                           API                            DATABASE
     │                              │                                  │
     │  POST /auth/token            │                                  │
     │  { key_id, secret }          │                                  │
     ├─────────────────────────────>│                                  │
     │                              │  rate limit (12/60 s)            │
     │                              │  SELECT … WHERE key_id = ?       │
     │                              ├─────────────────────────────────>│
     │                              │  password_verify(secret, hash)   │
     │                              │  active = 1 ?                    │
     │                              │                                  │
     │                              │  jwt_encode({sub, scopes, exp})  │
     │  { data: { token, … } }      │                                  │
     │<─────────────────────────────┤                                  │
     │                                                                 │
     │  GET /notes                                                     │
     │  Authorization: Bearer <jwt>                                    │
     ├─────────────────────────────>│                                  │
     │                              │  1) jwt_decode()                 │
     │                              │     alg == HS256 ?     ─┐        │
     │                              │     hash_equals(sig)    │ 401    │
     │                              │     exp / nbf           │        │
     │                              │     iss / aud          ─┘        │
     │                              │                                  │
     │                              │  2) rate limit (per key)         │
     │                              │                                  │
     │                              │  3) require_scope('notes:read')  │
     │                              │     if missing ─────────> 403    │
     │                              │                                  │
     │                              │  4) SELECT … WHERE owner_key = ? │
     │                              ├─────────────────────────────────>│
     │  { data: [...], meta: {...} }│                                  │
     │<─────────────────────────────┤                                  │
```

**The order is deliberate.** Rate limiting comes **after** authentication because the counter is per key. Placing it first would force per-IP counting, and every client behind a single IP (a corporate network, a mobile carrier's NAT) would eat each other's quota.

The scope check comes **before** the query: an unauthorized request never reaches the database.

---

## API Reference

Every response uses the same envelope:

```jsonc
// Success
{ "data": … , "meta": { … } }        // meta only when relevant

// Failure
{ "error": { "code": "…", "message": "…", "details": { … } } }
```

`error.code` is **for machines and never changes**; `error.message` is for humans and may change. Clients must not branch on the `message` text.

<details>
<summary><b>GET /</b> — endpoint discovery (no token required)</summary>

```bash
curl -s "$BASE/"
```

```json
{
  "data": {
    "name": "Çılgın Yazılım · JWT REST API",
    "version": "1.0.0",
    "auth": { "type": "Bearer JWT (HS256)", "token_url": "/auth/token", "expires_in": 900, "scopes": { … } },
    "endpoints": { "GET /notes": "Not listesi (notes:read)", … },
    "rate_limits": { "token": "12/60s", "api": "180/60s" }
  }
}
```

No authentication. Endpoint paths are not secrets; confidentiality comes from **authorization**, not from nobody knowing the URL.
</details>

<details>
<summary><b>POST /auth/token</b> — mint a token</summary>

```bash
curl -s -X POST "$BASE/auth/token" -H 'Content-Type: application/json' \
  -d '{"key_id":"demo_full","secret":"demo-secret-123"}'
```

```json
{
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9…",
    "token_type": "Bearer",
    "expires_in": 900,
    "expires_at": "2026-08-31T09:15:00+00:00",
    "scopes": ["notes:read", "notes:write", "profile:read"],
    "key_name": "Mobil uygulama (tam yetki)"
  }
}
```

| Status | Code | When |
|--------|------|------|
| `422` | `invalid_request` | `key_id` or `secret` empty |
| `401` | `invalid_client` | Wrong secret **or** disabled key (not distinguished) |
| `429` | `rate_limited` | More than 12 attempts in 60 seconds |
</details>

<details>
<summary><b>POST /auth/demo-token</b> — deliberately broken tokens (only while <code>DEMO_TOKENS</code> is on)</summary>

```bash
curl -s -X POST "$BASE/auth/demo-token" -H 'Content-Type: application/json' \
  -d '{"fault":"expired"}'
```

| `fault` | Token produced | Expected outcome |
|---------|----------------|------------------|
| `expired` | `exp` in the past | `401 invalid_token` / `expired` |
| `future` | `nbf` in the future | `401 invalid_token` / `not_yet_valid` |
| `bad_audience` | `aud` of another service | `401 invalid_token` / `bad_audience` |
| `no_expiry` | `exp` claim **absent** | `401 invalid_token` / `no_expiry` |
| `no_scopes` | Valid token, no scopes | `403 insufficient_scope` |

This endpoint is a **teaching aid**. Producing a broken but validly signed token requires the secret, and the secret must not live in the client. With `DEMO_TOKENS = false` the endpoint returns `404`. Every token it produces is already invalid; none of them carries any authority.
</details>

<details>
<summary><b>GET /me</b> — key profile <code>(profile:read)</code></summary>

```bash
curl -s "$BASE/me" -H "Authorization: Bearer $TOKEN"
```

```json
{
  "data": {
    "name": "Mobil uygulama (tam yetki)",
    "key_id": "demo_full",
    "active": true,
    "scopes": ["notes:read", "notes:write", "profile:read"],
    "created_at": "2026-05-27 00:40:43",
    "last_used_at": "2026-08-31 00:41:40",
    "token": { "jti": "985a34024773adbf", "iat": 1788126100, "exp": 1788127000, "kalan_saniye": 900 }
  }
}
```
</details>

<details>
<summary><b>GET /stats</b> — counters <code>(profile:read)</code></summary>

```json
{ "data": { "notlarim": 13, "son_gun": 4, "anahtar_toplam": 4, "anahtar_aktif": 3,
            "kapsamlarim": ["notes:read","notes:write","profile:read"], "kalan_saniye": 812 } }
```

The note count is computed **only for the token's owner**; another key's note count is never shared.
</details>

<details>
<summary><b>GET /notes</b> — list <code>(notes:read)</code></summary>

| Parameter | Default | Note |
|-----------|---------|------|
| `page` | `1` | |
| `limit` | `10` | Maximum `100` |
| `q` | — | Searches title and body; `%` and `_` are escaped |
| `sort` | `id` | `id` \| `title` \| `created_at` \| `updated_at` (**whitelist**) |
| `dir` | `desc` | `asc` \| `desc` |

```bash
curl -s "$BASE/notes?q=jeton&sort=title&dir=asc&limit=5" -H "Authorization: Bearer $TOKEN"
```

```json
{ "data": [ { "id": 5, "title": "…", "body": "…", "created_at": "…", "updated_at": "…" } ],
  "meta": { "page": 1, "limit": 5, "total": 3, "pages": 1, "sort": "title", "dir": "asc", "q": "jeton" } }
```
</details>

<details>
<summary><b>POST /notes</b> — create <code>(notes:write)</code></summary>

```bash
curl -i -s -X POST "$BASE/notes" -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' -d '{"title":"New note","body":"content"}'
```

`201 Created` plus `Location: /notes/138`. The new resource's address lives in the **header** — a standard header, not a field buried in the body.

| Status | Code | When |
|--------|------|------|
| `400` | `invalid_json` | The body is not valid JSON |
| `422` | `validation_failed` | `details` names the field and the reason |
| `403` | `insufficient_scope` | The token lacks `notes:write` |
</details>

<details>
<summary><b>GET / PUT / DELETE /notes/{id}</b> — a single record</summary>

```bash
curl -s        "$BASE/notes/12" -H "Authorization: Bearer $TOKEN"
curl -s -X PUT "$BASE/notes/12" -H "Authorization: Bearer $TOKEN" \
     -H 'Content-Type: application/json' -d '{"title":"New title"}'
curl -i -s -X DELETE "$BASE/notes/12" -H "Authorization: Bearer $TOKEN"
```

- **PUT performs a partial update**: fields you send are updated, fields you omit are preserved. By the book that is PATCH's job; the simplification is deliberate and documented here rather than left to be discovered.
- **DELETE returns `204 No Content`** — with no body. A 204 cannot carry one; not even `null`.
- Someone else's record returns **404** (not 403).
- `PUT`/`DELETE` on the collection (`/notes`) returns **405** with `Allow: GET, POST`.
</details>

<details>
<summary><b>Error codes</b> — full list</summary>

| HTTP | `error.code` | Meaning |
|------|--------------|---------|
| 400 | `invalid_json` | The body is not valid JSON |
| 401 | `unauthorized` | No `Authorization` header |
| 401 | `invalid_token` | Token invalid — `details.reason`: `malformed`, `bad_alg`, `bad_signature`, `bad_payload`, `expired`, `not_yet_valid`, `no_expiry`, `bad_audience` |
| 401 | `invalid_client` | Wrong `key_id`/`secret`, or the key is disabled |
| 403 | `insufficient_scope` | Identity valid, scope missing. `details`: `required`, `granted` |
| 404 | `not_found` | Resource absent **or** owned by someone else |
| 405 | `method_not_allowed` | The `Allow` header names what is permitted |
| 422 | `invalid_request` / `validation_failed` | Input missing or failed validation |
| 429 | `rate_limited` | `Retry-After` says how long to wait |
| 500 | `server_error` | Details only when `APP_DEBUG` is on |
</details>

---

## Database Schema

```sql
api_keys
├── id            INT UNSIGNED  AUTO_INCREMENT
├── name          VARCHAR(120)  human-readable name
├── key_id        VARCHAR(64)   UNIQUE — public identifier
├── secret_hash   VARCHAR(255)  password_hash(); raw secret NEVER stored
├── scopes        VARCHAR(255)  space-separated permissions
├── active        TINYINT(1)    0 = no tokens issued
├── created_at    TIMESTAMP
└── last_used_at  DATETIME      last token issued

api_notes
├── id            INT UNSIGNED  AUTO_INCREMENT (starts at 137)
├── owner_key     VARCHAR(64)   matches the 'sub' claim
├── title         VARCHAR(150)
├── body          TEXT
├── created_at    TIMESTAMP
├── updated_at    TIMESTAMP     ON UPDATE CURRENT_TIMESTAMP
└── KEY idx_notes_owner_id (owner_key, id)
```

| Decision | Why |
|----------|-----|
| `secret_hash`, not `secret` | A leaked dump must not be enough to capture the API. |
| `scopes` as one column, not a table | The scope set is small and fixed; a third table would be noise rather than instruction. If scopes become dynamic, `api_key_scopes` is the right answer. |
| `active`, not `DELETE` | Disabling a key must be reversible, and when a key was disabled is worth keeping. |
| `owner_key`, not `api_keys.id` | The token's `sub` claim carries `key_id`. Using `id` would mean hitting the key table on every request — removing exactly that round trip is why the token exists. |
| `idx_notes_owner_id (owner_key, id)` | The list query filters on `owner_key` and sorts by `id`; one index serves both. |
| `AUTO_INCREMENT = 137` | Notes get deleted in the demo, so numbers keep freeing up. If a new record inherited a deleted number, an old link would resolve to the wrong record. |

---

## FAQ

<details>
<summary><b>Why not use a JWT library?</b></summary>

You can, in production — `firebase/php-jwt` is a good library. But this is a **teaching example**: the fastest way to understand what a JWT is, is to read a 60-line `jwt_encode`/`jwt_decode` pair.

Besides, using a library does not close the holes by itself. The `alg: none` attack lived **inside libraries** for years, and today it lives on in code that calls them wrongly (`decode($t, $k, ['HS256','none'])`). Knowing what you are doing does not replace a library, but without it a library will not save you either.
</details>

<details>
<summary><b>Why is there no refresh token?</b></summary>

A deliberate scope decision. Refresh tokens are a topic of their own: storage (HttpOnly cookies), rotation, reuse detection, revocation lists. Adding all of it would have buried the one thing this example is about — verifying an access token.

The pattern in practice: a short-lived access token in memory, a long-lived refresh token in an HttpOnly cookie JavaScript cannot read; when the access token expires, the refresh token buys a new one.
</details>

<details>
<summary><b>How do I revoke a token?</b></summary>

You can't do it directly — and that is the nature of JWT. The token is not stored server-side; verification only looks at the signature. A "log out" button cannot invalidate it.

You have three options:

1. **A short lifetime** (900 s here) — a stolen token lives that long at most
2. **Rotating `JWT_SECRET`** — cuts off **every** token in circulation instantly
3. **`active = 0`** — stops new tokens; the existing one lives until `exp`

If you need per-token revocation, add a blacklist table keyed on the `jti` claim — but at that point every request hits the database and JWT's stateless advantage is gone. The `jti` field exists from day one precisely so that step can be taken without breaking tokens already in circulation.
</details>

<details>
<summary><b>Can't I just put the token in localStorage?</b></summary>

You can, but take the risk knowingly: **any** XSS hole on the page can read `localStorage` and exfiltrate the token. The attacker then acts as the user for the token's entire lifetime.

In this console the token lives in an ordinary JavaScript variable and disappears when the page reloads. That is not a shortcoming but a deliberate trade-off — a demo tool has no business keeping a persistent session.
</details>

<details>
<summary><b>The rate limiter writes to a file. Does that scale?</b></summary>

On a single server, yes. Across several servers, **no**: each server counts its own share and the effective limit multiplies by N. In that case the counter belongs somewhere shared (Redis, Memcached).

A file was chosen on purpose: this example has no dependencies and should run without requiring Redis. It uses a sliding window — a fixed window (a counter reset at the top of each minute) allows twice the limit: 180 requests at second 59 and 180 more at second 61.
</details>

<details>
<summary><b>Every request returns 401 even though my token is fine</b></summary>

Most likely the `Authorization` header never reaches PHP. Some Apache/CGI setups (SAPIs other than mod_php) drop it, and the code genuinely sees it as empty.

`api/.htaccess` forwards the header two different ways (`SetEnvIf` and `mod_rewrite`), and `bearer_token()` tries three server variables. If it still fails, make sure your `.htaccess` files are being read at all (`AllowOverride All`).
</details>

<details>
<summary><b>How do I restrict CORS?</b></summary>

`api/index.php` sends `Access-Control-Allow-Origin: *` at the top. That is deliberate for a public demo. In your own project, restrict the origin:

```php
$allowed = ['https://myapp.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
```

If you enable `Allow-Credentials`, using `*` is forbidden anyway.
</details>

---

## Going to Production

- [ ] `system/config.local.php` created; the **production `JWT_SECRET`** and database credentials live there
- [ ] `JWT_SECRET` is at least 32 random bytes (`bin2hex(random_bytes(32))`)
- [ ] `DEMO_TOKENS` is **`false`**
- [ ] Demo keys (`demo_full`, `demo_readonly`, `demo_writer`, `demo_pasif`) **deleted** or set to `active = 0`
- [ ] Your own API keys added, with secrets hashed via `password_hash()`
- [ ] `APP_DEBUG` off (it switches itself off on a live domain — verify anyway)
- [ ] `Access-Control-Allow-Origin` restricted
- [ ] Is `JWT_TTL` right for you? (short = safer, long = fewer requests)
- [ ] `RATE_LIMIT_*` tuned to your traffic
- [ ] HTTPS enforced — the token travels in a plaintext header
- [ ] `/cy_api.sql`, `/system/config.php` and `/CHANGELOG.md` all return **403**
- [ ] Do you need `index.php` and `assets/js/console.js` in production? If not, delete them

---

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Every request returns `401`, token is fine | The `Authorization` header never reaches PHP | `AllowOverride All`; is `api/.htaccess` being read? |
| `/api/notes` → 404 but `/api/index.php?path=/notes` works | `mod_rewrite` disabled | Enable the module or use the `?path=` form |
| I get `403` but my token is brand new | Missing scope | Read `details.required`; **a new token will not help** |
| Turkish characters are mangled | The `.sql` file was imported with the wrong charset | `mysql --default-character-set=utf8mb4 < cy_api.sql` |
| `SQLSTATE[HY093]` | The same named placeholder used twice | With `EMULATE_PREPARES = false` names cannot repeat; use `:q1`, `:q2` |
| Constant `429` | Stale rate-limit counter files | Delete the `sys_get_temp_dir()/cy_api_rate` directory |
| Console says the demo token could not be minted | `DEMO_TOKENS = false` | Expected behaviour; it is off in production |
| `db_unavailable` | Wrong database credentials | Check the `DB_*` values in `system/config.local.php` |

---

## Roadmap

- [ ] Refresh token flow (HttpOnly cookie + rotation)
- [ ] Per-token revocation via a `jti` blacklist
- [ ] RS256 support (asymmetric signing — verifiers need no secret)
- [ ] Redis-backed rate-limit driver
- [ ] OpenAPI (Swagger) definition
- [ ] Key management interface

---

## Contributing

Contributions are welcome.

1. Fork the repository
2. Create a branch: `git checkout -b feature/great-thing`
3. Commit your changes: `git commit -m 'Add a great thing'`
4. Push the branch: `git push origin feature/great-thing`
5. Open a pull request

For bug reports and suggestions, use the [Issues](https://github.com/CilginYazilim/PHP-MySQL-REST-API-JWT-Jeton-Kapsam-Hiz-Siniri/issues) section.

---

## License

MIT — see [LICENSE](LICENSE). Free to use in commercial projects too.

---

<div align="center">

**[Çılgın Yazılım](https://cilginyazilim.com)** · [Library](https://cilginyazilim.com/kutuphane) · [GitHub](https://github.com/CilginYazilim)

If you found this example useful, consider leaving a ⭐.

</div>
