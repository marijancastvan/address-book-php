(() => {
    const form = document.querySelector('.contact-comment-form');
    const textarea = form?.querySelector('textarea[name="body"]');
    if (!form || !textarea) return;

    const validateComment = () => {
        textarea.setCustomValidity(textarea.value.trim() === '' ? 'Komentar je obavezan.' : '');
    };

    validateComment();
    textarea.addEventListener('input', validateComment);
    form.addEventListener('submit', validateComment);
})();
