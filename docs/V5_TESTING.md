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

## V5.3.1 — Ručno spajanje dva duplirana kontakta

Spajanje nema novu migraciju. Testirajte u zasebnoj privremenoj lokalnoj bazi sa šemom `001`–`007`; ne koristite glavnu lokalnu bazu ni hosting. Važna otvorena stavka: backfill starih kontakata na hostingu još nije izvršen. Novi kontakti, izmene i generator popunjavaju normalizovane ključeve automatski, ali stari hosting kontakti bez ključeva neće se pojaviti kao duplikati dok se privatni CLI backfill ne izvrši.

### Pregled i uspešno spajanje

1. Napravite par koji se poklapa samo po telefonu, drugi samo po emailu i treći po oba. Na stranici **Duplikati** svaki par treba da ima dugme **Spoji**.
2. Otvorite pregled spajanja. Proverite zajedničku levu navigaciju i svetloplavi stil sekcije Kontakti. ID i datum kreiranja izabranog preostalog kontakta moraju ostati isti.
3. Proverite da su ime, prezime, telefon, email i grad inicijalno izabrani sa kontakta koji ostaje. Menjajte izvore polja i potvrdite da se „Konačan rezultat“ menja pre potvrde. Promena kontakta koji ostaje ne sme sama promeniti izbore; dugme **Postavi sva polja na kontakt koji ostaje** namerno postavlja svih pet izbora na njega.
4. Dodelite tag A prvom kontaktu i tag B drugom. Spojite ih. Aktivni kontakt treba da ima oba taga tačno jednom; drugi kontakt prelazi u **Obrisani kontakti** sa svojim originalnim tagovima i istorijom.
5. Proverite spajanje sa svakim kontaktom kao preostalim i kombinacijom polja sa oba izvora. Tagovi se uvek objedinjuju.
6. Proverite istoriju oba kontakta. Na preostalom se očekuje događaj **Spajanje kontakata — preostao kontakt** sa samo stvarno promenjenim poljima/tagovima i opisom ID-jeva. Na uklonjenom se očekuje **Spajanje kontakata — uklonjen iz aktivnih**, status i isti opis para. Događaj spajanja mora postojati i kada su sva polja i tagovi već isti.
7. Vratite uklonjeni kontakt iz pregleda obrisanih. Očekuje se zaseban aktivan kontakt sa originalnim ID-jem, podacima, tagovima i istorijom; izmene preostalog kontakta ostaju sačuvane, a pregled duplikata može ponovo prikazati par.
8. Proverite **Otkaži** i povratni link: oba vode na istu stranicu pregleda duplikata i zadržavaju filtere, sortiranje i stranicu kontakata. GET pregled, otkazivanje i povratak ne smeju menjati bazu.

### Odbijeni i zastareli zahtevi i transakcija

1. Pokušajte spajanje tuđeg kontakta, obrisanog kontakta, istog ID-ja dvaput, nepostojećeg ID-ja, izbora izvora van para i POST-a bez/neispravnog CSRF tokena. Zahtev mora biti odbijen, a kontakt, tagovi i istorija ostaju nepromenjeni.
2. Otvorite pregled, pa u drugoj sesiji promenite polje, grad, naziv taga ili vezu taga. Potvrdite staru formu: spajanje mora biti odbijeno kao zastarelo, prikazuje se osvežen pregled i potrebna je nova potvrda.
3. Promenite telefon/email tako da par više ne ispunjava pravila V5.3. Stara potvrda mora biti odbijena. Ponovite POST posle uspešnog spajanja: ne sme nastati novo spajanje ni duplirani događaj.
4. U izolovanoj bazi izazovite grešku pri `INSERT`-u u `contact_history_events` (npr. privremenim testnim triggerom), pošaljite merge i proverite rollback: preostali kontakt, njegove tag veze i `deleted_at` drugog kontakta moraju ostati nepromenjeni, bez delimične istorije. Uklonite trigger odmah nakon testa.
5. Posle uspeha proverite da spojeni par nestaje iz Duplikata, a druga poklapanja sa preostalim kontaktom ostaju kao nezavisni parovi sa svojim razlozima.

### Ručne HTTP i responsive provere

1. Prijavite se kao dva korisnika i pokušajte direktno otvoriti/POST-ovati ID-jeve drugog korisnika. Očekuje se nedostupan par bez promena.
2. Pošaljite POST bez CSRF tokena i sa izmenjenim tokenom. Statusi, tagovi i istorija ne smeju se promeniti.
3. Na desktopu i uskom telefonu proverite čitljivost izbora i pregleda, odsustvo horizontalnog prelivanja i raspored dugmeta **Spoji**.
4. Potvrdite da pretraga/filteri, V5.1 sortiranje, paginacija, live search, CRUD, vraćanje i istorija kontakta rade kao pre.

## V5.4 — Komentari kontakata

### Izolovana lokalna priprema

1. Napravite backup pre primene SQL-a. Za test koristite samo novu, privremenu lokalnu bazu i posebnu privremenu kopiju projekta; nikada ne koristite glavnu lokalnu ili hosting bazu.
2. U praznu privremenu bazu primenite migracije redom `001`–`008`, uključujući `008_create_contact_comments.sql`. Proverite da tabela `contact_comments` ima složene FK veze za vlasnika/kontakt i poreklo, kao i indeks `(user_id, contact_id, created_at, id)`.
3. Posebnu kopiju projekta usmerite na tu bazu preko njenog lokalnog, Git-ignorisanog `app/config/config.local.php`. Ne menjajte postojeći lokalni config. Pokrenite aplikaciju iz privremene kopije i uklonite bazu, kopiju i test sesije nakon provera.

### Ručni scenariji i očekivanja

1. U početnom i live-search prikazu proverite akciju **Komentari**. Otvorite je sa kontaktom koji ima komentare i sa kontaktom bez komentara. Početni prazan prikaz je kompaktan; stranica zadržava Kontakti sidebar, plavu paletu i malo teget dugme **← Kontakti**.
2. Dodajte tekst `Pozvan klijent, dogovoreno slanje ponude.` i proverite da autor potiče iz prijavljene sesije, vreme se upisuje kao UTC pa prikazuje u `Europe/Belgrade` formatu `HH:mm dd-mm-yyyy`, a tekst se prikazuje kao običan tekst.
3. Dodajte komentar sa HTML sadržajem `<script>alert(1)</script>` i više redova. Očekivano: HTML se prikaže kao tekst, skripta se ne izvrši, a prelomi redova ostanu vidljivi.
4. Pošaljite prazan/razmacima ispunjen sadržaj, 5000 Unicode znakova i 5001 Unicode znak. Prazan i 5001 znak se odbijaju bez INSERT-a; 5000 znakova se prihvata.
5. Pošaljite zahtev bez CSRF tokena i sa pogrešnim tokenom; nijedan komentar ne nastaje. Odjavljen korisnik se preusmerava na prijavu. Nalog B ne može pročitati niti dodati komentar kontaktu naloga A.
6. Napravite 26 komentara i proverite 25 po stranici, najnoviji prvi, zatim stabilan ID opadajuće za jednako vreme. Otvorite stranicu 2 i proverite povratni link zadržava search, grad/tag/datum filtere, sort/smer, pregled aktivnih/obrisanih i izvornu stranicu kontakata.
7. Zabeležite vreme UTC u bazi, uključujući datum zimi i leti, i proverite prikaz u Europe/Belgrade bez vidljive UTC oznake.
8. Soft-delete kontakt: komentari ostaju čitljivi, forma za dodavanje nije prikazana, a direktan POST odbija novi komentar. Vratite kontakt: isti komentari ostaju, a novi komentar može ponovo da se doda.
9. Dodajte komentare na oba kontakta, spojite ih i proverite da su sada dostupni na preostalom kontaktu uz originalnog autora, vreme, tekst i oznaku izvornog ID-ja. Istorija spajanja prikazuje broj prenetih komentara.
10. Spojite taj kontakt ponovo sa trecim kontaktom. Svaki komentar se pojavljuje samo jednom, a `origin_contact_id` ostaje isti. Vracanje ranije uklonjenog kontakta vraca ga kao zaseban kontakt i ne premesta komentare nazad.
11. U privremenoj bazi izazovite grešku upisa istorije pri spajanju. Očekivani rollback obuhvata kontaktna polja, tagove, status drugog kontakta, prenete komentare i istoriju.
12. Dodavanje komentara ne menja polja kontakta i ne stvara događaj u istoriji promena.

### Hosting napomene

Hosting backfill starih kontakata za V5.3 i dalje nije izvršen; novi/izmenjeni kontakti dobijaju normalizovane ključeve automatski. Za V5.4, pre objave napravite odobreni hosting backup i primenite migraciju 008 na hosting bazi pre objave koda, jer novi kod očekuje tabelu `contact_comments`. Zatim objavite kod i ručno proverite komentare. Ovo je samo redosled za kasnije odobreno objavljivanje; migraciju ne pokrećite produkciono tokom lokalnog testiranja.

## V5.5 — CSV import kontakata

### Priprema izolovane lokalne baze

Migracija 009 dodaje samo privatni prostor za kratkotrajni pregled importa. Ne primenjujte je na glavnu lokalnu ni hosting bazu tokom razvoja.

1. Napravite rezervnu kopiju i novu privremenu lokalnu bazu, na primer `address_book_v55_test`, sa `utf8mb4` i `utf8mb4_unicode_ci`.
2. U praznu privremenu bazu primenite redom `001_create_initial_tables.sql` kroz `009_create_contact_import_batches.sql`. Koristite samo test bazu i posebnu privremenu kopiju projekta sa sopstvenim, Git-ignorisanom `app/config/config.local.php`.
3. Proverite:

   ```sql
   SHOW CREATE TABLE contact_import_batches;
   SHOW INDEX FROM contact_import_batches;
   ```

   Očekuju se hash tokena, LONGTEXT staging payload, rok važenja, jedinstven token i indeksi za korisnika/rok i čišćenje isteklih pregleda. Tabela ne sadrži javnu putanju do fajla.
4. Pokrenite parser proveru iz korena projekta: `php tests/contact-import-parser.php`.
5. Po završetku uklonite samo privremenu kopiju i privremenu test bazu. Ne menjajte postojeći lokalni config.

Istekli staging redovi se brišu oportunistički pri sledećem zaštićenom zahtevu za import; ne postoji cron niti javni cleanup endpoint.

### Format CSV-a i Excel

Zaglavlje mora imati tačno pet kolona (redosled može biti promenjen):

```csv
first_name,last_name,phone,email,city
Mila,Petrović,0641234567,mila.petrovic@example.com,Zemun
Luka,Jovanović,+381641234568,luka.jovanovic@example.com,"Novi, Beograd"
```

Korisnik bira zarez ili tačka-zarez. Podržani su UTF-8 sa/bez BOM-a, standardna dvostruka navodila, escapovani navodnici i prelomi redova unutar navedenog polja. Prazni redovi se ignorišu. Telefon se obrađuje kao tekst.

Excel može ukloniti nule sa početka telefona ili promeniti `+` vrednost. Pre unošenja telefona formatirajte celu kolonu kao **Text/Tekst**. Pri uvozu CSV-a koristite Excel opciju za tekstualne podatke i eksplicitno izaberite **Text** za kolonu `phone`; nemojte otvarati pa ponovo snimati fajl ako je Excel već automatski pretvorio brojeve.

### Ručni UI i bezbednosni scenariji

1. Na **Kontakti** kliknite **Import CSV**. Proverite da su vidljiva ograničenja 2 MiB/1000 kontakata, izbor separatora, šablon i napomena o Excelu. Preuzmite šablon; treba da sadrži zaglavlje i dva izmišljena primera.
2. Učitajte zarez CSV sa BOM-om, unicode imenima, navodnicima, quoted zarezom/prelomom reda i telefonima `064...` i `+381...`. Ponovite sa tačka-zarezom i promenjenim redosledom kolona. Očekujte pregled koji prikazuje sve vrednosti ispravno i ne pravi nijedan kontakt ni grad.
3. Probajte prazan fajl, neispravan UTF-8, NUL sadržaj, neispravne navodnike, pogrešan broj polja, nedostajuću/dupliranu/nepoznatu kolonu, pogrešan separator, 2 MiB + 1 bajt i 1001 kontakt. Svaki slučaj treba da prikaže razumljivu poruku na srpskom; nijedan zapis se ne uvozi.
4. Proverite prazan naziv, naziv sa razmacima, neispravan email i svako obavezno kontakt polje. Greška treba da navede broj CSV zapisa i polje; dugme **Potvrdi import** ne treba da postoji dok greške nisu uklonjene. Ispravljen fajl učitajte ponovo.
5. Uvezite kontakte sa svojim postojećim gradom i proverite da nema kreiranja/dupliranja grada. Učitajte nepostojeći grad: pregled treba da ga navede i zahteva checkbox **Kreiraj nedostajuće gradove**. Bez checkbox-a direktan POST ne sme kreirati grad ni kontakte.
6. Stavite `Zemun`, `zemun` i postojeći `ZEMUN` u različite redove/varijante. Poređivanje prati `utf8mb4_unicode_ci`; nedostajući case-varijantni gradovi treba da se objedine, a ne postoje duplikati kod istog korisnika.
7. Dodajte u fajl dva reda sa istim normalizovanim telefonom, zatim emailom; dodajte i postojeći aktivni kontakt sa istim ključem. Pregled treba da upozori uz broj CSV zapisa i odgovarajući razlog. Tek uz checkbox za uvoz duplikata smeju svi redovi biti dodati; nijedan se automatski ne spaja ili preskače. Posle importa proverite postojeći pregled **Duplikati**.
8. Obrisani kontakti nisu aktivni duplikati i ne smeju izazvati upozorenje. Kontakti drugog korisnika nikad ne smeju biti uključeni u poređenje ili uvoz.
9. Otvorite dva importa u odvojenim tabovima; njihovi tokeni i redovi ostaju nezavisni. Otkazivanje jednog uklanja samo njegov staging. Sačekajte 30 minuta (ili u privremenoj bazi pomerite `expires_at` u prošlost): import treba da bude odbijen i staging očišćen.
10. Pošaljite upload, potvrdu i otkazivanje bez CSRF tokena i sa izmenjenim tokenom. Očekuje se odbijanje bez kreiranja kontakta/grada. Prijavite se kao korisnik B i pokušajte da otvorite/potvrdite token korisnika A; nema pristupa i nema upisa.
11. Posle uspešne potvrde ponovite isti POST i osvežite rezultat. Token je uklonjen pa nema drugog importa. Simulirajte dva paralelna POST-a: najviše jedan sme uspeti. Ako se lista gradova ili aktivni duplicate ključevi promene posle pregleda, prvo potvrđivanje treba osvežiti upozorenja i zahtevati novu potvrdu.
12. U privremenoj bazi izazovite grešku na drugom `INSERT` kontaktu (privremeni trigger). Potvrda mora uraditi rollback svih prethodnih kontakata, novih gradova i potrošnje tokena. Uklonite trigger odmah posle testa.
13. Proverite da novi kontakti imaju originalne tekstualne telefone i `phone_normalized`/`email_normalized`, da su vidljivi u aktivnoj listi i u postojećem pregledu duplikata. Ne nastaje događaj istorije niti se menjaju postojeći kontakti, tagovi ili komentari.
14. Pokušajte prikaz CSV vrednosti poput `<script>` ili HTML atributa. U pregledu se prikazuju kao tekst; ne izvršavaju se.

### Hosting redosled nakon lokalnog odobrenja

Hosting backfill starih kontakata za V5.3 i dalje nije izvršen. Pre budućeg push-a koji objavljuje V5.5: napravite odobreni backup, primenite migraciju 009 preko privatnog odobrenog DB alata, proverite tabelu/indekse, pa tek onda objavite kod. Ne menjajte hosting `config.local.php`, ne stavljajte CSV/staging fajlove u web root i ne pravite javni backfill/cleanup endpoint. U V5.5 istekli batch-evi se čiste kroz zaštićeni import tok; hosting CLI backfill starih kontakata ostaje zasebna otvorena stavka.

## V5.6.1 — API tokeni i Bearer autentifikacija

### Izolovana migracija

1. Napravite backup i novu privremenu lokalnu MariaDB bazu (npr. `address_book_v561_test`) uz posebnu privremenu kopiju projekta i Git-ignorisan lokalni config. Ne koristite glavnu lokalnu ni hosting bazu.
2. Primenite migracije `001`–`010` redom; proverite da `010_create_api_tokens.sql` prolazi na istoj MariaDB verziji kao hosting (cilj je MariaDB 5.5.68).
3. Proverite tabelu:

   ```sql
   SHOW CREATE TABLE api_tokens;
   SHOW INDEX FROM api_tokens;
   ```

   Hash mora imati jedinstveni `ascii_bin` ključ. Red treba da sadrži samo hash i metapodatke, nikada plaintext token.
4. Uklonite privremenu bazu/kopiju posle testiranja. Migracija se ne primenjuje na postojeću lokalnu/hosting bazu tokom ove faze.

### UI i baza

1. Kao korisnik A kreirajte token sa nazivom koji sadrži okolne razmake. Lista treba da prikazuje trimovan naziv, kreiranje i istek u Europe/Belgrade vremenu. Pun token se prikazuje samo na tom prikazu uz upozorenje; osvežavanje, povratak ili ponovno otvaranje ne sme ga prikazati ponovo.
2. U privremenoj bazi proverite da `token_hash = SHA2(<plaintext>, 256)` odgovara tokenu koji je privremeno zadržan u testu, a da plaintext nije upisan ni u jednu kolonu. Ne stavljajte token u testni izveštaj.
3. Probajte prazan naziv, samo Unicode razmake, 100 Unicode code point-a i 101 code point. Prazni/101 se odbijaju; validan naziv se trimuje i prihvata.
4. Korisnik B ne sme videti ni opozvati token korisnika A slanjem njegovog ID-ja. Opoziv zahteva POST i validan CSRF; ponovljen opoziv ne menja red. Nakon opoziva token mora odmah dobiti 401 na API-ju.
5. Očekivani rok važenja je 30 dana po UTC (`created_at`, `expires_at`, `revoked_at` su UTC DATETIME). Ručno pomerite `expires_at` u prošlost samo u privremenoj bazi i proverite UI status i API odbijanje.

### Probni API HTTP endpoint

Endpoint bez rewrite pravila: `GET /api/v1/me.php` (lokalno `http://127.0.0.1:8000/api/v1/me.php`, hosting URL je `app.base_url` + `/api/v1/me.php`; primeri su u `docs/API.md`). Pokrenite lokalni PHP server samo nad privremenom kopijom projekta/bazom.

1. Sa važećim tokenom očekujte `200 application/json`, `Cache-Control: no-store` i `{"data":{"user_id":...}}` za vlasnika tokena. Sačuvani session cookie bez Bearer zaglavlja mora i dalje dobiti `401`.
2. Bez tokena, pogrešnim formatom, nasumičnim tokenom, isteklim i opozvanim tokenom očekujte `401` JSON i `WWW-Authenticate: Bearer` challenge; ne sme se vratiti HTML ili redirect. Neispravan token ne sme otkriti korisnika.
3. Dodajte `?token=...` bez zaglavlja: endpoint mora odbiti query token. Nevažeći token iz URL-a ne sme se autentifikovati.
4. Pošaljite `POST` sa važećim zaglavljem: očekujte `405`, `Allow: GET`, JSON i no-store.
5. Pošaljite HTTP zahtev koristeći loopback (`127.0.0.1`) za lokalni izuzetak: dozvoljen je. Ponovite preko ne-loopback peer-a ili sa ne-loopback hostname-om preko HTTP: očekujte `400 https_required`. Hosting koristi HTTPS; ne oslanjajte se na `X-Forwarded-Proto` niti samo na `Host`.
6. Potvrdite da API ne koristi session login, ne postavlja CORS headers i ne emituje token/hash/config/SQL detalje ni u greškama.

### Otvorene stavke

- Hosting backfill starih kontakata za V5.3 još nije izvršen.
- Stvarni paralelni test CSV confirmation toka još nije izvršen.
- Hosting migracija 010, Plesk Authorization header forwarding i HTTPS endpoint nisu provereni. Potvrđeno je da `CGIPassAuth On` izaziva HTTP 500 na hostingu i da je direktiva uklonjena. Ne vraćati je niti koristiti alternativni token kanal; ostaje potreban hosting administrator da podesi dozvoljeno prosleđivanje standardnog Authorization zaglavlja PHP-FPM-u.

#### V5.6.4 — API lista i pojedinačni prikaz kontakta

##### Izolovana priprema

1. Napravite novu privremenu lokalnu bazu, npr. `address_book_v564_test`, i posebnu privremenu kopiju projekta. Ne koristite bazu iz postojećeg `config.local.php`, glavnu lokalnu bazu ni hosting bazu.
2. U privremenu bazu primenite migracije `001`–`010` redom. Ova faza nema novu migraciju. U kopiji projekta podesite lokalni config samo za privremenu bazu i pokrenite `php -S 127.0.0.1:8000 -t public`.
3. Napravite dva test naloga A/B, nekoliko gradova i tagova, aktivne i obrisane kontakte za oba naloga, kao i API tokene za testiranje. Zabeležite ID-jeve testnih zapisa; ne zapisujte plaintext tokene u fajl ili izveštaj. Uklonite bazu i kopiju projekta po završetku.

##### HTTP testovi

Koristite endpoint-e `GET /api/v1/contacts.php` i `GET /api/v1/contact.php?id=...` direktno, bez rewrite pravila. Tačni PowerShell `curl.exe` primeri i JSON struktura nalaze se u `docs/API.md`.

1. Važeći token A: lista vraća samo kontakte A; kontakt B iz direktnog `id` zahteva vraća isti `404 contact_not_found` kao nepostojeći ID. Nevažeći token vraća `401 invalid_token`, nedostajući token `401 authentication_required`, a opozvani token `401 invalid_token`. Session cookie bez Bearer tokena ne autentifikuje zahtev.
2. Podrazumevana lista sadrži samo aktivne kontakte. `view=deleted` vraća samo obrisane kontakte A. Pojedinačni endpoint može vratiti A-ov aktivan ili obrisan kontakt.
3. Kombinujte `search`, `city_id`, `tag_id`, `date_from` i `date_to`; svaki uslov sužava isti skup za listu i `total`. Pokušajte tuđi city/tag ID: dobijate `400 invalid_parameter`, bez tuđih kontakata.
4. Isprobajte sva četiri `sort` polja u oba smera, jednake vrednosti i više stranica. Redosled mora ostati stabilan; `page` izvan opsega vraća praznu listu i zadržava traženu stranicu u `meta`. `per_page` je 25 podrazumevano, prihvata najviše 100; `total_pages` je 0 kada nema rezultata.
5. Pošaljite niz umesto skalara (`search[]=Ana`, `city_id[]=1`), nultu/negativnu/nebrojnu stranicu, `per_page=101`, neispravan/obrnut datum opseg, nepoznat sort/direction i neispravan `id`. Očekujte `400` JSON `invalid_parameter` sa srpskom porukom.
6. Tuđi i nepostojeći ID pojedinačnog kontakta moraju dati isti 404 status i kod. Neispravan ID format daje 400. Pošaljite POST na oba endpoint-a: `405`, `Allow: GET`, JSON.
7. Proverite da lista sa kontaktom koji ima više tagova i dalje sadrži jedan kontakt, a `total` ga broji jednom. JSON ne sme sadržati `phone_normalized` ili `email_normalized`. Svi odgovori su `application/json`, `Cache-Control: no-store`; neočekivana serverska greška je generička.
8. Datumi su stringovi `YYYY-MM-DD HH:MM:SS` bez konverzije: `created_at` je u MySQL session vremenskoj zoni, a `deleted_at` predstavlja UTC `DATETIME`. Proverite da se vrednosti poklapaju sa bazom i da API nije promenio izvorno značenje.

##### Plesk ograničenje

Lokalni HTTP testovi ne dokazuju hosting prosleđivanje. Na potvrđenom Plesk-u `Authorization` zaglavlje ne stiže do PHP-a; `CGIPassAuth On` izazvao je HTTP 500 i uklonjen je. Ne vraćati direktivu i ne slati token kroz URL ili drugo zaglavlje. Hosting API provere čekaju dozvoljenu administratorsku konfiguraciju za prosleđivanje standardnog Authorization zaglavlja, pa tek onda HTTPS test važećim, nevažećim, nedostajućim i opozvanim tokenom.

##### Izvršene lokalne provere

Dana 2026-10-20 izvršeno je 21 izolovanih HTTP asercija nad privremenom bazom sa migracijama `001`–`010`, koristeći lokalni MySQL Server 8.0.46 i PHP 8.5.11. Prošle su provere tokena i session-cookie izolacije, vlasništva dva naloga, aktivnih/obrisanih i pojedinačnih kontakata, kombinovanih filtera, sva četiri sortiranja u oba smera, paginacije, neispravnih parametara, 404/405 i JSON polja/vremenskog formata. Privremena baza, nalozi i tokeni uklonjeni su po testu. Ovo ne potvrđuje kompatibilnost izvršavanja na MariaDB 5.5.68 niti prosleđivanje zaglavlja na Plesk-u; te provere ostaju otvorene.
