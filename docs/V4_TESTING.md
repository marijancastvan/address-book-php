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
