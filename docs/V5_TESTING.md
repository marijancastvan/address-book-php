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
