<dialog class="app-dialog" id="<?= escapeHtml($dialogId) ?>" aria-labelledby="<?= escapeHtml($dialogId) ?>-title" <?= !empty($openDialog) ? 'data-open-on-load="true"' : '' ?>>
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

        <form class="contact-form" method="post" action="<?= escapeHtml($formAction) ?>" novalidate data-validate-form>
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

            <div class="form-actions">
                <button class="button button-primary" type="submit">Sačuvaj</button>
                <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
            </div>
        </form>
    </section>
</dialog>
