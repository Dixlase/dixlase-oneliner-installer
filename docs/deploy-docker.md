# Docker install

> The "production-shaped local stack" — Nginx + PHP-FPM + MariaDB + Redis + Mailpit + Adminer in one `docker compose up`.

The one-liner installer (`install.php`) is **not** the right tool for a Docker setup. Dixlase already ships a dedicated Docker installer repository:

**[Dixlase/dixlase-installer-docker](https://github.com/Dixlase/dixlase-installer-docker)**

This guide is mostly a pointer to that repo, with a brief explanation of *why* they're separate and when each makes sense.

## Why two installers?

| | `dixlase-oneliner-installer` (this repo) | `dixlase-installer-docker` |
| --- | --- | --- |
| Audience | Operators provisioning a single server | Developers / evaluators on a laptop |
| Surface | One PHP script served at `install.dixlase.net` | A repo with `setup.sh` + Docker Compose |
| Outputs | A Laravel application tree on the host | A running stack of containers |
| Web server | Set up separately | Bundled (Nginx in a container) |
| Database | Set up separately | Bundled (MariaDB in a container) |
| Best for | VPS / cloud VM / SSH-able rental | Local development, demos, evaluation |

They both end up running the same Dixlase Core source — they just differ in how the runtime is assembled.

## Quick start with the Docker installer

```bash
git clone https://github.com/Dixlase/dixlase-installer-docker.git ~/dixlase-docker
cd ~/dixlase-docker
./setup.sh

# Production mode (pre-built Vite assets): default
# Development mode (Vite hot-reload):
#   ./setup.sh --dev
```

When `setup.sh` finishes, visit:

- `http://localhost` — Dixlase (set `HTTPS=true` in `.env` and re-run `setup.sh` for HTTPS)
- `http://localhost:8081` — Adminer (DB browser)
- `http://localhost:8025` — Mailpit (mail catcher)

The Dixlase install wizard takes over on the first browser visit.

## Combining with a tree the one-liner already produced

Sometimes you've already run `install.dixlase.net | php` somewhere and you'd like to wrap *that* tree with the Docker installer's services. This is **not the supported path** (the Docker installer expects to clone the core itself), but it is achievable:

1. Note the absolute path of your existing install (e.g. `/Volumes/Data/Works/Dixlase/Oneliner`).
2. Clone `dixlase-installer-docker` into a sibling directory.
3. Edit `docker-compose.apps.yml` to replace the `html/` volume mount with a bind mount to your existing install path.
4. Skip the `setup.sh` step that clones the core (`./setup.sh` re-runs are otherwise idempotent).

For most situations it's simpler to throw away the one-liner tree and start fresh with `./setup.sh`.

## Docker only, without the Docker installer repo

If you want to roll your own minimal `docker-compose.yml` (e.g. for production behind a managed reverse proxy), the high-level shape is:

```yaml
services:
  app:
    image: php:8.2-fpm-alpine
    volumes:
      - ./app:/var/www/html
    depends_on: [mysql]

  web:
    image: nginx:alpine
    ports: ["8080:80"]
    volumes:
      - ./app/public:/var/www/html/public:ro
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on: [app]

  mysql:
    image: mariadb:10.11
    environment:
      MARIADB_DATABASE: dixlase
      MARIADB_USER: dixlase
      MARIADB_PASSWORD: change-me
      MARIADB_ROOT_PASSWORD: change-me-too
    volumes:
      - mysql_data:/var/lib/mysql

volumes:
  mysql_data:
```

Bootstrap the app on the host with the one-liner first, then mount the result. The `dixlase-installer-docker` repo's `docker-compose.*.yml` files are the closest production-ish reference; lift configuration from there as needed.
