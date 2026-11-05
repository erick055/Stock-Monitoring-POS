import '../css/password-visibility.css';

const initializedPasswordFields = new WeakSet();
function initializePasswordFields() {
    document.querySelectorAll('[data-password-field]').forEach((field) => {
        if (initializedPasswordFields.has(field)) return;
        const input = field.querySelector('input');
        const toggle = field.querySelector('[data-password-toggle]');
        if (!input || !toggle) return;
        initializedPasswordFields.add(field);

        toggle.hidden = false;
        field.classList.add('has-password-toggle');
        toggle.addEventListener('click', () => {
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            const icon = toggle.querySelector('.bi');
            icon.classList.toggle('bi-eye', !visible);
            icon.classList.toggle('bi-eye-slash', visible);
            toggle.title = visible ? 'Hide password' : 'Show password';
            toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
            toggle.setAttribute('aria-pressed', String(visible));
        });
    });
}
initializePasswordFields();
document.addEventListener('motosync:table-updated', initializePasswordFields);
