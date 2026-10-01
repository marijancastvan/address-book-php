(() => {
    const input = document.getElementById('city-search');
    const results = document.getElementById('city-results');
    const status = document.getElementById('city-search-status');

    if (!input || !results) return;

    let debounceTimer = null;
    let requestVersion = 0;
    let activeController = null;

    const renderCities = (cities) => {
        results.replaceChildren();

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
            edit.href = `/cities.php?edit_id=${encodeURIComponent(city.id)}&search=${encodeURIComponent(input.value.trim())}`;

            const remove = document.createElement('button');
            remove.className = 'button button-small button-danger';
            remove.type = 'button';
            remove.textContent = 'Izbriši';
            remove.dataset.confirmOpen = 'city-delete-dialog';
            remove.dataset.deleteId = String(city.id);

            actions.append(edit, remove);
            actionsCell.append(actions);
            row.append(nameCell, actionsCell);
            body.append(row);
        }

        table.append(head, body);
        wrapper.append(table);
        results.append(wrapper);
    };

    const fetchCities = async (term, version) => {
        const controller = new AbortController();
        activeController = controller;
        results.setAttribute('aria-busy', 'true');
        if (status) status.textContent = '';

        const query = new URLSearchParams({ search: term, format: 'json' });

        try {
            const response = await fetch(`/cities.php?${query.toString()}`, {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json();

            if (version !== requestVersion) return;
            if (!response.ok || !Array.isArray(payload.cities)) {
                throw new Error(payload.error || 'Live pretraga nije uspela.');
            }

            renderCities(payload.cities);
        } catch (error) {
            if (error.name === 'AbortError' || version !== requestVersion) return;
            results.replaceChildren();
            if (status) status.textContent = 'Pretraga trenutno nije dostupna. Pokušajte ponovo.';
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
        debounceTimer = window.setTimeout(() => fetchCities(term, version), 250);
    });
})();
