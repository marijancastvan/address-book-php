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
