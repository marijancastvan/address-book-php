# V5 Testing

## V5.1 — Sortiranje kontakata

1. Otvorite **Kontakti** bez parametara sortiranja. Očekivani početni redosled je ime rastuće, zatim prezime rastuće, pa ID rastuće; zaglavlje Ime prikazuje `aria-sort="ascending"` i strelicu nagore.
2. Kliknite na **Ime** ponovo i proverite opadajući redosled, indikator i `aria-sort="descending"`. Klikom na drugo zaglavlje proverite da njegov prvi izbor bude rastući; ponovni klik menja ga na opadajući.
3. Ponovite uz **Prezime**, **Telefon** i **Email**. Telefon se poredi kao tekstualna vrednost; na primer `+10` dolazi pre `+2` u rastućem redosledu.
4. Dodajte najmanje 26 kontakata sa ponovljenim imenima/prezimenima i jednakim vrednostima telefona/emaila. Proverite da sekundarni kriterijumi i ID daju stabilan redosled, uključujući granicu između stranica 1 i 2, bez ponovljenih ili izostavljenih kontakata.
5. Isprobajte sortiranje uz pretragu i kombinaciju grad/tag/datumske filtere. Očekujte da filteri ostanu aktivni, broj rezultata odgovara filtriranom skupu, a paginacija zadrži sort i smer.
6. Na stranici 2 otvorite izmenu, sačuvajte kontakt i proverite povratak na istu stranicu, uz iste filtere, kolonu sortiranja i smer. Otvorite istoriju, pa **← Kontakti**; očekujte isti kontekst.
7. Sa uključenim JavaScript-om promenite sortiranje, pređite stranicu, pa koristite Back/Forward. Očekujte da se URL, zaglavlje, smer i rezultati vrate u odgovarajuće stanje. Sa isključenim JavaScript-om klikovi na zaglavlja treba da rade kao obični GET linkovi.
8. Ručno otvorite URL sa nepoznatim `sort` ili neispravnim `direction`. Očekujte bezbedan podrazumevani izbor: `first_name ASC`, `last_name ASC`, `id ASC`, bez SQL greške.

## V5.2 — Soft delete i vraćanje kontakata

### Lokalna migracija

Primenu radite isključivo u namenskoj privremenoj lokalnoj bazi. Nemojte koristiti bazu iz `config.local.php`, glavnu lokalnu bazu niti hosting bazu.

1. Napravite praznu bazu, na primer `address_book_v52_test`, sa `utf8mb4` i `utf8mb4_unicode_ci`.
2. U nju primenite redom `001_create_initial_tables.sql`, `002_create_password_reset_tokens.sql`, `003_create_tags_and_contact_tags.sql`, `004_add_contact_created_at_filter_index.sql`, `005_create_contact_history.sql` i `006_add_contact_soft_delete.sql`. Iz PowerShell-a pozovite `cmd.exe` za MySQL input redirekciju; pokrenite komande iz korena projekta:

   ```powershell
   cmd.exe /c "mysql -u TEST_USER -p address_book_v52_test < app\database\migrations\001_create_initial_tables.sql"
   cmd.exe /c "mysql -u TEST_USER -p address_book_v52_test < app\database\migrations\002_create_password_reset_tokens.sql"
   cmd.exe /c "mysql -u TEST_USER -p address_book_v52_test < app\database\migrations\003_create_tags_and_contact_tags.sql"
   cmd.exe /c "mysql -u TEST_USER -p address_book_v52_test < app\database\migrations\004_add_contact_created_at_filter_index.sql"
   cmd.exe /c "mysql -u TEST_USER -p address_book_v52_test < app\database\migrations\005_create_contact_history.sql"
   cmd.exe /c "mysql -u TEST_USER -p address_book_v52_test < app\database\migrations\006_add_contact_soft_delete.sql"
   ```

3. Proverite `SHOW COLUMNS FROM contacts LIKE 'deleted_at';` i `SHOW INDEX FROM contacts WHERE Key_name = 'idx_contacts_user_deleted_at';`. Očekuje se nullable `DATETIME` kolona i kompozitni indeks `(user_id, deleted_at)`.
4. Za UI test napravite zasebnu, privremenu kopiju projekta i podesite samo njen lokalni, Git-ignorisan `app/config/config.local.php` da koristi `address_book_v52_test`. Sačuvajte postojeći lokalni config i ne kopirajte testne tajne u Git. Pokrenite PHP server iz te kopije. Po završetku obrišite kopiju i celu privremenu bazu.

### Ručno testiranje

1. Napravite kontakt sa gradom i dva taga. Zabeležite ID i podatke kontakta, veze u `contact_tags` i broj istorijskih događaja.
2. Na aktivnoj listi kliknite **Izbriši**. Očekujte poruku da kontakt ide u **Obrisane kontakte** i može da se vrati. Potvrdite: kontakt nestaje iz aktivne liste i live search/COUNT-a, ali se pojavljuje u obrisanim kontaktima.
3. U obrisanoj listi proverite da kontakt, grad i tagovi prikazuju iste vrednosti. Otvorite istoriju: vlasnik i dalje može da je vidi, a novi događaj prikazuje brisanje sa izvršiocem i vremenom u Europe/Belgrade.
4. Kliknite **Vrati kontakt** i potvrdite. Očekujte povratak u aktivne kontakte sa istim ID-jem, poljima, tagovima i istorijom; obrisani pregled više ga ne prikazuje.
5. Direktno pošaljite POST na `contact-edit.php` za obrisani ID (sa važećim nalogom i CSRF tokenom). Očekuje se odbijanje i nepromenjeni podaci.
6. Ponovite POST brisanja nad već obrisanim kontaktom i vraćanja nad aktivnim kontaktom. Status ostaje isti i broj `contact_deleted`/`contact_restored` događaja se ne povećava.
7. Pokušajte brisanje tuđeg ID-ja i POST bez CSRF tokena ili sa neispravnim tokenom. Očekuje se odbijanje bez promene statusa i bez istorijskog događaja.
8. U privremenoj bazi izazovite grešku pri upisu u `contact_history_events`, pa pokušajte brisanje i vraćanje. Očekuje se rollback: `deleted_at` i broj događaja ostaju kao pre zahteva. Uklonite testni mehanizam greške odmah nakon provere.
9. Dok je kontakt obrisan, pokušajte da obrišete njegov grad. Baza i dalje blokira brisanje; poruka objašnjava da su obuhvaćeni i obrisani kontakti. Proverite i postojeće izmene/brisanje taga: kontakti i istorija ostaju dostupni, a uklanjanje taga i dalje uklanja njegove veze prema postojećim pravilima.
10. Sa najmanje 26 obrisanih kontakata proverite 25 po stranici, stabilno sortiranje i granicu stranica. Ponovite sa aktivnim kontaktima i kombinacijom pretrage, grada, taga i datuma. URL, V5.1 sortiranje i filteri treba da ostanu sačuvani kroz paginaciju i povratak iz istorije/izmene.
11. Prijavite se kao drugi korisnik. Njegov pregled obrisanih kontakata ne sme prikazati kontakte prvog korisnika; POST vraćanja tuđeg ID-ja mora biti odbijen.

## V5.3 — Normalizacija i pregled duplikata

### Lokalni test: backup → migracija → backfill → provera

Izvršavajte samo u namenskoj privremenoj lokalnoj bazi. Ne koristite glavnu lokalnu niti hosting bazu.

1. Napravite rezervnu kopiju baze pre izmena. Za novu privremenu bazu kopija nije potrebna, ali je i dalje primenite u praznu bazu redom kroz migracije `001`–`007`.
2. Za test postojećih zapisa napravite izolovanu privremenu kopiju šeme/podataka iz lokalne baze, pa usmerite posebnu kopiju projekta na nju preko njenog Git-ignorisanog `app/config/config.local.php`. Nikad ne menjajte postojeći config.
3. Primena redom: `001_create_initial_tables.sql`, `002_create_password_reset_tokens.sql`, `003_create_tags_and_contact_tags.sql`, `004_add_contact_created_at_filter_index.sql`, `005_create_contact_history.sql`, `006_add_contact_soft_delete.sql`, `007_add_contact_duplicate_keys.sql`. U PowerShell-u koristite `cmd.exe /c "mysql ... < app\database\migrations\NNN_....sql"` iz korena projekta.
4. Proverite `SHOW COLUMNS FROM contacts LIKE 'phone_normalized';`, `SHOW COLUMNS FROM contacts LIKE 'email_normalized';` i `SHOW INDEX FROM contacts WHERE Key_name IN ('idx_contacts_user_phone_normalized', 'idx_contacts_user_email_normalized');`. Ključevi treba da budu nullable VARBINARY, bez UNIQUE indeksa; email indeks koristi prefiks od 191 bajta.
5. Pokrenite backfill samo iz CLI-a na toj privremenoj bazi: `php scripts/backfill-contact-duplicate-keys.php --batch-size=250`. Izveštaj sadrži samo brojeve obrađenih/ažuriranih/redova i praznih/nepodržanih ključeva, nikad lične podatke.
6. Pokrenite istu komandu drugi put. Svi redovi treba da budu obrađeni, a ažuriranih treba da bude 0 ako se podaci nisu promenili. Originalna `phone` i `email` polja moraju ostati neizmenjena. Proverite deo redova poređenjem ključeva sa pravilima ispod.
7. Po završetku ugasite test server i uklonite privremenu bazu, kopiju projekta i sesije.

### Ručno testiranje

1. Isti međunarodni telefon u formatima `+381 64 123-4567` i `00381 (64) 123.4567` treba da bude prijavljen kao poklapanje. Lokalni `064 123-4567` ne treba da se poklopi sa tim međunarodnim brojem bez poznatog države.
2. Prazan telefon/email i nepodržan telefon kao `pozovi-me-123` ne treba da proizvode poklapanja. Tekst se ne sme pretvoriti u broj uklanjanjem svih znakova.
3. Emailovi ` Ana.Primer+posao@example.com ` i `ana.primer+posao@example.com` treba da se poklope. Adrese koje se razlikuju po tački ili `+suffix` ostaju različite.
4. Napravite parove koji se poklapaju samo po telefonu, samo po emailu i po oba; razlog treba da bude tačan. Napravite A–B sa telefonskim poklapanjem i B–C sa email poklapanjem; prikaz treba da sadrži dva nezavisna para i ne sme tvrditi da A i C dele podatak.
5. Isti normalizovani ključevi kod drugog korisnika ne smeju se pojaviti u vašem pregledu. Obrisani kontakt ne sme biti kandidat; vraćeni kontakt ponovo ulazi u pregled bez gubitka ključeva.
6. Izmenite telefon/email aktivnog kontakta. Originalna formatirana vrednost treba da ostane prikazana, normalizovani ključ treba da se promeni u istoj DB izmeni, a stara veza duplikata treba da nestane/nova da se pojavi. Neuspeh transakcije ne sme razdvojiti originalnu i normalizovanu vrednost.
7. Generator treba da popuni ključeve za sve kreirane kontakte. Ne pokreće se backfill kroz HTTP.
8. Napravite više od 25 parova i proverite paginaciju: svaki par je prikazan jednom, sortiranje stabilno, a stranice nemaju preklapanja. Neispravan ili prevelik `duplicates_page` treba bez greške da bude sveden na validnu stranicu.
9. Proverite da pregled koristi samo kontakte aktivnog korisnika; nazivi, telefoni i emailovi moraju biti HTML-escaped.

### Hosting redosled

Pošto Git push automatski objavljuje kod, prvo napravite i proverite hosting backup. Zatim, **pre push-a**, primenite SQL iz migracije 007 nad hosting bazom preko odobrenog DB alata (npr. Plesk/phpMyAdmin), jer novi kod očekuje obe kolone. Posle potvrde migracije push objavljuje kod. Zatim pokrenite `scripts/backfill-contact-duplicate-keys.php` kroz Plesk CLI/SSH ili drugi odobreni, privatni CLI pristup i proverite izveštaj. Ako hosting nema CLI, dogovorite CLI pristup sa hosting podrškom ili administratorom; do backfill-a stari kontakti nemaju ključeve i zato neće biti uključeni u pregled. Ne pravite javni ili nezaštićeni backfill endpoint. Ne testirajte migraciju/backfill na produkciji; produkcioni koraci su samo plan objavljivanja nakon lokalnog testiranja i odobrenog backupa.
