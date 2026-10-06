(() => {
    const appBase = document.body?.dataset.appBase ?? '';
    const input = document.getElementById('city-search');
    const results = document.getElementById('city-results');
    const status = document.getElementById('city-search-status');
    const pagination = document.getElementById('cities-pagination');

    if (!input || !results) return;

    let debounceTimer = null;
    let requestVersion = 0;
    let activeController = null;
    const buildPageUrl = (page, term, extra = {}) => {
        const query = new URLSearchParams({ page: String(page), ...extra });
        if (term !== '') query.set('search', term);
        return `${appBase}/cities.php?${query.toString()}`;
    };

    const createPaginationLink = (label, page, term, options = {}) => {
        const link = document.createElement('a');
        link.className = 'pagination-link';
        link.textContent = label;
        link.href = buildPageUrl(page, term);
        link.dataset.page = String(page);
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
            const link = createPaginationLink(String(page), page, term);
            if (page === currentPage) {
                link.classList.add('is-current');
                link.setAttribute('aria-current', 'page');
            }
            pagination.append(link);
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

    const renderCities = (cities, metadata, term) => {
        results.replaceChildren();
        renderPagination(metadata, term);

        if (cities.length === 0) {
            const emptyState = document.createElement('section');
            emptyState.className = 'empty-state';
            const heading = document.createElement('h2');
            const message = document.createElement('p');

            if (input.value.trim() === '') {
                heading.textContent = 'Još nema gradova';
                message.textContent = 'Trenutno nemate nijedan grad.';
                const addButton = document.createElement('button');
                addButton.className = 'button button-primary';
                addButton.type = 'button';
                addButton.textContent = 'Dodaj novi grad';
                addButton.dataset.dialogOpen = 'city-create-dialog';
                emptyState.append(heading, message, addButton);
            } else {
                heading.textContent = 'Nema rezultata';
                message.textContent = 'Nema gradova koji odgovaraju pretrazi.';
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

        for (const label of ['Naziv grada', 'Akcije']) {
            const heading = document.createElement('th');
            heading.scope = 'col';
            heading.textContent = label;
            headingRow.append(heading);
        }
        head.append(headingRow);

        const body = document.createElement('tbody');
        for (const city of cities) {
            const row = document.createElement('tr');
            const nameCell = document.createElement('td');
            nameCell.dataset.label = 'Naziv grada';
            nameCell.textContent = city.name ?? '';

            const actionsCell = document.createElement('td');
            actionsCell.dataset.label = 'Akcije';
            const actions = document.createElement('div');
            actions.className = 'row-actions';

            const edit = document.createElement('a');
            edit.className = 'button button-small button-secondary';
            edit.textContent = 'Izmeni';
            edit.href = buildPageUrl(metadata.current_page, term, { edit_id: String(city.id) });

            const remove = document.createElement('button');
            remove.className = 'button button-small button-danger';
            remove.type = 'button';
            remove.textContent = 'Izbriši';
            remove.dataset.confirmOpen = 'city-delete-dialog';
            remove.dataset.deleteId = String(city.id);
            remove.dataset.deleteLabel = city.name ?? '';

            actions.append(edit, remove);
            actionsCell.append(actions);
            row.append(nameCell, actionsCell);
            body.append(row);
        }

        table.append(head, body);
        wrapper.append(table);
        results.append(wrapper);
    };

    const fetchCities = async (term, page, version) => {
        const controller = new AbortController();
        activeController = controller;
        results.setAttribute('aria-busy', 'true');
        if (status) {
            status.textContent = 'Pretraga...';
            status.dataset.state = 'loading';
        }

        const query = new URLSearchParams({ search: term, page: String(page), format: 'json' });

        try {
            const response = await fetch(`${appBase}/cities.php?${query.toString()}`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json();

            if (version !== requestVersion) return;
            if (!response.ok || !Array.isArray(payload.cities) || !payload.pagination) {
                throw new Error(payload.error || 'Live pretraga nije uspela.');
            }

            renderCities(payload.cities, payload.pagination, term);
            if (status) {
                status.textContent = '';
                status.removeAttribute('data-state');
            }
        } catch (error) {
            if (error.name === 'AbortError' || version !== requestVersion) return;
            results.replaceChildren();
            pagination?.replaceChildren();
            if (pagination) pagination.hidden = true;
            if (status) {
                status.textContent = 'Pretraga trenutno nije dostupna. Pokušajte ponovo.';
                status.dataset.state = 'error';
            }
        } finally {
            if (version === requestVersion) {
                results.setAttribute('aria-busy', 'false');
                activeController = null;
            }
        }
    };

    input.addEventListener('input', () => {
        window.clearTimeout(debounceTimer);
        const version = ++requestVersion;
        activeController?.abort();
        const term = input.value.trim();
        debounceTimer = window.setTimeout(() => fetchCities(term, 1, version), 250);
    });

    pagination?.addEventListener('click', (event) => {
        const link = event.target.closest('a[data-page]');
        if (!link) return;

        event.preventDefault();
        window.clearTimeout(debounceTimer);
        const version = ++requestVersion;
        activeController?.abort();
        fetchCities(input.value.trim(), Number(link.dataset.page) || 1, version);
    });
})();
