(() => {
    document.querySelectorAll('form[data-pending-submit]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;

            if (form.dataset.pending === 'true') {
                event.preventDefault();
                return;
            }

            form.dataset.pending = 'true';
            form.setAttribute('aria-busy', 'true');

            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                button.disabled = true;
                if (button instanceof HTMLButtonElement && button.dataset.pendingLabel) {
                    button.textContent = button.dataset.pendingLabel;
                } else if (button instanceof HTMLInputElement && button.dataset.pendingLabel) {
                    button.value = button.dataset.pendingLabel;
                }
            });
        });
    });
})();
