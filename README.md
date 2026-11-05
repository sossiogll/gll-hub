# gllHub

gllHub is a content hub: it keeps what has no other home, brings in what lives elsewhere (GitHub, Medium, ...),
curates it and serves it. The core is a Laravel 12 application with a Filament admin panel; every kind of content
and every integration comes from a package, and the core knows none of them by name. The architecture and the rules
for writing code are in [`CLAUDE.md`](CLAUDE.md). The packages live in
[`sossiogll/gllhub-packages`](https://github.com/sossiogll/gllhub-packages).

## The API

The read API is public, with a rate limit (`GLLHUB_API_PER_MINUTE`), and served under `/api/v2`: `/languages`,
`/entries/{type}` and `/entries/{type}/{slug}`, for whatever types the installed packages declare. Its contract is
`docs/api-v2.openapi.yaml` in the repository of the site, and a consumer's base URL ends in `/api/v2`. It needs
`GLLHUB_LANGUAGES` and `GLLHUB_DEFAULT_LANGUAGE`; without them it answers 503. See
[ADR 0003](docs/adr/0003-the-read-api-serves-any-declared-type.md).

## Development

The development stack builds from this checkout and mounts the sources. Clone this repository and `gllhub-packages`
side by side (the stack mounts the sibling folder):

```sh
git clone https://github.com/sossiogll/gll-hub.git
git clone https://github.com/sossiogll/gllhub-packages.git
cd gll-hub
git checkout dev
docker compose -f docker-compose.dev.yml up
```

The first start copies `.env.example` to `.env`, installs the Composer dependencies, generates the application key
and migrates the database; it takes a few minutes. Then open <http://localhost:8000/admin> and create an admin user:

```sh
docker compose -f docker-compose.dev.yml exec app php artisan make:filament-user
```

`make:filament-user` is Filament's own command (it also takes `--name`, `--email` and `--password` to skip the
prompts), so the core has no command of its own for it. To type less, `export COMPOSE_FILE=docker-compose.dev.yml`
and drop the `-f`.

The admin draws everything from the installed packages. With none (see "Add packages") the Content and Sources menus
are empty and System > Packages says so. Syncs run on the `queue` service (Horizon); "Sync now" only queues them.

| Service | URL |
| --- | --- |
| App | <http://localhost:8000> |
| Vite | <http://localhost:5173> |
| Mailpit (outgoing mail) | <http://localhost:8025> |

A port already in use on your machine? Set `APP_PORT`, `VITE_PORT` or `MAILPIT_PORT` in `.env` (and `APP_URL` to match `APP_PORT`), then `docker compose -f docker-compose.dev.yml up -d` again.

On Linux, build with your own ids so the files the containers create belong to you:
`HOST_UID=$(id -u) HOST_GID=$(id -g) docker compose -f docker-compose.dev.yml up --build`.

### Reloading

- PHP: the sources are bind-mounted, so a change applies on the next request.
- Assets: Vite serves them with hot module replacement; `public/hot` points the browser at `http://localhost:5173`.
- `queue` and `scheduler` load the code once, so `docker/app/watch-restart.sh` runs them and restarts them when a
  `.php` file changes under `app/`, `config/`, `routes/`, `database/`, `contracts/` or the packages.

### Xdebug

Xdebug is installed in the dev image and off. Turn it on with `XDEBUG_MODE=debug` in `.env` or in the shell, and
recreate the containers (`docker compose -f docker-compose.dev.yml up -d`). It connects to your IDE on
`host.docker.internal:9003` (Docker Desktop on macOS and, through `extra_hosts`, Linux) only for requests that ask
for it (`start_with_request=trigger`):

- Browser: the Xdebug helper extension, or an `XDEBUG_SESSION` cookie.
- CLI: `docker compose -f docker-compose.dev.yml exec -e XDEBUG_TRIGGER=1 app php artisan ...`.

Map the project root on your machine to `/app` in the container:

- PhpStorm: Settings > PHP > Servers, add a server named `gllhub` with host `localhost` and port `8000`, tick "Use path
  mappings" and map the project root to `/app`. Start "Listen for PHP Debug Connections" (port 9003).
- VS Code (PHP Debug extension), in `.vscode/launch.json`:

```json
{
    "name": "gllHub",
    "type": "php",
    "request": "launch",
    "port": 9003,
    "pathMappings": { "/app": "${workspaceFolder}" }
}
```

## Add packages

The core's `composer.json` names no package. An instance lists its own in `composer.local.json`, which is
git-ignored and merged by `wikimedia/composer-merge-plugin`:

```sh
cp composer.local.json.example composer.local.json
docker compose -f docker-compose.dev.yml exec app composer update gllhub/portfolio gllhub/medium
```

The example points at `/gllhub-packages` inside the container, the sibling repository. `composer update` also
rewrites `composer.lock`; do not commit that change.

## Production

`docker-compose.yml` builds nothing: it runs the image published to GitHub Container Registry
(`ghcr.io/sossiogll/gllhub`), with MariaDB and Redis. `:X.Y.Z` is a release (tag `vX.Y.Z`), `:latest` follows
`master`, and `:dev` follows the `dev` branch.

```sh
cp .env.production.example .env      # then fill it in; the compose file refuses to start without the required values
docker run --rm ghcr.io/sossiogll/gllhub php artisan key:generate --show   # the value for APP_KEY
docker compose up -d
docker compose exec app php artisan make:filament-user
```

The `init` service migrates the database and exits; `app`, `queue` (Horizon) and `scheduler` start after it has
succeeded and the database and Redis are healthy. The application listens on port 8080 over plain HTTP
(`GLLHUB_PORT` changes the published port): put a reverse proxy that terminates TLS in front of it and list its
address in `TRUSTED_PROXIES`. Uploaded files and logs live in the `storage` volume, the database in `db`.

To upgrade, set `GLLHUB_VERSION=1.2.3` in `.env` and run `docker compose pull && docker compose up -d`; `init` runs
the new migrations before the new containers start. To go back, set the previous version (migrations are not
reverted).

The image runs [FrankenPHP](https://frankenphp.dev) in classic mode: one process, built on Caddy, that serves Laravel
and Filament without an extra package, where php-fpm would add nginx and a process supervisor. It runs as a non-root
user, with OPcache on and Xdebug absent.

### An instance image with packages

The published image names no package. An instance adds its own on top of it, from a `composer.local.json` (same format
as in development, with `path` or `vcs` repositories) next to a `Dockerfile`:

```dockerfile
FROM ghcr.io/sossiogll/gllhub:1.2.3

USER root
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/*
COPY composer.local.json /app/composer.local.json
# Add `COPY packages /packages` first when composer.local.json has a path repository.
# A private repository needs credentials: RUN --mount=type=secret,id=composer_auth,target=/root/.composer/auth.json ...
RUN CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync \
    composer update 'gllhub/*' --no-dev --optimize-autoloader --prefer-dist --no-interaction
USER app
```

Build and publish it wherever you like, and run it with `GLLHUB_IMAGES` and `GLLHUB_VERSION` pointing at it:

```sh
docker build -t registry.example.com/mysite/gllhub:1.2.3 .
```

## Tests

```sh
docker compose -f docker-compose.dev.yml exec app php artisan test
docker compose -f docker-compose.dev.yml exec app sh -c 'cd contracts && composer install && vendor/bin/phpunit'
```

CI runs both, starts the development stack and builds and starts the production one, on every push to `next` and every pull request.
