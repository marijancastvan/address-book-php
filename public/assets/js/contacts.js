(() => {
    const appBase = document.body?.dataset.appBase ?? '';
    const form = document.querySelector('.contact-search');
    const input = document.getElementById('contact-search');
    const results = document.getElementById('contact-results');
    const status = document.getElementById('contact-search-status');
    const pagination = document.getElementById('contacts-pagination');
    const filterNames = ['search', 'city_id', 'tag_id', 'date_from', 'date_to'];

    if (!form || !input || !results) return;

    const columns = [
        ['first_name', 'Ime'],
        ['last_name', 'Prezime'],
        ['phone', 'Telefon'],
        ['email', 'Email'],
        ['city_name', 'Grad'],
        ['tags', 'Tagovi'],
    ];
    let debounceTimer = null;
    let requestVersion = 0;
    let activeController = null;

    const readFilters = () => Object.fromEntries(filterNames.map((name) => {
        const control = form.elements.namedItem(name);
        return [name, control?.value?.trim() ?? ''];
    }));

    const filtersFromUrl = (url) => Object.fromEntries(filterNames.map((name) => [
        name,
        url.searchParams.get(name) ?? '',
    ]));

    const setFormFilters = (filters) => {
        for (const name of filterNames) {
            const control = form.elements.namedItem(name);
            if (control) control.value = filters[name] ?? '';
        }
    };

    const buildPageUrl = (page, filters, extra = {}) => {
        const params = new URLSearchParams();
        for (const name of filterNames) {
            if (filters[name] !== '') params.set(name, filters[name]);
        }
        params.set('page', String(page));
        for (const [name, value] of Object.entries(extra)) params.set(name, value);
        return `${appBase}/contacts.php?${params.toString()}`;
    };

    const createCell = (tag, label, value) => {
        const cell = document.createElement(tag);
        cell.dataset.label = label;
        cell.textContent = value ?? '';
        return cell;
    };

    const createPaginationLink = (label, page, filters, options = {}) => {
        const link = document.createElement('a');
        link.className = 'pagination-link';
        link.textContent = label;
        link.href = buildPageUrl(page, filters);
        if (options.current) {
            link.classList.add('is-current');
            link.setAttribute('aria-current', 'page');
        }
        if (options.rel) link.rel = options.rel;
        link.dataset.page = String(page);
        return link;
    };

    const renderPagination = (metadata, filters) => {
        if (!pagination) return;

        const currentPage = Number(metadata.current_page) || 1;
        const totalPages = Number(metadata.total_pages) || 1;
        pagination.replaceChildren();
        pagination.hidden = totalPages <= 1;
        if (pagination.hidden) return;

        if (currentPage > 1) {
            pagination.append(createPaginationLink('Prethodna', currentPage - 1, filters, { rel: 'prev' }));
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
            pagination.append(createPaginationLink('1', 1, filters));
            if (startPage > 2) appendEllipsis();
        }
        for (let page = startPage; page <= endPage; page += 1) {
            pagination.append(createPaginationLink(String(page), page, filters, {
                current: page === currentPage,
            }));
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) appendEllipsis();
            pagination.append(createPaginationLink(String(totalPages), totalPages, filters));
        }

        if (currentPage < totalPages) {
            pagination.append(createPaginationLink('Sledeća', currentPage + 1, filters, { rel: 'next' }));
        } else {
            const next = document.createElement('span');
            next.className = 'pagination-link is-disabled';
            next.textContent = 'Sledeća';
            next.setAttribute('aria-disabled', 'true');
            pagination.append(next);
        }
    };

    const renderContacts = (contacts, metadata, filters) => {
        results.replaceChildren();
        renderPagination(metadata, filters);

        if (contacts.length === 0) {
            const emptyState = document.createElement('section');
            emptyState.className = 'empty-state';
            const heading = document.createElement('h2');
            const message = document.createElement('p');
            const hasFilters = filterNames.some((name) => filters[name] !== '');
            if (!hasFilters) {
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
                message.textContent = 'Nema kontakata koji odgovaraju izabranim kriterijumima.';
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
                if (key === 'tags') {
                    const cell = document.createElement('td');
                    cell.dataset.label = label;
                    const tagList = document.createElement('div');
                    tagList.className = 'contact-tags';
                    const tags = Array.isArray(contact.tags) ? contact.tags : [];
                    if (tags.length === 0) {
                        const empty = document.createElement('span');
                        empty.className = 'contact-tag-empty';
                        empty.textContent = '—';
                        tagList.append(empty);
                    } else {
                        for (const tag of tags) {
                            const badge = document.createElement('span');
                            badge.className = 'contact-tag';
                            badge.textContent = typeof tag.name === 'string' ? tag.name : '';
                            tagList.append(badge);
                        }
                    }
                    cell.append(tagList);
                    row.append(cell);
                    continue;
                }
                row.append(createCell('td', label, contact[key]));
            }

            const actionsCell = document.createElement('td');
            actionsCell.dataset.label = 'Akcije';
            const actions = document.createElement('div');
            actions.className = 'row-actions';
            const edit = document.createElement('a');
            edit.className = 'button button-small button-secondary';
            edit.textContent = 'Izmeni';
            edit.href = buildPageUrl(metadata.current_page, filters, { edit_id: String(contact.id) });

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

    const fetchContacts = async (filters, page, version) => {
        const controller = new AbortController();
        activeController = controller;
        results.setAttribute('aria-busy', 'true');
        status.textContent = 'Učitavanje kontakata…';
        status.dataset.state = 'loading';

        const query = new URLSearchParams();
        for (const name of filterNames) {
            if (filters[name] !== '') query.set(name, filters[name]);
        }
        query.set('page', String(page));
        query.set('format', 'json');

        try {
            const response = await fetch(`${appBase}/contacts.php?${query.toString()}`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json();

            if (version !== requestVersion) return;
            if (!response.ok) {
                const message = typeof payload.error === 'string' ? payload.error : payload.error?.message;
                throw new Error(message || 'Filtriranje kontakata nije uspelo.');
            }
            if (!Array.isArray(payload.contacts) || !payload.pagination) {
                throw new Error('Odgovor servera nije ispravan. Pokušajte ponovo.');
            }

            renderContacts(payload.contacts, payload.pagination, filters);
            history.replaceState({}, '', buildPageUrl(payload.pagination.current_page, filters));
            status.textContent = '';
            status.removeAttribute('data-state');
        } catch (error) {
            if (error.name === 'AbortError' || version !== requestVersion) return;
            status.textContent = error.message || 'Pretraga trenutno nije dostupna. Pokušajte ponovo.';
            status.dataset.state = 'error';
        } finally {
            if (version === requestVersion) {
                results.setAttribute('aria-busy', 'false');
                activeController = null;
            }
        }
    };

    const request = (filters, page, delay, historyMode = 'push') => {
        window.clearTimeout(debounceTimer);
        const version = ++requestVersion;
        activeController?.abort();
        const destination = buildPageUrl(page, filters);
        if (destination !== `${window.location.pathname}${window.location.search}`) {
            history[`${historyMode}State`]({}, '', destination);
        }
        debounceTimer = window.setTimeout(() => fetchContacts(filters, page, version), delay);
    };

    form.addEventListener('input', (event) => {
        if (event.target === input) request(readFilters(), 1, 250);
    });
    form.addEventListener('change', (event) => {
        if (event.target !== input) request(readFilters(), 1, 0);
    });
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        request(readFilters(), 1, 0);
    });
    pagination?.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-page]');
        if (!link) return;
        event.preventDefault();
        request(readFilters(), Number(link.dataset.page) || 1, 0);
    });

    window.addEventListener('popstate', () => {
        const url = new URL(window.location.href);
        const filters = filtersFromUrl(url);
        setFormFilters(filters);
        request(filters, Number(url.searchParams.get('page')) || 1, 0, 'replace');
    });
})();
