(() => {
    const form = document.querySelector('.contact-search');
    const input = document.getElementById('contact-search');
    const results = document.getElementById('contact-results');
    const status = document.getElementById('contact-search-status');

    if (!form || !input || !results) return;

    const columns = [
        ['first_name', 'Ime'],
        ['last_name', 'Prezime'],
        ['phone', 'Telefon'],
        ['email', 'Email'],
        ['city_name', 'Grad'],
    ];
    let debounceTimer = null;
    let requestVersion = 0;
    let activeController = null;

    const createCell = (tag, label, value) => {
        const cell = document.createElement(tag);
        cell.dataset.label = label;
        cell.textContent = value ?? '';
        return cell;
    };

    const renderContacts = (contacts) => {
        results.replaceChildren();

        if (contacts.length === 0) {
            const emptyState = document.createElement('section');
            emptyState.className = 'empty-state';
            const heading = document.createElement('h2');
            const message = document.createElement('p');
            if (input.value.trim() === '') {
                heading.textContent = 'Još nema kontakata';
                message.textContent = 'Trenutno nemate nijedan kontakt.';
                const addButton = document.createElement('button');
                addButton.className = 'button button-primary';
                addButton.type = 'button';
                addButton.textContent = 'Dodaj kontakt';
                addButton.dataset.dialogOpen = 'contact-create-dialog';
                emptyState.append(heading, message, addButton);
            } else {
                heading.textContent = 'Nema rezultata';
                message.textContent = 'Nema kontakata koji odgovaraju pretrazi.';
                emptyState.append(heading, message);
            }
            results.append(emptyState);
            return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'table-wrap';
        const table = document.createElement('table');
        const head = document.createElement('thead');
        const headingRow = document.createElement('tr');
        for (const [, label] of [...columns, ['actions', 'Akcije']]) {
            const heading = document.createElement('th');
            heading.scope = 'col';
            heading.textContent = label;
            headingRow.append(heading);
        }
        head.append(headingRow);

        const body = document.createElement('tbody');
        for (const contact of contacts) {
            const row = document.createElement('tr');
            for (const [key, label] of columns) {
                row.append(createCell('td', label, contact[key]));
            }

            const actionsCell = document.createElement('td');
            actionsCell.dataset.label = 'Akcije';
            const actions = document.createElement('div');
            actions.className = 'row-actions';
            const edit = document.createElement('a');
            edit.className = 'button button-small button-secondary';
            edit.textContent = 'Izmeni';
            edit.href = `/contacts.php?edit_id=${encodeURIComponent(contact.id)}&search=${encodeURIComponent(input.value.trim())}`;

            const remove = document.createElement('button');
            remove.className = 'button button-small button-danger';
            remove.type = 'button';
            remove.textContent = 'Izbriši';
            remove.dataset.confirmOpen = 'contact-delete-dialog';
            remove.dataset.deleteId = String(contact.id);
            remove.dataset.deleteLabel = `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim();

            actions.append(edit, remove);
            actionsCell.append(actions);
            row.append(actionsCell);
            body.append(row);
        }

        table.append(head, body);
        wrapper.append(table);
        results.append(wrapper);
    };

    const fetchContacts = async (term, version) => {
        const controller = new AbortController();
        activeController = controller;
        results.setAttribute('aria-busy', 'true');
        status.textContent = 'Pretraga...';
        status.dataset.state = 'loading';

        const query = new URLSearchParams({ search: term, format: 'json' });

        try {
            const response = await fetch(`/contacts.php?${query.toString()}`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json();

            if (version !== requestVersion) return;
            if (!response.ok || !Array.isArray(payload.contacts)) {
                throw new Error(payload.error || 'Live pretraga nije uspela.');
            }

            renderContacts(payload.contacts);
            status.textContent = '';
            status.removeAttribute('data-state');
        } catch (error) {
            if (error.name === 'AbortError' || version !== requestVersion) return;
            results.replaceChildren();
            status.textContent = 'Pretraga trenutno nije dostupna. Pokušajte ponovo.';
            status.dataset.state = 'error';
        } finally {
            if (version === requestVersion) {
                results.setAttribute('aria-busy', 'false');
                activeController = null;
            }
        }
    };

    const startSearch = (delay) => {
        window.clearTimeout(debounceTimer);
        const version = ++requestVersion;
        activeController?.abort();
        const term = input.value.trim();

        debounceTimer = window.setTimeout(() => fetchContacts(term, version), delay);
    };

    input.addEventListener('input', () => startSearch(250));
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        startSearch(0);
    });
})();
