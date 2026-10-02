<dialog class="app-dialog" id="<?= escapeHtml($dialogId) ?>" aria-labelledby="<?= escapeHtml($dialogId) ?>-title" <?= !empty($openDialog) ? 'data-open-on-load="true"' : '' ?>>
    <section class="dialog-panel">
        <header class="dialog-header">
            <div><p class="eyebrow">LOKACIJE</p><h2 id="<?= escapeHtml($dialogId) ?>-title"><?= escapeHtml($dialogTitle) ?></h2></div>
            <button class="dialog-close" type="button" data-dialog-close aria-label="Zatvori dijalog">&times;</button>
        </header>
        <?php if (($errors['_form'] ?? '') !== ''): ?><p class="message message-error" role="alert"><?= escapeHtml($errors['_form']) ?></p><?php endif; ?>
        <form class="city-form" method="post" action="<?= escapeHtml($formAction) ?>" novalidate data-validate-form data-pending-submit>
            <?php if (isset($cityId)): ?><input type="hidden" name="city_id" value="<?= (int) $cityId ?>"><?php endif; ?>
            <?php $nameError = $errors['name'] ?? ''; ?>
            <div class="field-group">
                <label for="<?= escapeHtml($formIdPrefix) ?>-name">Naziv grada</label>
                <input id="<?= escapeHtml($formIdPrefix) ?>-name" name="name" type="text" maxlength="255" required value="<?= escapeHtml($cityName) ?>" data-required-message="Naziv grada je obavezan." data-length-message="Naziv grada može imati najviše 255 karaktera." aria-describedby="<?= escapeHtml($formIdPrefix) ?>-name-error" <?= $nameError !== '' ? 'aria-invalid="true" class="is-invalid"' : '' ?> autofocus>
                <p class="field-error" id="<?= escapeHtml($formIdPrefix) ?>-name-error" <?= $nameError === '' ? 'hidden' : '' ?>><?= escapeHtml($nameError) ?></p>
            </div>
            <div class="form-actions">
                <button class="button button-primary" type="submit" data-pending-label="Čuvanje...">Sačuvaj</button>
                <button class="button button-secondary" type="button" data-dialog-close>Otkaži</button>
            </div>
        </form>
    </section>
</dialog>
