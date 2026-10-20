# Address Book API v1

## API tokeni

Prijavljeni korisnik otvara **API tokeni**, unosi naziv (1–100 Unicode znakova) i kreira token. Token važi 30 dana. Pun token se prikazuje samo na sledećem prikazu nakon kreiranja; kopirajte ga tada. Baza čuva samo SHA-256 hash. Kasnije se prikazuju samo naziv, status i datumi. Opoziv je odmah važeći.

Token šaljite isključivo u `Authorization: Bearer ...` zaglavlju preko HTTPS-a. Nemojte stavljati token u URL, logove, issue-e ili deljeni shell history. Lokalni izuzetak za HTTP važi samo kada je zahtev zaista stigao preko loopback peer-a i loopback hostname-a (`localhost`, `127.0.0.1` ili `[::1]`). API ne koristi session cookie i ne prihvata `X-Forwarded-Proto` za utvrđivanje HTTPS-a.

## Probni endpoint

Trenutno postoji samo `GET /api/v1/me.php`. Vraća ID korisnika koji je utvrđen iz važećeg Bearer tokena:

```json
{"data":{"user_id":123}}
```

Greške koriste oblik `{"error":{"code":"...","message":"..."}}`. Odgovori su `application/json` i `Cache-Control: no-store`. Endpoint ne podržava CORS.

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

Ako Plesk/PHP-FPM ne prosleđuje standardno `Authorization` zaglavlje aplikaciji, podešavanje servera mora ga izložiti kao `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` ili kroz standardni `getallheaders()` API. Aplikacija namerno ne prihvata proizvoljne `X-*` zamene.
