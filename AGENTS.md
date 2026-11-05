# Repository Guidelines

## Project Structure & Module Organization

This auto-glass storefront uses PHP 8.4.1+, Laravel 13, Livewire, Filament 5, PostgreSQL, and Vite/Tailwind. Application code lives in `app/`; shared catalog, pricing, identity, and ordering rules belong in `app/Domain/`. Keep Livewire components (`app/Livewire/`), administration (`app/Filament/`), and AI adapters (`app/Ai/`) focused on their interfaces and reuse domain services.

Blade templates, JavaScript, and CSS live in `resources/`. Routes are in `routes/`; migrations, factories, and seeders are in `database/`. Tests are split between `tests/Unit/`, `tests/Feature/`, and Playwright scenarios in `tests/Browser/`. Consult `README.md`, `MODERNIZATION_SPEC.md`, and `docs/modernization/` before changing modernization flows.

## Build, Test, and Development Commands

- `composer install` and `npm ci --ignore-scripts`: install locked dependencies.
- `composer dev`: run Laravel, default and AI queue workers, logs, and Vite locally.
- `npm run build`: compile production assets.
- `./scripts/test.ps1`: run PHPUnit with 512 MB memory; append `--filter SearchOffersTest` for targeted checks. Portable alternative: `composer test`.
- `php scripts/doctor.php --testing`: check the test environment.
- `vendor/bin/pint --dirty`: format changed PHP files.
- `npm run test:e2e`: run desktop/mobile Chromium checks against the isolated preview at `127.0.0.1:8015`; prepare and start it using README instructions.

On Windows without PHP in PATH, use `C:/php/php.exe`.

## Coding Style & Naming Conventions

Follow `.editorconfig`: UTF-8, LF, four spaces, final newline; YAML uses two spaces except Compose files. Follow Laravel/Pint conventions, PSR-4 namespaces, PascalCase classes, and camelCase methods. Match existing domain code with strict types and typed interfaces. Name PHPUnit files `*Test.php`, test methods `test_descriptive_behavior`, and browser scenarios `*.spec.js`.

## Testing Guidelines

Use PHPUnit 12 and model factories for domain/integration coverage; use Playwright for changed user journeys. Cover meaningful regressions, authorization, validation, and idempotency where relevant. No numeric coverage threshold is configured. Mock external HTTP calls; `Tests\TestCase` rejects stray requests.

Run database tests only against `avtoglasskz_test` through the guarded runner. Never run concurrent suites against the same database or bypass `TestDatabaseGuard`.

## Commit & Pull Request Guidelines

Recent commits use short, descriptive Russian subjects without a mandatory prefix. Keep changes focused. PRs should explain the problem, resulting behavior, validation performed, and migration/configuration impact; link relevant issues and include screenshots for UI changes.

## Configuration & Work Records

Preserve existing `.env`, application keys, and unrelated working changes. Never commit credentials. Avoid `composer setup` on existing environments: it regenerates the key and migrates the configured database. Keep preview data isolated. Record verified progress and remaining work in `docs/`; AI implementation checkpoints belong in `docs/AI-ASSISTANT-IMPLEMENTATION.md`.
