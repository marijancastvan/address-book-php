# V4 test scenarios

## V4.1.2 — Tag management

Run these checks while signed in. Use two separate user accounts for the ownership checks.

1. Open **Tagovi** with an account that has no tags. Confirm the empty state is shown and no other user's tags appear.
2. Add `Klijent`, `Partner`, and `VIP`. Confirm all three appear in alphabetical order.
3. Try submitting an empty name and a name containing only spaces. Both should be rejected with a Serbian validation message; no tag should be added.
4. Add a name with surrounding spaces, for example `  Klijent  `. Confirm the stored/displayed value is `Klijent`.
5. With `VIP` present, try adding `vip`. Confirm the duplicate is rejected case-insensitively. A database duplicate-key error must also be presented as a friendly duplicate message, never as raw SQL output.
6. Check the length boundary using Unicode code points:
   - A name of 150 characters, such as `Ž` repeated 150 times, is accepted.
   - A name of 151 characters is rejected.
   - Repeat with a multibyte character such as `😀` to confirm the limit is not measured in UTF-8 bytes or UTF-16 units.
7. Rename a tag and confirm the new name is displayed. Edit a tag without changing its name and confirm it is accepted.
8. Open a delete confirmation and choose **Otkaži**; confirm the tag and its contact associations remain. Open it again and choose **Izbriši**; confirm the tag is removed, its associations are removed, and the contacts remain.
9. Sign in as User A and create `VIP`; sign in as User B and create the same `VIP`. Confirm both can save it and each user sees only their own tag.
10. While signed in as User A, submit an edit request with User B's tag ID and a valid CSRF token. Confirm it is rejected and User B's tag remains unchanged. Repeat for delete and confirm User B's tag remains.
11. Submit create, edit, and delete POST requests without a valid CSRF token. Confirm each request is rejected and makes no database change.
12. Submit `VIP` when it already exists and confirm the duplicate message and entered value remain while the create dialog is open. Click **Otkaži**, reopen **Dodaj novi tag**, and confirm the field and errors are cleared.
13. Repeat with a name containing only spaces. Confirm the required-name message remains until closing, then reopen the create dialog and confirm it is blank with no error.
14. Repeat the reset check using the **X** button and **Escape**. Each close method should clear create-form values and errors. Confirm reopening an edit dialog still shows the selected tag's current name.

For the Unicode boundary, JavaScript and PHP count Unicode code points. A composed visual character made from multiple code points counts as multiple characters under this limit.

## V4.1.2 — Verification record (2026-10-07)

### Manually confirmed by the user

- Each user sees only their own tags.
- The same tag name can exist on two accounts.
- Editing or deleting a tag on one account does not affect the other account.

### Executed against the local application

The tests used the local `address_book` database (MySQL 8.0.46) and a temporary PHP development server on loopback. The local configuration was checked before running; no production host was used.

- A valid session and valid CSRF token belonging to a non-owner were used for direct edit and delete POST requests. Both were rejected as not found; the owner's tag stayed unchanged.
- Create, edit, and delete were each submitted once without a CSRF token and once with an invalid token. All six requests were rejected; no tags were inserted, updated, or deleted.
- A create POST containing 150 Unicode code points (emoji) succeeded.
- A create POST containing 151 Unicode code points was rejected and inserted no tag.
- The isolated users and their tags were deleted in cleanup; a follow-up query found zero test users. Temporary session files and the local PHP server were also removed/stopped.

### Still to check manually in the browser

- Empty state, ordinary create/edit, duplicate-name feedback, whitespace trimming, and retaining the submitted value while a validation dialog remains open.
- Cancel and confirm in the delete dialog, including verifying that deleting a tag removes its contact links while preserving the contacts.
- The UI display of the 150/151-character validation boundary and normal owner-authorized edit/delete flows.

## V4.1.3 — Contact tags

Use a signed-in account with at least one city. The contact forms submit to `contact-create.php` and `contact-edit.php`; the checkbox field is `tag_ids[]`, the CSRF field is `csrf_token`, and edit forms also submit `contact_id`.

1. Create a contact without checking any tags. Expected: the contact is saved with no `contact_tags` rows.
2. Create another contact with two or more of your tags checked. Expected: one contact row and one association per distinct selected tag; the contact appears only once in the table.
3. Edit that contact and add a tag. Expected: the existing associations are retained and the new one is added.
4. Edit it again and choose a different set of tags. Expected: the old set is replaced by exactly the new set.
5. Edit it and uncheck all tags. Expected: all its tag links are removed, while the contact remains.
6. Cause another contact validation error after selecting tags. Expected: the entered contact fields, checked tags, and error remain visible when the form reopens. Cancel the create form, reopen it, and confirm its tag selection is empty. Open edit for two different contacts in turn; each form should show only that contact's existing tags.
7. Search for contacts and move between pagination pages. Expected: each visible row has the correct tag badges after live search and page changes. Reload the page; expected badges still match the database.
8. Rename a connected tag on the **Tagovi** page and reload Contacts. Expected: its badge uses the new name. Delete that tag; expected: only that badge/link disappears and the contact plus its other tags remain.
9. With two users, try selecting the other user's tag by submitting its ID in `tag_ids[]`. Expected: the POST is rejected with a clear message, and neither the contact nor any of its associations changes. A non-existent ID must have the same no-partial-save result.
10. Submit the same valid tag ID more than once in `tag_ids[]`. Expected: it is stored as one association. Submit a scalar instead of an array, a non-integer, zero, or a negative ID. Expected: the request is rejected without saving any contact or association changes.
11. Generate dummy contacts with the existing generator. Expected: generated contacts have no tag associations. Confirm ordinary contact create, edit, and delete still work.

### V4.1.3 local verification record (2026-10-07)

**Executed against local test data:** the configured database and app URL were confirmed to target loopback and the local `address_book` database (MySQL 8.0.46). A temporary PHP server was used; production was not contacted.

- Create POST with no tags and with multiple tags passed. Repeated tag IDs produced one association.
- Edit POST replaced the association set; an empty selection removed all links and kept the contact.
- Create/edit POSTs with a foreign or nonexistent tag were rejected. The create did not add a contact; rejected edits left the contact fields and existing links unchanged.
- A valid session without a CSRF token and a valid session with an invalid token were rejected without data changes.
- A failing association insert in a transaction rolled back the preceding contact insert.
- A contact validation error preserved the submitted values and selected tag in the reopened form. Separate edit GETs showed each contact's own selection.
- Live-search JSON returned the grouped tag list for matching contacts. The dummy generator created a contact without tag links.
- Temporary test accounts, cities, contacts, tags, association rows, and session files were removed. A follow-up query found zero `v413-…@example.invalid` test accounts; both local PHP servers were stopped.

**Still requiring browser verification:** visually inspect badges in the initial table and after live search and page changes (including a page beyond page 1); verify create-form tag selection resets after Cancel/close; confirm the full contact create/edit/delete UX and tag badge updates after rename/delete. The DOM close behavior and responsive layout were not automated in a browser.

## Contact ordering check

Create enough contacts to span at least two pages, including `aAna aaa`, `Petar 1123`, and multiple contacts with the same first name but different last names. Confirm the list starts in case-insensitive ascending first-name order, then sorts matching first names by last name, with a stable order for exact name ties. Check the boundary between pages, repeat with a live-search query, and confirm the same order is retained in the JSON results and pagination. The first page should contain the first 25 results; later pages must continue the full sorted sequence without duplicates or omissions.

## V4.2 — Combined contact filters

Use only a local database. Before inserting test contacts, confirm `SELECT DATABASE(), @@session.time_zone, @@global.time_zone, @@system_time_zone, NOW(), UTC_TIMESTAMP();`. Create two local test accounts, two cities and two tags for the first account, plus a city and tag for the second account. For the first account, create at least 30 contacts named `Alpha 001` through `Alpha 030` in City A, tagged with `Filter A`, and dated on `2026-05-10`; assign both tags to at least one contact. Add contacts at `2026-05-09 23:59:59`, `2026-05-10 00:00:00`, `2026-05-10 23:59:59`, and `2026-05-11 00:00:00`. Put additional control contacts in City B, with Filter B, and with a different creation date. Keep a record of all test IDs and remove only those rows after testing.

`contacts.created_at` is a MySQL `TIMESTAMP` with `DEFAULT CURRENT_TIMESTAMP`. The application does not set a connection timezone; MySQL interprets and displays this column in each connection's session timezone. Date filters therefore use midnight in the MySQL session timezone as the inclusive lower boundary and midnight after the selected end date as the exclusive upper boundary. They do not assume stored values are UTC and do not call `DATE(created_at)`. On the local environment checked for V4.2, PHP reported `UTC`, while MySQL reported session/global `SYSTEM`, system zone `Central Europe Daylight Time`, and a current offset of `+02:00`. Verify these values on hosting with the SQL above; hosting timezone was not accessible during implementation. A date-only value is a calendar date and is not converted through PHP's timezone.

1. Test the text, city, tag, start date and end date separately. Confirm each limits only the signed-in user's contacts. Combine all five controls and confirm the result satisfies every non-empty filter; the four non-text filters combine with the grouped text-search OR conditions using AND.
2. Confirm the contact assigned both tags appears once when filtering by either tag. Verify the total count and page count do not duplicate it.
3. With `Alpha` + City A + Filter A + `2026-05-10` active, confirm 30 results, 25 on page 1 and 5 on page 2. Confirm the sort remains `first_name ASC, last_name ASC, id ASC`, filters remain in the URL across pages, and HTML rows and `format=json` return identical contact IDs in the same order.
4. Test only `date_from=2026-05-10`: contacts at the start of that date and later are included, but the previous day's `23:59:59` is excluded. Test only `date_to=2026-05-10`: the selected day's `23:59:59` is included and the next day's `00:00:00` is excluded. Test both dates equal to `2026-05-10` for the same complete-day boundaries.
5. For deterministic boundary setup, set values on isolated test rows using the database session timezone:

   ```sql
   UPDATE contacts SET created_at = '2026-05-09 23:59:59' WHERE id = <local_test_contact_id>;
   UPDATE contacts SET created_at = '2026-05-10 00:00:00' WHERE id = <local_test_contact_id>;
   UPDATE contacts SET created_at = '2026-05-10 23:59:59' WHERE id = <local_test_contact_id>;
   UPDATE contacts SET created_at = '2026-05-11 00:00:00' WHERE id = <local_test_contact_id>;
   ```

   Run one statement per test row and replace the placeholder only with an isolated local test ID. Do not run these updates on production data.
6. Submit malformed dates such as `2026-02-30`, a non-`YYYY-MM-DD` value and a reversed range. Expected: HTML shows a validation message; JSON returns HTTP 400 with an `error` object containing `code`, `message` and field details. A foreign or missing `city_id`/`tag_id` must also return a readable validation error, not an empty successful result. Try an account B ID while signed in as account A.
7. Search for a unique nonexistent phrase. Expected: a clear empty-results state, zero results and no pagination. Confirm the filters still work using the ordinary GET form with JavaScript disabled.
8. From page 2, edit one matching contact so that it no longer matches the active text filter. Save it. Expected: the redirect retains every filter and page context, the updated row disappears from the filtered results, and no false “contact not found” message appears. Trigger a validation error in edit and confirm the same filters remain in the return URL.
9. Use browser Back and Forward after changing filters and pages. Expected: controls, results and URL return to the corresponding state. Change a filter while on a later page; expected: results restart at page 1.
10. Remove the isolated contacts, tags, cities and accounts after the checks.

For production-like scale, apply `app/database/migrations/004_add_contact_created_at_filter_index.sql` manually after reviewing it. It adds `(user_id, created_at)` so the owner equality and date range can use one index. Do not run it automatically as part of a page request.
# V4.3 — Istorija promena kontakta

## Migracija

Migracija `app/database/migrations/005_create_contact_history.sql` se ne pokreće automatski. Napravite backup, pa primenite je nad ciljnom bazom preko phpMyAdmin (Import) ili MySQL klijenta:

```sh
mysql -u USER -p DATABASE < app/database/migrations/005_create_contact_history.sql
```

Proverite da postoje `contact_history_events` i `contact_history_changes` i njihovi ključevi. Aplikacija upisuje `occurred_at` pomoću `UTC_TIMESTAMP()`; vrednost `DATETIME` se pri prikazu tumači kao UTC i konvertuje u `Europe/Belgrade`, uz automatski CET/CEST pomak. Prikaz je `Time: HH:mm dd-mm-yyyy` bez oznake vremenske zone. Postojeći zapisi se konvertuju pri čitanju i ne menjaju se u bazi. Za zapise ručno unete ili menjane van aplikacije ne može se utvrditi zona samo iz `DATETIME` vrednosti.

## Funkcionalni testovi

1. Izmenite ime, telefon, e-mail, grad i/ili tag kontakta. Stranica Istorija treba da prikaže samo stvarno promenjena polja sa starim i novim vrednostima, vremenom i email-om aktera.
2. Izmenite kontakt i uporedite vreme novog događaja sa lokalnim vremenom u `Europe/Belgrade`. Proverite zimski i letnji datum, uključujući UTC vreme koje nakon konverzije prelazi ponoć; očekivani prikaz je `Time: HH:mm dd-mm-yyyy`, bez `UTC`.
3. Sačuvajte kontakt bez promena: ne treba da nastane novi događaj. Dodajte, zamenite i uklonite tagove i proverite snapshot-e naziva i ID-jeva.
4. Preimenujte tag povezan sa više kontakata: svaki pogođeni kontakt dobija događaj sa starim i novim skupom tagova. Obrišite tag: događaji pokazuju uklonjeni tag, kontakti ostaju.
5. Preimenujte grad povezan sa više kontakata: svaki kontakt dobija staro i novo ime grada. Pokušaj brisanja grada povezanog sa kontaktom ostaje odbijen postojećim FK ograničenjem.
6. Kreirajte kontakt ručno i preko generatora: ne nastaje istorijski događaj. Obrišite kontakt: njegov događaj i promene se uklanjaju kaskadno.
7. Na listi sa aktivnom pretragom/filterima i stranom većom od 1 otvorite Istorija, pa Nazad na kontakte; filteri i broj strane treba da ostanu sačuvani. Testirajte istoriju sa preko 25 događaja i proverite najnoviji događaj prvi.
8. Otvorite istoriju tuđeg ili nepostojećeg ID-ja: oba slučaja daju isti 404 odgovor bez prikaza podataka. Proverite escaping koristeći HTML specijalne znakove u nazivu taga/grada.
9. Testirajte CSRF validaciju na kreiranju/izmeni kontakta, brisanju kontakta, izmeni/brisanja grada i taga; zahtevi bez tokena ili sa pogrešnim tokenom ne smeju menjati podatke.
10. Za proveru atomicity-jaa izolovanoj bazi izazovite neuspeh upisa istorije tokom izmene, pa proverite da su i kontakt i njegove tag veze vraćeni na prethodno stanje.

## Transakcije i lock redosled

Operacije koje mogu promeniti istorijske snapshot-e prvo zaključavaju red korisnika, čime se izmene istog naloga serijalizuju. Zatim kontakt izmena/brisanje zaključava kontakt; preimenovanje/brisanje grada ili taga zaključava taj entitet i pogođene kontakte po rastućem ID-ju. Kontakt kreiranje i generator koriste isti lock korisnika pre provere gradova/tagova i insert-a. Istorijski događaj se upisuje u istoj transakciji kao promena. Nemojte primenjivati migraciju na produkciji pre pregleda i backup-a.

## Rezultati verifikacije V4.3

- Izvršeno na privremenoj lokalnoj MySQL bazi: migracije 001–005 su primenjene; preimenovanje/brisanje taga i preimenovanje grada za po dva kontakta proizveli su po jedan događaj za svaki kontakt; tag linkovi su uklonjeni bez brisanja kontakata. Provereno je i da upit ograničen drugim korisnikom ne vidi te događaje. Privremena baza je uklonjena.
- Preostaje ručno proveriti browser tokove i forme iz gore navedenih koraka, posebno contact edit rollback pri namerno neuspešnom upisu istorije, CSRF i istorijsku paginaciju. Browser provera nije izvršena.

## V4.4.1 — Tagovi na Dashboard-u

1. Prijavite se i otvorite Dashboard. Kartica **Tagovi** treba da prikazuje samo naslov „Tagovi“ i tekst „Upravljajte svojim tagovima“, bez broja tagova.
2. Kliknite karticu i potvrdite da otvara postojeću stranicu `tags.php`.
3. Proverite desktop, tablet i telefon: tri kartice su u tri kolone na širokom ekranu, dve na srednjem, a jedna u koloni na užem ekranu; kartica Tagovi zadržava ljubičastu paletu, a kartice Kontakti i Gradovi zadržavaju izgled i linkove.

## V4.4.2 — Kreiranje taga iz kontakt forme

1. Otvorite Dodaj kontakt, unesite vrednosti u kontakt polja i izaberite grad. Kada nalog još nema tagove, kliknite **Dodaj tag**, napravite prvi tag i proverite da se opcija pojavi i označi bez zatvaranja ili gubitka unosa kontakt forme.
2. Sa postojećim tagovima izaberite jedan ili više tagova, otvorite dijalog taga i kreirajte još jedan. Proverite da se postojeći izbor sačuva, novi tag se označi samo u formi iz koje je kreiranje pokrenuto, a obe otvorene kontakt forme dobiju sortiranu novu opciju.
3. Ponovite tok iz Izmeni kontakt. Sačuvajte kontakt i proverite da su tag veze tačne, a V4.3 istorija sadrži promenu tagova samo ako se skup izabranih tagova zaista promenio.
4. Probajte naziv koji već postoji (uključujući promenu velikih/malih slova), prazan naziv i naziv sa 151 Unicode code point-om. Očekujte jasnu grešku u tag dijalogu; kontakt forma i njeni podaci/izbori ostaju otvoreni i nepromenjeni. Ispravite naziv i ponovite zahtev.
5. Za neuspešan mrežni/server zahtev proverite da se poruka prikaže u tag dijalogu, dugme ponovo omogući i ponovni pokušaj radi. Tokom zahteva brzo kliknite Dodaj više puta: sme biti poslat samo jedan zahtev.
6. Otvorite dijalog taga pa ga zatvorite preko Otkaži, X i Escape. Svaki put treba da se zatvori samo tag dijalog; kontakt forma, njena polja i prethodni tag izbor ostaju sačuvani.
7. Kreirajte tag, zatim otkažite kontakt formu. Tag treba da ostane na stranici Tagovi, bez kontakt veze i bez novog događaja u istoriji kontakta.
8. Sačuvajte kontakt nakon uspešnog kreiranja taga. Proverite vezu u prikazu kontakta i odgovarajući događaj istorije ako je tag izbor izmenjen.
9. Proverite izolaciju sa dva korisnika: svaki vidi i može inline da kreira samo svoje tagove. Pozovite `tag-create.php?format=json` bez CSRF tokena ili sa pogrešnim tokenom: očekujte HTTP 403 i bez promene baze. Request `user_id` se ignoriše; vlasnik je sesija.
