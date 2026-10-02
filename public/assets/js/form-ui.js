(() => {
    const dialogs = [...document.querySelectorAll('dialog.app-dialog')];

    const syncScrollLock = () => {
        document.body.classList.toggle('modal-open', dialogs.some((dialog) => dialog.open));
    };

    const openDialog = (dialog) => {
        if (!dialog || dialog.open) return;

        dialog.showModal();
        syncScrollLock();
        dialog.querySelector('[autofocus]')?.focus();
    };

    document.addEventListener('click', (event) => {
        const target = event.target;
        const closeTrigger = target.closest('[data-dialog-close]');
        if (closeTrigger) {
            closeTrigger.closest('dialog')?.close();
            return;
        }

        const confirmTrigger = target.closest('[data-confirm-open]');
        if (confirmTrigger) {
            const dialog = document.getElementById(confirmTrigger.dataset.confirmOpen);
            const idField = dialog?.querySelector('[data-confirm-id]');
            if (idField) idField.value = confirmTrigger.dataset.deleteId || '';
            const message = dialog?.querySelector('[data-confirm-message]');
            if (message) {
                const entity = dialog.dataset.confirmEntity || 'stavku';
                const label = confirmTrigger.dataset.deleteLabel || '';
                message.textContent = label
                    ? `Da li ste sigurni da želite da obrišete ${entity} „${label}“?`
                    : `Da li ste sigurni da želite da obrišete ovaj ${entity}?`;
            }
            openDialog(dialog);
            return;
        }

        const openTrigger = target.closest('[data-dialog-open]');
        if (openTrigger) {
            openDialog(document.getElementById(openTrigger.dataset.dialogOpen));
        }
    });

    dialogs.forEach((dialog) => {
        dialog.addEventListener('close', syncScrollLock);

        if (dialog.dataset.openOnLoad === 'true') openDialog(dialog);
    });

    const setFieldError = (field, message) => {
        const group = field.closest('.field-group');
        const error = group?.querySelector('.field-error');
        if (!group || !error) return;

        field.classList.toggle('is-invalid', message !== '');
        field.setAttribute('aria-invalid', message === '' ? 'false' : 'true');
        error.textContent = message;
        error.hidden = message === '';
    };

    const validateField = (field) => {
        const value = field.value.trim();

        if (field.required && value === '') {
            return field.dataset.requiredMessage || 'Ovo polje je obavezno.';
        }

        if (field.type === 'email' && value !== '' && field.validity.typeMismatch) {
            return field.dataset.typeMessage || 'Unesite ispravnu email adresu.';
        }

        if (field.maxLength > 0 && Array.from(value).length > field.maxLength) {
            return field.dataset.lengthMessage || `Polje može imati najviše ${field.maxLength} karaktera.`;
        }

        return '';
    };

    document.querySelectorAll('form[data-validate-form]').forEach((form) => {
        const fields = [...form.querySelectorAll('input, select, textarea')]
            .filter((field) => field.required || field.type === 'email');

        fields.forEach((field) => {
            const revalidate = () => {
                if (field.classList.contains('is-invalid')) {
                    setFieldError(field, validateField(field));
                }
            };
            field.addEventListener('input', revalidate);
            field.addEventListener('change', revalidate);
        });

        form.addEventListener('submit', (event) => {
            let firstInvalid = null;

            fields.forEach((field) => {
                const message = validateField(field);
                setFieldError(field, message);
                if (message !== '' && firstInvalid === null) firstInvalid = field;
            });

            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus();
            }
        });
    });
})();
