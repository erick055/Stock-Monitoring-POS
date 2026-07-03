const shell = document.querySelector('.auth-shell');
const roleStep = document.querySelector('[data-role-step]');
const accountForm = document.querySelector('[data-account-form]');

if (shell && roleStep && accountForm) {
    let selectedRole = shell.dataset.oldRole || '';
    let mode = shell.dataset.oldMode || 'register';
    const baseUrl = shell.dataset.baseUrl || '';
    const roleButtons = [...document.querySelectorAll('[data-role]')];
    const createButton = document.querySelector('[data-open-register]');

    function renderForm() {
        const isRegister = mode === 'register';
        const nameField = document.querySelector('[data-name-field]');
        const confirmField = document.querySelector('[data-confirm-field]');
        const rememberField = document.querySelector('[data-remember-field]');
        const passwordInput = accountForm.querySelector('[name="password"]');

        accountForm.action = `${baseUrl}/${isRegister ? 'register' : 'login'}`;
        document.querySelector('[data-mode-input]').value = mode;
        document.querySelector('[data-role-label]').textContent = selectedRole.charAt(0).toUpperCase() + selectedRole.slice(1);
        document.querySelector('[data-role-input]').value = selectedRole;
        document.querySelector('[data-form-mode]').textContent = isRegister ? 'CREATE ACCOUNT' : 'WELCOME BACK';
        document.querySelector('[data-form-title]').textContent = isRegister ? 'registration' : 'login';
        document.querySelector('[data-form-copy]').textContent = isRegister ? 'Create your MotoSync account.' : 'Enter your credentials to continue.';
        document.querySelector('[data-submit-label]').textContent = isRegister ? 'Create account' : 'Log in';
        document.querySelector('[data-switch-text]').textContent = isRegister ? 'Already have an account?' : 'Need an account?';
        document.querySelector('[data-switch-mode]').textContent = isRegister ? 'Log in' : 'Create one';

        nameField.hidden = !isRegister;
        confirmField.hidden = !isRegister;
        rememberField.hidden = isRegister;
        nameField.querySelector('input').required = isRegister;
        confirmField.querySelector('input').required = isRegister;
        passwordInput.autocomplete = isRegister ? 'new-password' : 'current-password';
    }

    function openForm(nextMode) {
        if (!selectedRole) return;
        mode = nextMode;
        renderForm();
        roleStep.hidden = true;
        accountForm.hidden = false;
    }

    roleButtons.forEach((button) => button.addEventListener('click', () => {
        selectedRole = button.dataset.role;
        roleButtons.forEach((item) => item.classList.toggle('selected', item === button));
        createButton.disabled = false;
    }));

    createButton.addEventListener('click', () => openForm('register'));
    document.querySelector('[data-open-login]').addEventListener('click', () => openForm('login'));
    document.querySelector('[data-switch-mode]').addEventListener('click', () => openForm(mode === 'register' ? 'login' : 'register'));
    document.querySelector('[data-back]').addEventListener('click', () => {
        accountForm.hidden = true;
        roleStep.hidden = false;
    });

    if (selectedRole) {
        roleButtons.forEach((button) => button.classList.toggle('selected', button.dataset.role === selectedRole));
        createButton.disabled = false;
        openForm(mode);
    }
}