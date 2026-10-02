(() => {
    const dialog = document.getElementById('contact-generator-dialog');
    const form = dialog?.querySelector('[data-contact-generator]');
    const countInput = form?.querySelector('[name="count"]');
    const submitButton = form?.querySelector('[data-contact-generator-submit]');
    const errorMessage = dialog?.querySelector('[data-contact-generator-error]');
    const successMessage = document.getElementById('contact-generator-success');
    const searchInput = document.getElementById('contact-search');

    if (!dialog || !form || !countInput || !submitButton || !errorMessage || !successMessage) return;

    let isGenerating = false;

    const showError = (message) => {
        errorMessage.textContent = message;
        errorMessage.hidden = message === '';
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (isGenerating) return;

        const count = countInput.valueAsNumber;
        if (countInput.value === '' || !Number.isInteger(count)) {
            showError('Unesite ispravan ceo broj test kontakata.');
            countInput.focus();
            return;
        }
        if (count < 0) {
            showError('Broj dummy kontakata ne može biti manji od 0.');
            countInput.focus();
            return;
        }
        if (count > 500) {
            showError('Broj dummy kontakata ne može biti veći od 500.');
            countInput.focus();
            return;
        }
        if (count === 0) {
            showError('Broj dummy kontakata mora biti veći od 0.');
            countInput.focus();
            return;
        }

        isGenerating = true;
        showError('');
        successMessage.hidden = true;
        form.setAttribute('aria-busy', 'true');
        countInput.disabled = true;
        submitButton.disabled = true;
        submitButton.textContent = submitButton.dataset.pendingLabel || 'Generisanje...';

        try {
            const response = await fetch('/contact-generate.php', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({ count: String(count) }).toString(),
            });

            let payload;
            try {
                payload = await response.json();
            } catch {
                throw new Error('invalid-response');
            }

            if (!response.ok || payload.status !== 'success' || payload.count !== count) {
                const serverMessage = typeof payload.error === 'string' && payload.error !== ''
                    ? payload.error
                    : 'Test kontakte trenutno nije moguće generisati. Pokušajte ponovo.';
                showError(serverMessage);
                return;
            }

            successMessage.textContent = `Uspešno je generisano ${payload.count} test kontakata.`;
            successMessage.hidden = false;
            dialog.close();
            searchInput?.dispatchEvent(new Event('input', { bubbles: true }));
        } catch {
            showError('Test kontakte trenutno nije moguće generisati. Pokušajte ponovo.');
        } finally {
            isGenerating = false;
            form.removeAttribute('aria-busy');
            countInput.disabled = false;
            submitButton.disabled = false;
            submitButton.textContent = 'Generiši';
        }
    });
})();
