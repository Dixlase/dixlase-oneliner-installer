# Dixlase Oneliner Installer Documentation

The one-liner `curl -sS https://install.dixlase.net | php` works on any environment that exposes a shell, PHP 8.3+, and outbound HTTPS. This section walks through the realistic deployment scenarios.

For Japanese, see [ja/index.md](./ja/index.md).

## Pick the scenario that matches your target

| Scenario | Guide | When to use |
| --- | --- | --- |
| Local development on your laptop | [deploy-local-dev.md](./deploy-local-dev.md) | First-time evaluation, contributor setup, "just want to see the wizard" |
| Docker on your machine | [deploy-docker.md](./deploy-docker.md) | Production-shaped local stack (Nginx + PHP-FPM + MariaDB + Redis + Mailpit) |
| VPS / cloud VM | [deploy-vps.md](./deploy-vps.md) | The recommended path for self-hosting Dixlase |
| Shared / rental hosting | [deploy-shared-hosting.md](./deploy-shared-hosting.md) | When the host gives you SSH + PHP 8.3+ (and the workaround for SFTP-only plans) |
| Troubleshooting | [troubleshooting.md](./troubleshooting.md) | Common failures, their root cause, and the exact fix |

## Prerequisites recap

The installer always needs:

- **PHP 8.3+** with the standard extensions (`openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `curl`, `fileinfo`, `gd`)
- **Composer** (recommended — used by the primary delivery path)
- **Node.js 24** (or any `^20.19 || >=22.12`) — only when the frontend assets have to be built; pass `--no-build` to skip the build entirely
- **Outbound HTTPS** to `github.com`, `api.github.com`, `packagist.org`, and `install.dixlase.net`
- A shell where you can run `curl ... | php` (so plain SFTP-only hosts can't use it directly — see [deploy-shared-hosting.md](./deploy-shared-hosting.md) for the workaround)

While Dixlase Core is still private, you also need a **GitHub fine-grained PAT** scoped to `Dixlase/dixlase-core` and `Dixlase/theme-dixlase-onepage` with **Contents: Read-only**. Pass it through the `GITHUB_TOKEN` environment variable so it never lands in shell history:

```bash
curl -sS https://install.dixlase.net | GITHUB_TOKEN=github_pat_xxx php
```

See the main [README.md](../README.md#private-repositories) for the token rationale and the [Composer integration details](../README.md#how-it-works).
