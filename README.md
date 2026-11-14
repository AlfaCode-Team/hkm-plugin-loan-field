# hkm-plugin-loan-field

An [HKM Kernel](https://github.com/AlfaCode-Team/hkm-kernel) plugin providing
**`field.operations`** — part of the **akasente** loan-management set.

Field operations: a visit an officer made to a customer (GPS optional, photo optional) and a reminder that was attempted by WhatsApp, SMS or a call. This module records those; it does not send messages. Also answers which customers in arrears have had no visit and no reminder in the last N days. Tables live in the tenant template, not here.

## Install

```bash
hkm plugins install https://github.com/AlfaCode-Team/hkm-plugin-loan-field.git
hkm plugins enable loan-field
```

It installs as `plugins/LoanField` with the namespace `Plugins\LoanField\`, because
`hkm` takes the folder from `module.json`'s `name`, not from the repository.

## What it owns

Tables, shipped as `database/tenant-template/` migrations and published into a
project on enable:

- `visits`
- `reminders`

Requires the domains: `database.management`, `sync.offline`, `organisation.structure`, `loans.management`

Publishes:

- `Plugins\LoanField\API\Contracts\FieldServiceContract`

## Routes

| Route | Name |
|---|---|
| `GET /api/field/visits` | `field.visits.index` |
| `POST /api/field/visits` | `field.visits.store` |
| `GET /api/field/visits/{id:num}` | `field.visits.show` |
| `GET /api/field/reminders` | `field.reminders.index` |
| `POST /api/field/reminders` | `field.reminders.store` |
| `GET /api/field/contacts/not-contacted` | `field.contacts.not_contacted` |

## Every write takes a sync revision

Each row carries `rev`, `device_id`, `created_at`, `updated_at` and
`deleted_at`. `rev` comes from `sync.offline`'s counter and is the cursor a
handset pulls against; a delete is a tombstone, never a `DELETE`. Ids are
client-minted from a claimed shard, so a phone with no signal can create a row
that will never collide with one the server made.

## Tests

```bash
HKM_KERNEL_PATH=/path/to/hkm-kernel phpunit
```

Check these out beside this repository (or install them) before running the suite: `hkm-plugin-database`, `hkm-plugin-loan-customers`, `hkm-plugin-loan-organisation`, `hkm-plugin-loan-s`, `hkm-plugin-loan-sync`.

The suite migrates the real tenant template into a throwaway database and
drives the real adapter against it. `HKM_TEST_DB` picks which database that is:

```bash
phpunit                                                  # SQLite (default, no server)
HKM_TEST_DB=mysql  HKM_TEST_DB_USERNAME=root  phpunit
HKM_TEST_DB=pgsql  HKM_TEST_DB_PORT=5432      phpunit
HKM_TEST_DB=sqlsrv HKM_TEST_DB_USERNAME=sa    phpunit
```

The account needs permission to CREATE DATABASE; each run provisions
`hkm_test_<suite>_<random>` and drops it in teardown.

**Run at least one non-SQLite driver before believing a green suite.** SQLite
has no strict mode, no real column types, it tolerates a named placeholder used
twice, and it serialises writers for free — so it hides the whole class of
defect this code is most likely to have.

## Licence

MIT. See [LICENSE](LICENSE).
