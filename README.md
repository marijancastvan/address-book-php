# ADDRESS BOOK – PHP CLONE

Edukativni PHP klon Address Book aplikacije.

## Tehnologije

- PHP 8.5.11
- HTML, CSS i JavaScript
- MySQL preko PDO (konekcija je pripremljena; baza i tabele još nisu kreirane)

## Lokalno pokretanje

Iz korena projekta pokrenite PHP development server:

```sh
php -S localhost:8000 -t public
```

Zatim otvorite <http://localhost:8000>.

Za lokalnu PDO konfiguraciju kopirajte `app/config/config.example.php` u
`app/config/config.local.php` i unesite lokalne MySQL podatke. Lokalni fajl je
isključen iz Git-a; ne commit-ujte lozinke niti druge tajne.

## Struktura projekta

```text
app/
  bootstrap.php
  config/
    config.example.php
  database/
    migrations/
  views/
public/
  index.php
  assets/
    css/
    js/
tests/
```

`public/` je web root. `app/bootstrap.php` centralizuje konfiguraciju i
priprema PDO konekciju koja se uspostavlja tek kada se zatraži.
