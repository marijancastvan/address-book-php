(() => {
    'use strict';

    const form = document.querySelector('.contact-merge-form');
    if (!form) return;

    const keepSelect = form.querySelector('#keep-contact-id');
    const keptPreview = form.querySelector('[data-merge-kept]');
    const sourceSelects = [...form.querySelectorAll('[data-merge-field]')];
    const useKeptValuesButton = form.querySelector('[data-use-kept-values]');
    const submitButton = form.querySelector('button[type="submit"]');

    const refreshPreview = () => {
        const keepOption = keepSelect.selectedOptions[0];
        keptPreview.textContent = keepOption ? keepOption.textContent : '';
        sourceSelects.forEach((select) => {
            const selectedOption = select.selectedOptions[0];
            const output = form.querySelector(`[data-merge-preview="${select.dataset.mergeField}"]`);
            if (output) output.textContent = selectedOption?.dataset.finalValue ?? '';
        });
    };

    keepSelect.addEventListener('change', refreshPreview);
    sourceSelects.forEach((select) => select.addEventListener('change', refreshPreview));
    useKeptValuesButton?.addEventListener('click', () => {
        sourceSelects.forEach((select) => {
            select.value = keepSelect.value;
        });
        refreshPreview();
    });
    form.addEventListener('submit', () => {
        if (submitButton.disabled) return;
        submitButton.disabled = true;
        submitButton.textContent = 'Spajanje...';
        form.setAttribute('aria-busy', 'true');
    });

    refreshPreview();
})();
