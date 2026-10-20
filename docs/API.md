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

### Prosleđivanje Authorization zaglavlja na Apache/Plesk-u

`public/api/.htaccess` uključuje Apache direktivu `CGIPassAuth On` samo za API direktorijum. Ona prosleđuje standardno `Authorization` zaglavlje CGI/FastCGI aplikaciji, gde ga postojeći API helper može pročitati. Ne menja PHP autentifikaciju ni session login i ne podešava nginx.

`CGIPassAuth` zahteva Apache 2.4.13 ili noviji, a hosting konfiguracija mora dozvoliti ovu direktivu u `.htaccess` kroz `AllowOverride` pravila. Ako nakon objave dobijete HTTP 500, uklonite dodatu direktivu i proverite Apache error log; nemojte prikrivati nepodržanu direktivu uslovnim pravilom.

Nakon objave proverite postojeći endpoint `GET /api/v1/me.php` preko HTTPS-a. Namerno nevažeći literal `test` mora biti prepoznat kao poslati Bearer token i vratiti `401` sa JSON kodom `invalid_token` (a ne `authentication_required`). Zahtev bez Authorization zaglavlja mora vratiti `401` sa kodom `authentication_required`. Važeći, neopozvani hosting token mora vratiti `200` i ID vlasnika tokena. Nakon opoziva tokena kroz interfejs, isti zahtev mora vratiti `401` sa kodom `invalid_token`. Token koristite samo lokalno; nikada ga ne stavljajte u URL, repozitorijum ili deljeni log.

Hosting provera još nije izvršena. Postojeći API helper čita `HTTP_AUTHORIZATION`, `REDIRECT_HTTP_AUTHORIZATION` i standardni rezultat `getallheaders()`; aplikacija namerno ne prihvata proizvoljna `X-*` zaglavlja kao zamenu.

Primeri provere sa `curl.exe` (placeholder važećeg tokena zamenite samo u svom lokalnom terminalu; nemojte zapisivati stvarnu vrednost u fajl):

```powershell
$url = "https://YOUR_HOSTING_BASE_URL/api/v1/me.php"
curl.exe -i -H "Authorization: Bearer test" $url
curl.exe -i $url
curl.exe -i -H "Authorization: Bearer PASTE_VALID_TOKEN_LOCALLY" $url
# Nakon opoziva tokena ponovite prethodni zahtev; očekuje se 401 invalid_token.
```
