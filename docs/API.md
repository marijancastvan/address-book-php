# Address Book API v1

## API tokeni

Prijavljeni korisnik otvara **API tokeni**, unosi naziv (1–100 Unicode znakova) i kreira token. Token važi 30 dana. Pun token se prikazuje samo na sledećem prikazu nakon kreiranja; kopirajte ga tada. Baza čuva samo SHA-256 hash. Kasnije se prikazuju samo naziv, status i datumi. Opoziv je odmah važeći.

Token šaljite isključivo u `Authorization: Bearer ...` zaglavlju preko HTTPS-a. Nemojte stavljati token u URL, logove, issue-e ili deljeni shell history. Lokalni izuzetak za HTTP važi samo kada je zahtev zaista stigao preko loopback peer-a i loopback hostname-a (`localhost`, `127.0.0.1` ili `[::1]`). API ne koristi session cookie i ne prihvata `X-Forwarded-Proto` za utvrđivanje HTTPS-a.

## Probni endpoint

`GET /api/v1/me.php` vraća ID korisnika koji je utvrđen iz važećeg Bearer tokena:

```json
{"data":{"user_id":123}}
```

Greške koriste oblik `{"error":{"code":"...","message":"..."}}`. Odgovori su `application/json` i `Cache-Control: no-store`. Endpoint ne podržava CORS.

## Kontakti — read-only API

Endpoint-i su direktni PHP fajlovi, bez rewrite pravila:

- `GET /api/v1/contacts.php` — lista.
- `GET /api/v1/contact.php?id=123` — jedan kontakt, aktivan ili obrisan.

Oba zahtevaju Bearer token. Session cookie ne autentifikuje API. Odgovor liste ima oblik:

```json
{
  "data": [
    {
      "id": 123,
      "first_name": "Ana",
      "last_name": "Petrović",
      "phone": "+381 64 123 4567",
      "email": "ana@example.com",
      "city": {"id": 7, "name": "Novi Sad"},
      "tags": [{"id": 3, "name": "Klijent"}],
      "created_at": "2026-10-20 10:30:00",
      "deleted_at": null
    }
  ],
  "meta": {"page": 1, "per_page": 25, "total": 1, "total_pages": 1}
}
```

Pojedinačni odgovor koristi `{"data": { ...kontakt... }}`. Vraćaju se originalni kontakt podaci, grad i tagovi sa ID-jevima i nazivima; interni `phone_normalized` i `email_normalized` ključevi se ne vraćaju.

Lista podrazumevano obuhvata aktivne kontakte. `view=deleted` bira obrisane. Podržani filteri su `search` (trimovani delimični tekst za ime, prezime, telefon ili email), `city_id`, `tag_id`, `date_from` i `date_to` (`YYYY-MM-DD`). Filteri se kombinuju; datum od ne sme biti posle datuma do. `sort` prihvata `first_name`, `last_name`, `phone` ili `email`; `direction` prihvata `asc` ili `desc`. Podrazumevane vrednosti su `first_name` i `asc`. Sortiranje ima stabilne dodatne kriterijume, uključujući ID.

`page` počinje od 1. `per_page` podrazumevano iznosi 25, a dozvoljen je raspon 1–100. `total` i `total_pages` računaju se sa istim vlasničkim, statusnim i filter uslovima kao lista; kontakt sa više izabranih tagova se ne duplira. Kada je `page` izvan opsega, API vraća prazno `data`, bez promene tražene vrednosti `meta.page`; za nula rezultata `total_pages` je 0. Neispravan tip parametra (uključujući `search[]=...` umesto jednog stringa), ID, datum, sortiranje ili paginacija vraća `400` JSON sa kodom `invalid_parameter` i porukom na srpskom. Nevažeći ili tuđi ID pojedinačnog kontakta vraća isti `404 contact_not_found`; neispravan ID format vraća `400`.

Vremenska polja se vraćaju kao `YYYY-MM-DD HH:MM:SS`, bez automatske vremenske konverzije. `created_at` je postojeći MySQL `TIMESTAMP` i vraća se u vremenskoj zoni MySQL konekcije/sesije; aplikacija API-ja je ne menja. `deleted_at` je postojeći `DATETIME`, upisan eksplicitno preko `UTC_TIMESTAMP()`, pa njegova vrednost predstavlja UTC. API ne dodaje oznaku zone niti menja postojeće značenje ovih podataka.

## Lokalni poziv

Pokrenite PHP development server iz korena projekta:

```powershell
php -S 127.0.0.1:8000 -t public
```

U primerima zamenite placeholder svojim jednokratno prikazanim tokenom. Nemojte čuvati stvarni token u repozitorijumu.

```powershell
$token = "PASTE_ONE_TIME_TOKEN_HERE"
$headers = @{ Authorization = "Bearer $token" }
Invoke-RestMethod -Method Get -Uri "http://127.0.0.1:8000/api/v1/me.php" -Headers $headers
```

Isti zahtev preko `curl.exe`:

```powershell
curl.exe -i -H "Authorization: Bearer PASTE_ONE_TIME_TOKEN_HERE" "http://127.0.0.1:8000/api/v1/me.php"
```

## Hosting putanja

Poziv se gradi iz hosting `app.base_url` vrednosti. Ako je ona, na primer, `https://oculus.rs/marijan/address-book-php-hosting/public`, endpoint je:

```text
https://oculus.rs/marijan/address-book-php-hosting/public/api/v1/me.php
```

PowerShell:

```powershell
$token = "PASTE_ONE_TIME_TOKEN_HERE"
$headers = @{ Authorization = "Bearer $token" }
Invoke-RestMethod -Method Get -Uri "https://oculus.rs/marijan/address-book-php-hosting/public/api/v1/me.php" -Headers $headers
```

`curl.exe`:

```powershell
curl.exe -i -H "Authorization: Bearer PASTE_ONE_TIME_TOKEN_HERE" "https://oculus.rs/marijan/address-book-php-hosting/public/api/v1/me.php"
```

### Plesk status: Authorization zaglavlje

Lokalna Bearer autentifikacija radi, ali potvrđeni Plesk hosting trenutno ne prosleđuje standardno `Authorization` zaglavlje PHP-u. `CGIPassAuth On` u `public/api/.htaccess` izazvao je HTTP 500 i uklonjen je. Ne vraćajte tu direktivu niti šaljite tokene kroz query string ili alternativno zaglavlje. Hosting administrator mora podesiti dozvoljeno Apache/PHP-FPM prosleđivanje standardnog Authorization zaglavlja. Dok to nije rešeno, hosting API Bearer endpoint-i neće moći da autentifikuju zahteve. Nije izvršen hosting test ove read-only API faze.

Primeri PowerShell `curl.exe` poziva za API kontakata nalaze se ispod; token placeholder zamenite samo lokalno u terminalu i nikada ga ne stavljajte u URL, testni fajl ili deljeni log.

```powershell
$baseUrl = "https://oculus.rs/marijan/address-book-php-hosting/public/api/v1"
$token = "PASTE_VALID_TOKEN_LOCALLY"
curl.exe -i -H "Authorization: Bearer $token" "$baseUrl/contacts.php?per_page=25&page=1"
```

Lokalni pozivi rade preko loopback HTTP izuzetka. Primeri su u delu „Lokalni API testovi“ ispod.

## Lokalni API testovi sa PowerShell/curl.exe

Pokrenite PHP server nad posebnom test kopijom aplikacije i koristite test token koji pripada test nalogu:

```powershell
$baseUrl = "http://127.0.0.1:8000/api/v1"
$token = "PASTE_TEST_TOKEN_LOCALLY"
$auth = "Authorization: Bearer $token"
curl.exe -i -H $auth "$baseUrl/contacts.php?search=Ana&city_id=1&tag_id=1&date_from=2026-01-01&date_to=2026-12-31&sort=last_name&direction=desc&page=1&per_page=25"
curl.exe -i -H $auth "$baseUrl/contact.php?id=1"
curl.exe -i -H "Authorization: Bearer test" "$baseUrl/contacts.php"
curl.exe -i "$baseUrl/contacts.php"
curl.exe -i -X POST -H $auth "$baseUrl/contacts.php"
```

Očekivanja: lista i vlasnički pojedinačni prikaz vraćaju `200`; `Bearer test` vraća `401 invalid_token`; zahtev bez tokena vraća `401 authentication_required`; `POST` vraća `405` sa `Allow: GET`. Opozvani token takođe vraća `401 invalid_token`. Tuđi/nepostojeći kontakt vraća isti `404 contact_not_found`; neispravan ID i neispravni filteri vraćaju `400 invalid_parameter`. Svi odgovori su JSON i imaju `Cache-Control: no-store`.
