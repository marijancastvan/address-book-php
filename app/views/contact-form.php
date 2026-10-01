<?php if ($errors !== []): ?>
    <div class="messages" role="alert">
        <?php foreach ($errors as $error): ?>
            <p class="message message-error"><?= escapeHtml($error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($cities === []): ?>
    <p class="message message-info">Nemate nijedan grad. Kontakt možete dodati kada kreirate svoj grad.</p>
<?php endif; ?>

<form class="contact-form" method="post" action="<?= escapeHtml($formAction) ?>">
    <?php if (isset($contactId)): ?>
        <input type="hidden" name="contact_id" value="<?= (int) $contactId ?>">
    <?php endif; ?>

    <label for="first_name">Ime</label>
    <input id="first_name" name="first_name" type="text" maxlength="100" required value="<?= escapeHtml($formValues['first_name']) ?>">

    <label for="last_name">Prezime</label>
    <input id="last_name" name="last_name" type="text" maxlength="100" required value="<?= escapeHtml($formValues['last_name']) ?>">

    <label for="phone">Telefon</label>
    <input id="phone" name="phone" type="tel" maxlength="50" required value="<?= escapeHtml($formValues['phone']) ?>">

    <label for="email">Email</label>
    <input id="email" name="email" type="email" maxlength="255" required value="<?= escapeHtml($formValues['email']) ?>">

    <label for="city_id">Grad</label>
    <select id="city_id" name="city_id" required>
        <option value="">Izaberite grad</option>
        <?php foreach ($cities as $city): ?>
            <option value="<?= (int) $city['id'] ?>" <?= (string) $city['id'] === (string) $formValues['city_id'] ? 'selected' : '' ?>><?= escapeHtml($city['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <div class="form-actions">
        <button class="button button-primary" type="submit">Sačuvaj</button>
        <a class="button button-secondary" href="/contacts.php">Otkaži</a>
    </div>
</form>
