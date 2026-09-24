# Habitat

A multi-tenant property management platform for short-term rentals, vacation
rentals, serviced apartments, boutique hotels and multi-unit portfolios.

Laravel 13 on PHP 8.4 and PostgreSQL 16, with a React 19 admin interface. 106
tables, 323 API routes, 461 tests.

## What it does

Take a booking through a real availability check and a real pricing engine.
Clean the property afterwards, on a rota that follows the booking when it moves.
Talk to the guest. Take their money, post it to a double-entry ledger, bill the
costs, and close the month with an owner statement that adds up night by night
across changes of ownership. Distribute the listing to channels. Report on all
of it.

The design decisions behind each of those are in [docs/](docs/).

## One thing to know first

There are no commercial partner agreements behind this software and no
third-party credentials ship with it. Rather than stub the integration points
out, each one is a proper abstraction with a working local implementation
behind it — and **every one of them says so**, in a field the API returns and
the interface displays.

A simulated payment is labelled simulated. A channel connection running against
a local adapter is labelled simulated, on the connection itself. A door code
that opens nothing says so. A message recorded rather than sent says so.

Nothing here pretends a real integration is active when it is not.
[docs/integrations.md](docs/integrations.md) has the full table.

## Running it

Requires PHP 8.4, PostgreSQL 16, Redis and Node 22.

```bash
composer install
cp .env.example .env
php artisan key:generate

createdb bnb        -O bnb
createdb bnb_testing -O bnb        # the test suite uses its own database

php artisan migrate
php artisan db:seed                # permissions and amenities: every environment needs these

cd frontend && npm ci && npm run build && cd ..
php artisan serve
```

The admin interface is at `/app`.

### A portfolio to look at

```bash
php artisan db:seed --class=DemoSeeder
```

This builds "Demo Hospitality Group": four Lisbon properties, three owners with
dated shares, fifteen bookings from last month to next, payments, cleans, an
inbox with somebody waiting, reviews, costs and last month's owner statements.

Every record goes through the same services the application uses, so the demo
obeys the same rules as production — which is why it takes eight seconds rather
than one, and why it refused to publish a listing until it had a photograph.

Sign in as `admin@demo-hospitality.test` with the password `password`; there is
one account per role, and signing in as the housekeeper is the quickest way to
see that authorisation is real.

## Tests

```bash
php artisan test
vendor/bin/pint --test
cd frontend && npm run typecheck && npm run build
```

The suite runs against PostgreSQL, not SQLite. The reasoning, and what the tests
actually protect, are in [docs/testing.md](docs/testing.md).

## Documentation

| | |
|---|---|
| [architecture.md](docs/architecture.md) | How it is put together, and why |
| [domain-model.md](docs/domain-model.md) | What the concepts mean and the rules between them |
| [database.md](docs/database.md) | The schema, area by area |
| [api.md](docs/api.md) | Conventions, authentication, a worked example |
| [integrations.md](docs/integrations.md) | What is real, what is simulated, how you would tell |
| [security.md](docs/security.md) | Tenant isolation, secrets, and the known gaps |
| [testing.md](docs/testing.md) | What is protected, and what is not |
| [development-roadmap.md](docs/development-roadmap.md) | Built, deliberately not built, and next |
| [deployment.md](docs/deployment.md) | Docker, Render, Neon |

## Licence and scope

Guesty was used as a functional benchmark for *what a platform in this category
does*. No Guesty source code, algorithm, asset, trademark, wording or interface
was copied, and none of it was consulted in writing this.
