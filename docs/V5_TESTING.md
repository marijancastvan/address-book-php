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
