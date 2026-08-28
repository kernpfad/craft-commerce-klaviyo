# Contributing

## Code quality tooling

This repo ships configuration for [`craftcms/ecs`](https://github.com/craftcms/ecs),
[`phpstan/phpstan`](https://github.com/phpstan/phpstan) (^2.2) and
[`craftcms/rector`](https://github.com/craftcms/rector) (`dev-craft6`, Rector 2).

```sh
composer install
composer check
```

`composer check` runs ECS, PHPStan (level 8), Rector dry-run, and unit tests.
Individual scripts:

| Script | Purpose |
|---|---|
| `composer check-cs` / `composer fix-cs` | Easy Coding Standard |
| `composer phpstan` | Static analysis |
| `composer rector` / `composer rector:fix` | Rector dry-run / apply |
| `composer test:unit` | Unit tests (no Craft boot) |
| `composer test:integration` | Integration tests (needs Craft + DB) |

All of ECS, PHPStan and Rector must pass clean before a release. Pull requests
should keep `composer check` green.

## Tests

```sh
composer test:unit
composer test:integration
```

Unit tests run without booting Craft. Integration tests boot a real Craft
application and exercise the plugin against real records, so they need a
configured test database.

## Local development

Install the plugin into a Craft 5 site through a Composer path repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../craft-commerce-klaviyo" }
    ]
}
```

```sh
composer require kernpfad/craft-commerce-klaviyo:@dev
php craft plugin/install commerce-klaviyo
```

Local checks run automatically via a git hook that `composer install`
wires up for you (`git config core.hooksPath .githooks`):

- `.githooks/pre-commit` runs `composer check` on every commit that touches
  PHP/tooling files.
- `.githooks/pre-push` runs `composer test:integration` if
  `CRAFT_TEST_SITE_PATH` is set, against the shared local Craft + Commerce
  site described in `craft-plugin-blueprint`'s `BLUEPRINT.md`:

  ```sh
  export CRAFT_TEST_SITE_PATH=~/projects/kernpfad/craft-test-site
  ```

## Branches

Trunk-based: `main` is always release-ready. No long-lived `develop` branch.

| Prefix | Use for |
|---|---|
| `feature/…` | New behaviour |
| `fix/…` | Bug fixes |
| `chore/…` | Tooling, docs, cleanup |
| `release/x.y.z` | Parallel release work only when needed |

Name form: `prefix/kurz-kebab-case` (e.g. `feature/list-fields`, `fix/bulk-catalog-timeout`).
Prefer `feature/` over `feat/`. Temporary Cloud Agent branches may use `cursor/…`; rename before opening the PR when practical.

Open a PR into `main`. Squash-merge is the only enabled merge method; GitHub deletes the head branch after merge.

## Pull requests

Use the PR template. Update `CHANGELOG.md` when behaviour changes. Keep `composer check` green; CI runs Quality on PHP 8.2–8.4.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md).
