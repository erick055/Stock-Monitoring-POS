# MotoSync

MotoSync is a Laravel application for motorcycle parts inventory and point of sale.
It includes stock movements, product compatibility, supplier prices, sales analytics,
dead stock tracking, returns, and owner-managed staff accounts.

## Local setup

Requirements: PHP 8.2 or later supported by the locked dependencies, Composer,
Node.js with npm, and MySQL.

1. Run `composer install` and `npm ci`.
2. Copy `.env.example` to `.env` and configure the database and mail settings.
3. For a new installation, run `php artisan key:generate`.
4. Run `php artisan migrate`.
5. Follow [owner account setup](docs/owner-account-setup.md) to create an owner.
6. Run `npm run build`, then `php artisan serve`.

Keep `.env` private. Preserve the existing application key when updating an installation.

## Checks

```bash
php artisan test
node --test tests/js/*.test.mjs
npm run build
```

## Operations

- [Production security](docs/production-security.md)
- [Owner account setup](docs/owner-account-setup.md)
- [Background model updates](docs/ml-background-updates.md)
- [Product demand forecasting](docs/product-demand-machine-learning.md)
- [Catalog scale test results](docs/catalog-scale-test-2026-10-19.md)

The application uses Laravel and other open source packages. Their license notices
remain in the installed dependencies.
