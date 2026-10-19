'use strict';

const duplicateConsent = document.querySelector('input[name="include_duplicates"]');

if (duplicateConsent instanceof HTMLInputElement) {
    const consentMessage = 'Potvrdite da razumete da će mogući duplikati biti uvezeni.';

    const updateConsentValidity = () => {
        duplicateConsent.setCustomValidity(duplicateConsent.checked ? '' : consentMessage);
    };

    updateConsentValidity();
    duplicateConsent.addEventListener('change', updateConsentValidity);
}
