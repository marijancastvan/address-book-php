<dialog class="app-dialog" id="<?= escapeHtml($dialogId) ?>" aria-labelledby="<?= escapeHtml($dialogId) ?>-title" <?= !empty($openDialog) ? 'data-open-on-load="true"' : '' ?> <?= !empty($resetTagSelectionOnClose) ? 'data-reset-contact-tags-on-close="true"' : '' ?>>
    <section class="dialog-panel">
        <header class="dialog-header">
            <div>
                <p class="eyebrow">ADRESAR</p>
                <h2 id="<?= escapeHtml($dialogId) ?>-title"><?= escapeHtml($dialogTitle) ?></h2>
            </div>
            <button class="dialog-close" type="button" data-dialog-close aria-label="Zatvori dijalog">&times;</button>
        </header>

        <?php if (($errors['_form'] ?? '') !== ''): ?>
            <p class="message message-error" role="alert"><?= escapeHtml($errors['_form']) ?></p>
        <?php endif; ?>
        <?php if ($cities === []): ?>
            <p class="message message-info">Nemate nijedan grad. Kontakt možete dodati kada kreirate svoj grad.</p>
        <?php endif; ?>

        <form class="contact-form" method="post" action="<?= escapeHtml($formAction) ?>" novalidate data-validate-form data-pending-submit>
            <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
            <?php if (isset($contactId)): ?>
                <input type="hidden" name="contact_id" value="<?= (int) $contactId ?>">
            <?php endif; ?>

            <?php foreach ([
                'first_name' => ['label' => 'Ime', 'type' => 'text', 'max' => 100, 'required' => 'Ime je obavezno.'],
                'last_name' => ['label' => 'Prezime', 'type' => 'text', 'max' => 100, 'required' => 'Prezime je obavezno.'],
                'phone' => ['label' => 'Telefon', 'type' => 'tel', 'max' => 50, 'required' => 'Telefon je obavezan.'],
                'email' => ['label' => 'Email', 'type' => 'email', 'max' => 255, 'required' => 'Email je obavezan.'],
            ] as $field => $definition): ?>
                <?php $fieldId = $formIdPrefix . '-' . $field; $errorId = $fieldId . '-error'; $fieldError = $errors[$field] ?? ''; ?>
                <div class="field-group">
                    <label for="<?= escapeHtml($fieldId) ?>"><?= escapeHtml($definition['label']) ?></label>
                    <input id="<?= escapeHtml($fieldId) ?>" name="<?= escapeHtml($field) ?>" type="<?= escapeHtml($definition['type']) ?>" maxlength="<?= (int) $definition['max'] ?>" required value="<?= escapeHtml($formValues[$field]) ?>" data-required-message="<?= escapeHtml($definition['required']) ?>" data-length-message="<?= escapeHtml($definition['label'] . ' može imati najviše ' . $definition['max'] . ' karaktera.') ?>" <?= $field === 'email' ? 'data-type-message="Unesite ispravnu email adresu."' : '' ?> aria-describedby="<?= escapeHtml($errorId) ?>" <?= $fieldError !== '' ? 'aria-invalid="true" class="is-invalid"' : '' ?> <?= $field === 'first_name' ? 'autofocus' : '' ?>>
                    <p class="field-error" id="<?= escapeHtml($errorId) ?>" <?= $fieldError === '' ? 'hidden' : '' ?>><?= escapeHtml($fieldError) ?></p>
                </div>
            <?php endforeach; ?>

            <?php $cityFieldId = $formIdPrefix . '-city_id'; $cityError = $errors['city_id'] ?? ''; ?>
            <?php if (!isset($contactId)): ?>
                <?php
                $selectedCityName = '';
                foreach ($cities as $city) {
                    if ((string) $city['id'] === (string) $formValues['city_id']) {
                        $selectedCityName = (string) $city['name'];
                        break;
                    }
                }
                ?>
                <div class="field-group city-picker-field">
                    <label for="contact-create-city-search">Grad</label>
                    <div class="city-picker-control">
                        <div class="city-picker-input-wrap">
                            <input id="contact-create-city-search" type="search" maxlength="255" required value="<?= escapeHtml($selectedCityName) ?>" placeholder="Pretražite grad..." autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="contact-create-city-options" data-city-name-input data-required-message="Izaberite grad ili dodajte novo mesto." aria-describedby="contact-create-city_id-error contact-create-city-status" <?= $cityError !== '' ? 'aria-invalid="true" class="is-invalid"' : '' ?>>
                            <div class="city-picker-options" id="contact-create-city-options" role="listbox" aria-label="Rezultati pretrage gradova" hidden></div>
                        </div>
                        <button class="button button-secondary" type="button" data-dialog-open="contact-city-create-dialog" data-city-create-open disabled>Dodaj novo mesto</button>
                    </div>
                    <input id="contact-create-city_id-value" type="hidden" name="city_id" value="<?= escapeHtml($formValues['city_id']) ?>" data-city-id-value>
                    <p class="field-error" id="contact-create-city_id-error" <?= $cityError === '' ? 'hidden' : '' ?>><?= escapeHtml($cityError) ?></p>
                    <p class="city-create-status" id="contact-create-city-status" role="status" aria-live="polite"></p>
                </div>
            <?php else: ?>
                <div class="field-group">
                    <label for="<?= escapeHtml($cityFieldId) ?>">Grad</label>
                    <select id="<?= escapeHtml($cityFieldId) ?>" name="city_id" required data-required-message="Izaberite grad." aria-describedby="<?= escapeHtml($cityFieldId) ?>-error" <?= $cityError !== '' ? 'aria-invalid="true" class="is-invalid"' : '' ?>>
                        <option value="">Izaberite grad</option>
                        <?php foreach ($cities as $city): ?>
                            <option value="<?= (int) $city['id'] ?>" <?= (string) $city['id'] === (string) $formValues['city_id'] ? 'selected' : '' ?>><?= escapeHtml($city['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-error" id="<?= escapeHtml($cityFieldId) ?>-error" <?= $cityError === '' ? 'hidden' : '' ?>><?= escapeHtml($cityError) ?></p>
                </div>
            <?php endif; ?>

            <?php $tagError = $errors['tag_ids'] ?? ''; ?>
            <fieldset class="contact-tag-fieldset" aria-describedby="<?= escapeHtml($formIdPrefix) ?>-tag_ids-error">
                <legend>Tagovi <span>(opciono)</span></legend>
                <?php if ($availableTags === []): ?>
                    <p class="contact-tags-empty">Nemate tagove. <a href="<?= escapeHtml($baseUrl) ?>/tags.php">Upravljajte tagovima</a>.</p>
                <?php else: ?>
                    <div class="contact-tag-options">
                        <?php foreach ($availableTags as $tag): ?>
                            <?php $tagId = (int) $tag['id']; $tagInputId = $formIdPrefix . '-tag-' . $tagId; ?>
                            <label class="contact-tag-option" for="<?= escapeHtml($tagInputId) ?>">
                                <input id="<?= escapeHtml($tagInputId) ?>" type="checkbox" name="tag_ids[]" value="<?= $tagId ?>" <?= in_array($tagId, $selectedTagIds, true) ? 'checked' : '' ?>>
                                <span><?= escapeHtml((string) $tag['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="field-error contact-tag-error" id="<?= escapeHtml($formIdPrefix) ?>-tag_ids-error" <?= $tagError === '' ? 'hidden' : '' ?>><?= escapeHtml((string) $tagError) ?></p>
            </fieldset>

            <div class="form-actions">
                <button class="button button-primary" type="submit" data-pending-label="Čuvanje...">Sačuvaj</button>
                <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
            </div>
        </form>
    </section>
</dialog>
