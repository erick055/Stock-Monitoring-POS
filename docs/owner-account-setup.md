# Set up owner login

Open PowerShell and run:

```powershell
cd C:\xampp\htdocs\stock-pos
php artisan owner:setup
```

Enter the owner email, owner name, password, and password confirmation when asked.
Password input is hidden. Use at least 12 characters including uppercase,
lowercase, a number, and a symbol. Credentials are chosen by you; the app does
not contain a shared default password.

The command creates an active owner account and treats the locally provisioned
email as verified. If that email already belongs to an owner, the command asks
before resetting its password and revokes existing sessions and trusted devices.
It refuses to convert an existing staff account into an owner.

Owners log in with their email and password and go directly to the owner
dashboard. They do not receive an email code or need email delivery for login.
Staff still use the existing email-code and trusted-device flow.

To reset owner credentials later, run the same command and enter the same owner
email. This changes the password, not the email of a different existing owner.
