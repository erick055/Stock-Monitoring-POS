const shell = document.querySelector('.auth-shell');
const accountForm = document.querySelector('[data-account-form]');

if (shell && accountForm) {
    let mode = shell.dataset.oldMode === 'register' ? 'register' : 'login';
    const baseUrl = shell.dataset.baseUrl || '';
    const nameField = document.querySelector('[data-name-field]');
    const confirmField = document.querySelector('[data-confirm-field]');
    const rememberField = document.querySelector('[data-remember-field]');
    const passwordHint = document.querySelector('[data-password-hint]');
    const passwordInput = accountForm.querySelector('[name="password"]');

    function renderForm() {
        const isRegister = mode === 'register';

        accountForm.action = `${baseUrl}/${isRegister ? 'register' : 'login'}`;
        document.querySelector('[data-mode-input]').value = mode;
        document.querySelector('[data-form-mode]').textContent = isRegister ? 'CREATE STAFF ACCOUNT' : 'WELCOME BACK';
        document.querySelector('[data-form-title]').textContent = isRegister ? 'Register for MotoSync' : 'Log in to MotoSync';
        document.querySelector('[data-form-copy]').textContent = isRegister
            ? 'Create a staff account using an email address you can verify.'
            : 'Use your registered email address and password.';
        document.querySelector('[data-submit-label]').textContent = isRegister ? 'Create account' : 'Log in';
        document.querySelector('[data-switch-text]').textContent = isRegister ? 'Already registered?' : 'Need a staff account?';
        document.querySelector('[data-switch-mode]').textContent = isRegister ? 'Log in' : 'Create one';

        nameField.hidden = !isRegister;
        confirmField.hidden = !isRegister;
        passwordHint.hidden = !isRegister;
        rememberField.hidden = isRegister;
        nameField.querySelector('input').required = isRegister;
        confirmField.querySelector('input').required = isRegister;
        passwordInput.autocomplete = isRegister ? 'new-password' : 'current-password';
    }

    document.querySelector('[data-switch-mode]').addEventListener('click', () => {
        mode = mode === 'register' ? 'login' : 'register';
        renderForm();
    });

    renderForm();
}
