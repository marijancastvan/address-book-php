(() => {
    const appBase = document.body?.dataset.appBase ?? '';
    const dialog = document.querySelector('[data-contact-tag-create-dialog]');
    const form = dialog?.querySelector('[data-tag-create-form]');
    const nameInput = dialog?.querySelector('#contact-tag-create-name');
    const errorMessage = dialog?.querySelector('[data-tag-create-error]');
    const submitButton = dialog?.querySelector('[data-tag-create-submit]');
    const cancelButton = dialog?.querySelector('[data-tag-create-cancel]');

    if (!dialog || !form || !nameInput || !errorMessage || !submitButton || !cancelButton) return;

    let sourceContactForm = null;
    let isSubmitting = false;

    const showError = (message) => {
        errorMessage.textContent = message;
        errorMessage.hidden = message === '';
        nameInput.classList.toggle('is-invalid', message !== '');
        if (message === '') nameInput.removeAttribute('aria-invalid');
        else nameInput.setAttribute('aria-invalid', 'true');
    };

    const setSubmitting = (submitting) => {
        isSubmitting = submitting;
        submitButton.disabled = submitting;
        cancelButton.disabled = submitting;
        dialog.querySelector('.dialog-close').disabled = submitting;
        submitButton.textContent = submitting ? 'Dodavanje...' : 'Dodaj tag';
        if (submitting) dialog.setAttribute('aria-busy', 'true');
        else dialog.removeAttribute('aria-busy');
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-tag-create-open]');
        if (!trigger) return;
        sourceContactForm = trigger.closest('form.contact-form');
        showError('');
        nameInput.value = '';
    });

    dialog.addEventListener('cancel', (event) => {
        if (isSubmitting) event.preventDefault();
    });

    dialog.addEventListener('close', () => {
        if (!isSubmitting) {
            nameInput.value = '';
            showError('');
            sourceContactForm = null;
        }
    });

    const insertTagOption = (contactForm, tag, selected) => {
        const options = contactForm.querySelector('[data-contact-tag-options]');
        if (!options || options.querySelector(`input[name="tag_ids[]"][value="${tag.id}"]`)) return;

        const prefix = contactForm.dataset.contactFormPrefix || 'contact';
        const label = document.createElement('label');
        label.className = 'contact-tag-option';
        label.htmlFor = `${prefix}-tag-${tag.id}`;
        label.dataset.contactTagName = tag.name;

        const checkbox = document.createElement('input');
        checkbox.id = label.htmlFor;
        checkbox.type = 'checkbox';
        checkbox.name = 'tag_ids[]';
        checkbox.value = String(tag.id);
        checkbox.checked = selected;

        const text = document.createElement('span');
        text.textContent = tag.name;
        label.append(checkbox, text);
        options.append(label);

        const sortedOptions = [...options.querySelectorAll(':scope > .contact-tag-option')]
            .sort((left, right) => left.dataset.contactTagName.localeCompare(right.dataset.contactTagName, 'sr', { sensitivity: 'base' }));
        sortedOptions.forEach((option) => options.append(option));

        const emptyMessage = contactForm.querySelector('[data-contact-tags-empty]');
        if (emptyMessage) emptyMessage.hidden = true;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (isSubmitting) return;

        const name = nameInput.value.trim();
        if (name === '') {
            showError('Naziv taga je obavezan.');
            nameInput.focus();
            return;
        }

        showError('');
        setSubmitting(true);
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                credentials: 'same-origin',
                body: new URLSearchParams(new FormData(form)).toString(),
            });
            let payload;
            try {
                payload = await response.json();
            } catch {
                throw new Error('invalid-response');
            }

            if (!response.ok || payload.status !== 'created' || !payload.tag
                || !Number.isInteger(Number(payload.tag.id)) || Number(payload.tag.id) < 1
                || typeof payload.tag.name !== 'string') {
                const message = typeof payload.errors?.name === 'string'
                    ? payload.errors.name
                    : typeof payload.error === 'string'
                        ? payload.error
                        : 'Tag trenutno nije moguće dodati. Pokušajte ponovo.';
                showError(message);
                return;
            }

            const tag = { id: Number(payload.tag.id), name: payload.tag.name };
            document.querySelectorAll('form.contact-form').forEach((contactForm) => {
                insertTagOption(contactForm, tag, contactForm === sourceContactForm);
            });
            dialog.close();
        } catch {
            showError('Tag trenutno nije moguće dodati. Proverite vezu i pokušajte ponovo.');
        } finally {
            setSubmitting(false);
        }
    });
})();
