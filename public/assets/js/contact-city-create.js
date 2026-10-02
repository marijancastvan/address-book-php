(() => {
    const createDialog = document.getElementById('contact-create-dialog');
    const contactForm = createDialog?.querySelector('form.contact-form');
    const cityInput = contactForm?.querySelector('[data-city-name-input]');
    const cityIdInput = contactForm?.querySelector('[data-city-id-value]');

    if (!createDialog || !contactForm || !cityInput || !cityIdInput) return;

    const cityList = document.getElementById('contact-create-city-options');
    const addCityButton = contactForm.querySelector('[data-city-create-open]');
    const cityError = document.getElementById('contact-create-city_id-error');
    const cityStatus = document.getElementById('contact-create-city-status');
    const confirmationDialog = document.getElementById('contact-city-create-dialog');
    const confirmationMessage = confirmationDialog?.querySelector('[data-city-create-confirm-message]');
    const confirmButton = confirmationDialog?.querySelector('[data-city-create-confirm]');

    if (!cityList || !addCityButton || !cityError || !cityStatus || !confirmationDialog || !confirmationMessage || !confirmButton) return;

    let isCreating = false;
    let searchTimer = null;
    let searchController = null;
    let searchVersion = 0;
    let activeOptionIndex = -1;

    const setCityError = (message) => {
        cityError.textContent = message;
        cityError.hidden = message === '';
        cityInput.classList.toggle('is-invalid', message !== '');
        if (message === '') cityInput.removeAttribute('aria-invalid');
        else cityInput.setAttribute('aria-invalid', 'true');
    };

    const setCityStatus = (message, state = '') => {
        cityStatus.textContent = message;
        if (state === '') cityStatus.removeAttribute('data-state');
        else cityStatus.dataset.state = state;
    };

    const hideCityList = () => {
        cityList.hidden = true;
        cityInput.setAttribute('aria-expanded', 'false');
        cityInput.removeAttribute('aria-activedescendant');
        activeOptionIndex = -1;
    };

    const setActiveOption = (index) => {
        const options = [...cityList.querySelectorAll('.city-picker-option')];
        if (options.length === 0) return;

        activeOptionIndex = (index + options.length) % options.length;
        options.forEach((option, optionIndex) => {
            const isActive = optionIndex === activeOptionIndex;
            option.classList.toggle('is-active', isActive);
            option.setAttribute('aria-selected', String(isActive));
        });
        cityInput.setAttribute('aria-activedescendant', options[activeOptionIndex].id);
        options[activeOptionIndex].scrollIntoView({ block: 'nearest' });
    };

    const showCityList = () => {
        cityList.hidden = false;
        cityInput.setAttribute('aria-expanded', 'true');
    };

    const renderCityResults = (cities) => {
        cityList.replaceChildren();
        if (cities.length === 0) {
            const emptyMessage = document.createElement('p');
            emptyMessage.className = 'city-picker-empty';
            emptyMessage.textContent = 'Nema gradova koji odgovaraju pretrazi.';
            cityList.append(emptyMessage);
            showCityList();
            return;
        }

        for (const city of cities) {
            if (!city || !Number.isInteger(Number(city.id)) || typeof city.name !== 'string') continue;
            const option = document.createElement('button');
            option.className = 'city-picker-option';
            option.type = 'button';
            option.role = 'option';
            option.id = `contact-create-city-option-${Number(city.id)}`;
            option.tabIndex = -1;
            option.setAttribute('aria-selected', 'false');
            option.dataset.cityId = String(city.id);
            option.dataset.cityName = city.name;
            option.textContent = city.name;
            cityList.append(option);
        }

        if (cityList.childElementCount === 0) {
            const emptyMessage = document.createElement('p');
            emptyMessage.className = 'city-picker-empty';
            emptyMessage.textContent = 'Nema gradova koji odgovaraju pretrazi.';
            cityList.append(emptyMessage);
        }
        showCityList();
    };

    const searchCities = async (term, version) => {
        searchController?.abort();
        searchController = new AbortController();
        const controller = searchController;
        cityList.replaceChildren();
        const loadingMessage = document.createElement('p');
        loadingMessage.className = 'city-picker-empty';
        loadingMessage.textContent = 'Pretraga...';
        cityList.append(loadingMessage);
        showCityList();

        try {
            const query = new URLSearchParams({ format: 'json', search: term });
            const response = await fetch(`/cities.php?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });
            const payload = await response.json();
            if (version !== searchVersion || controller.signal.aborted) return;
            if (!response.ok || !Array.isArray(payload.cities)) {
                throw new Error(payload.error || 'Gradovi trenutno nisu dostupni. Pokušajte ponovo.');
            }
            renderCityResults(payload.cities);
        } catch (error) {
            if (error.name === 'AbortError' || version !== searchVersion) return;
            cityList.replaceChildren();
            hideCityList();
            setCityStatus(error.message || 'Gradovi trenutno nisu dostupni. Pokušajte ponovo.', 'error');
        }
    };

    const scheduleCitySearch = () => {
        cityIdInput.value = '';
        addCityButton.disabled = cityInput.value.trim() === '' || isCreating;
        setCityError('');
        setCityStatus('');
        searchVersion += 1;
        const version = searchVersion;
        clearTimeout(searchTimer);
        searchController?.abort();
        cityList.replaceChildren();
        hideCityList();

        const term = cityInput.value.trim();
        if (term === '') {
            return;
        }

        searchTimer = setTimeout(() => searchCities(term, version), 250);
    };

    const selectCity = (option) => {
        cityInput.value = option.dataset.cityName;
        cityIdInput.value = option.dataset.cityId;
        cityList.replaceChildren();
        hideCityList();
        setCityError('');
        setCityStatus('');
        addCityButton.disabled = isCreating;
        cityInput.focus();
    };

    cityInput.addEventListener('input', scheduleCitySearch);
    cityList.addEventListener('click', (event) => {
        const option = event.target.closest('[data-city-id][data-city-name]');
        if (option && cityList.contains(option)) selectCity(option);
    });
    cityList.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && cityInput.getAttribute('aria-expanded') === 'true') {
            event.preventDefault();
            event.stopPropagation();
            hideCityList();
            cityInput.focus();
        }
    });
    cityInput.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            if (cityInput.getAttribute('aria-expanded') === 'true') {
                event.preventDefault();
                event.stopPropagation();
                hideCityList();
                cityInput.focus();
            }
            return;
        }
        const options = [...cityList.querySelectorAll('.city-picker-option')];
        if (options.length === 0) return;
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveOption(activeOptionIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveOption(activeOptionIndex < 0 ? options.length - 1 : activeOptionIndex - 1);
        } else if (event.key === 'Enter' && activeOptionIndex >= 0) {
            event.preventDefault();
            selectCity(options[activeOptionIndex]);
        }
    });
    document.addEventListener('click', (event) => {
        if (!cityInput.closest('.city-picker-control')?.contains(event.target)) hideCityList();
    });

    addCityButton.addEventListener('click', () => {
        const cityName = cityInput.value.trim();
        if (cityName === '') return;
        hideCityList();
        confirmationMessage.textContent = `Da li želite da dodate mesto "${cityName}"?`;
    });

    contactForm.addEventListener('submit', (event) => {
        if (cityIdInput.value !== '') return;
        event.preventDefault();
        setCityError('Izaberite grad iz rezultata pretrage ili ga dodajte kao novo mesto.');
        cityInput.focus();
    });

    confirmButton.addEventListener('click', async () => {
        if (isCreating) return;

        const cityName = cityInput.value.trim();
        if (cityName === '') {
            confirmationDialog.close();
            setCityError('Naziv grada je obavezan.');
            cityInput.focus();
            return;
        }

        isCreating = true;
        confirmButton.disabled = true;
        addCityButton.disabled = true;
        confirmButton.textContent = 'Dodavanje...';
        confirmationDialog.setAttribute('aria-busy', 'true');
        setCityStatus('Dodavanje mesta...', 'info');

        try {
            const response = await fetch('/city-create.php?format=json', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                },
                credentials: 'same-origin',
                body: new URLSearchParams({ name: cityName }).toString(),
            });
            const payload = await response.json();

            if (!response.ok || !payload.city || !['created', 'exists'].includes(payload.status)) {
                throw new Error(payload.errors?.name || payload.error || 'Mesto trenutno nije moguće dodati. Pokušajte ponovo.');
            }

            cityInput.value = payload.city.name;
            cityIdInput.value = String(payload.city.id);
            cityList.replaceChildren();
            hideCityList();
            setCityError('');
            setCityStatus(payload.message, payload.status === 'created' ? 'success' : 'info');
            confirmationDialog.close();
        } catch (error) {
            setCityStatus(error.message || 'Mesto trenutno nije moguće dodati. Pokušajte ponovo.', 'error');
            confirmationDialog.close();
        } finally {
            isCreating = false;
            confirmButton.disabled = false;
            confirmButton.textContent = 'Dodaj mesto';
            confirmationDialog.removeAttribute('aria-busy');
            addCityButton.disabled = cityInput.value.trim() === '';
        }
    });

    addCityButton.disabled = cityInput.value.trim() === '';
})();
