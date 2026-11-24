# opencolibri/starter-dev-kit

Castor tasks to build a local Sylius shop little by little. `starter.yaml` holds the wanted state of the shop (dataset,
theme and header, name, locales, currencies, plugins), and `castor dev-kit:apply` changes only what differs from the
running instance, showing what each step costs before it runs.

Developed in the [colibri-starter](https://github.com/opencolibri/colibri-starter) monorepo (`src/DevKit`), split into
the read-only [opencolibri/starter-dev-kit](https://github.com/opencolibri/starter-dev-kit) repository. See the
[dev-kit section of the documentation](https://starter.opencolibri.org/docs/dev-kit/).

```bash
castor dev-kit:init              # write starter.yaml from what the shop runs now
castor dev-kit:apply --dry-run   # show the plan
castor dev-kit:apply             # run it
castor dev-kit:status            # what the shop runs now (--format=json)
```

| Task | What it does |
|---|---|
| `dev-kit:status` | Reads the instance: theme and header from the theme hooks, plugins from `composer.json`, name, locales and currencies of the default channel from the database |
| `dev-kit:init` | Writes `starter.yaml` from the instance |
| `dev-kit:apply` | Plans and runs the steps that bring the instance to `starter.yaml` |
| `dev-kit:channel` | Changes the name, locales and currencies of the default channel (`WEB_STORE`) |
| `dev-kit:history` | The states `dev-kit:apply` brought the shop to, as JSON |
| `dev-kit:catalog` | What a manifest can pick (themes, datasets, plugins), as JSON |

Every task the admin needs is exposed to castor-api (`#[AsApi]`): `opencolibri/starter-dev-kit-bundle` drives them
from its dashboard. `dev-kit:apply` reports its steps to `.castor/import/var/.progress/dev-kit.json`.

The loaded dataset is not stored by Sylius: dev-kit records it in `var/dev-kit/state.json` of the app when it loads
one. Sylius has no console command for the channel: the dev-kit copies its own, `sylius:dev-kit:channel`, into
the app (`src/Command/DevKitChannelCommand.php`, refreshed before each use, do not change it), which goes through
the Sylius repositories and works on any database Doctrine supports.
