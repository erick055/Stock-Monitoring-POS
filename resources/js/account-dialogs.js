const initializedAccountDialogs = new WeakSet();

function openAccountDialog(dialog) {
    if (!dialog || dialog.open) return;
    dialog.showModal();
    document.body.classList.add('modal-open');
    dialog.querySelector('input:not([type="hidden"])')?.focus();
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-open-account-dialog]');
    if (button) {
        const dialog = document.getElementById(button.dataset.openAccountDialog);
        if (dialog?.matches('[data-account-dialog]')) openAccountDialog(dialog);
    }
});

function initializeAccountDialogs() {
    const accountDialogs = [...document.querySelectorAll('[data-account-dialog]')].filter(dialog => !initializedAccountDialogs.has(dialog));
    accountDialogs.forEach((dialog) => {
        initializedAccountDialogs.add(dialog);
        dialog.querySelectorAll('[data-close-account-dialog]').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('close', () => {
            dialog.querySelector('form')?.reset();
            dialog.querySelectorAll('[data-password-field]').forEach((field) => {
                const input = field.querySelector('input');
                input.value = '';
                input.type = 'password';
                const toggle = field.querySelector('[data-password-toggle]');
                toggle.setAttribute('aria-label', 'Show password');
                toggle.setAttribute('aria-pressed', 'false');
                toggle.title = 'Show password';
                toggle.querySelector('.bi').className = 'bi bi-eye';
            });
            if (!document.querySelector('[data-account-dialog][open]')) document.body.classList.remove('modal-open');
        });
    });

    openAccountDialog(accountDialogs.find((dialog) => dialog.dataset.openOnError === 'true'));
}
initializeAccountDialogs();
document.addEventListener('motosync:table-updated', initializeAccountDialogs);
