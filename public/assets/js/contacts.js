(() => {
    const appBase = document.body?.dataset.appBase ?? '';
    const form = document.querySelector('.contact-search');
    const input = document.getElementById('contact-search');
    const results = document.getElementById('contact-results');
    const status = document.getElementById('contact-search-status');
    const pagination = document.getElementById('contacts-pagination');

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

    const buildPageUrl = (page, term, extra = {}) => {
        const params = new URLSearchParams({ page: String(page), ...extra });
        if (term !== '') params.set('search', term);
        return `${appBase}/contacts.php?${params.toString()}`;
    };

    const createPaginationLink = (label, page, term, options = {}) => {
        const link = document.createElement('a');
        link.className = 'pagination-link';
        link.textContent = label;
        link.href = buildPageUrl(page, term);
        if (options.current) {
            link.classList.add('is-current');
            link.setAttribute('aria-current', 'page');
        }
        if (options.rel) link.rel = options.rel;
        return link;
    };

    const renderPagination = (metadata, term) => {
        if (!pagination) return;

        const currentPage = Number(metadata.current_page) || 1;
        const totalPages = Number(metadata.total_pages) || 1;
        pagination.replaceChildren();
        pagination.hidden = totalPages <= 1;
        if (pagination.hidden) return;

        if (currentPage > 1) {
            pagination.append(createPaginationLink('Prethodna', currentPage - 1, term, { rel: 'prev' }));
        } else {
            const previous = document.createElement('span');
            previous.className = 'pagination-link is-disabled';
            previous.textContent = 'Prethodna';
            previous.setAttribute('aria-disabled', 'true');
            pagination.append(previous);
        }

        const startPage = Math.max(1, currentPage - 2);
        const endPage = Math.min(totalPages, currentPage + 2);
        const appendEllipsis = () => {
            const ellipsis = document.createElement('span');
            ellipsis.className = 'pagination-ellipsis';
            ellipsis.setAttribute('aria-hidden', 'true');
            ellipsis.textContent = '…';
            pagination.append(ellipsis);
        };

        if (startPage > 1) {
            pagination.append(createPaginationLink('1', 1, term));
            if (startPage > 2) appendEllipsis();
        }
        for (let page = startPage; page <= endPage; page += 1) {
            pagination.append(createPaginationLink(String(page), page, term, {
                current: page === currentPage,
            }));
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) appendEllipsis();
            pagination.append(createPaginationLink(String(totalPages), totalPages, term));
        }

        if (currentPage < totalPages) {
            pagination.append(createPaginationLink('Sledeća', currentPage + 1, term, { rel: 'next' }));
        } else {
            const next = document.createElement('span');
            next.className = 'pagination-link is-disabled';
            next.textContent = 'Sledeća';
            next.setAttribute('aria-disabled', 'true');
            pagination.append(next);
        }
    };

    const renderContacts = (contacts, metadata, term) => {
        results.replaceChildren();
        renderPagination(metadata, term);

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
            edit.href = buildPageUrl(metadata.current_page, term, { edit_id: String(contact.id) });

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

    const fetchContacts = async (term, page, version) => {
        const controller = new AbortController();
        activeController = controller;
        results.setAttribute('aria-busy', 'true');
        status.textContent = 'Pretraga...';
        status.dataset.state = 'loading';

        const query = new URLSearchParams({ search: term, page: String(page), format: 'json' });

        try {
            const response = await fetch(`${appBase}/contacts.php?${query.toString()}`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json();

            if (version !== requestVersion) return;
            if (!response.ok || !Array.isArray(payload.contacts) || !payload.pagination) {
                throw new Error(payload.error || 'Live pretraga nije uspela.');
            }

            renderContacts(payload.contacts, payload.pagination, term);
            status.textContent = '';
            status.removeAttribute('data-state');
        } catch (error) {
            if (error.name === 'AbortError' || version !== requestVersion) return;
            results.replaceChildren();
            pagination?.replaceChildren();
            if (pagination) pagination.hidden = true;
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

        debounceTimer = window.setTimeout(() => fetchContacts(term, 1, version), delay);
    };

    input.addEventListener('input', () => startSearch(250));
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        startSearch(0);
    });
})();
