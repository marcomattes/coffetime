# ☕ Coffee Time

**A privacy-first, installable PWA for the shared office coffee tab.** Everyone
signs in with a passkey and tracks their own coffees; names and individual
totals stay private, and only anonymous aggregate statistics are ever shared.
The server stores names exclusively as ciphertext it cannot decrypt — the
admin's private key never leaves the admin's browser.

Source, issues and the full documentation live on GitHub:
**https://github.com/marcomattes/coffetime**

<p align="center">
  <img src="https://raw.githubusercontent.com/marcomattes/coffetime/main/docs/screenshots/home.png" width="270" alt="Home view: personal coffee counter, streak, and the Take a coffee button.">
  <img src="https://raw.githubusercontent.com/marcomattes/coffetime/main/docs/screenshots/stats.png" width="270" alt="Statistics view: a bar chart of the last 14 days above the anonymous leaderboard.">
  <img src="https://raw.githubusercontent.com/marcomattes/coffetime/main/docs/screenshots/admin.png" width="270" alt="Admin view: price and invite settings, and local decryption of account names.">
</p>

## Run it

```bash
docker run -d -p 8123:80 -v coffee-data:/var/www/html/data \
  marcomattes/coffetime:latest
```

Open <http://localhost:8123> and follow the setup wizard. It generates the
admin RSA keypair in your browser, has you download the private key, and asks
for a coffee price and an invite code. The first account registered afterwards
becomes administrator. No manual configuration is required to get started.

The wizard asks for a **setup token** first. Read it from the container log:

```bash
docker logs $(docker ps -lq) 2>&1 | grep 'setup token'
```

Setup is unauthenticated by necessity and fixes the RSA key every name is
sealed to, so the token is what stops anyone who reaches a fresh instance
before you from claiming it. It is also written to `setup-token.txt` in the
data volume.

### docker compose

```yaml
services:
  app:
    image: marcomattes/coffetime:latest
    ports:
      - "8123:80"
    volumes:
      - coffee-data:/var/www/html/data
      # For anything past localhost, pin rpId and origin:
      # - ./config.php:/var/www/html/config.php:ro
    restart: unless-stopped

volumes:
  coffee-data:
```

## Configuration

The image ships **no `config.php` at all**: `origin` and `rpId` are derived
from the request, which is what lets the same image serve localhost and your
own domain without a rebuild. To pin them — which you should for anything past
localhost — bind-mount your own file over `/var/www/html/config.php`. Set
`rpId` to the host without a port and `origin` to the complete origin.
Production WebAuthn requires HTTPS.

Start from
[`config.example.php`](https://github.com/marcomattes/coffetime/blob/main/config.example.php);
every setting is documented in the
[README](https://github.com/marcomattes/coffetime#configuration).

| | |
| --- | --- |
| Exposed port | `80` |
| Data volume | `/var/www/html/data` — SQLite database and setup token |
| Config file | `/var/www/html/config.php` (optional, bind-mount) |
| Health check | built in, `curl` against the app root |
| Base image | `php:8.2-apache` |

The data directory sits outside the document root, so neither the database nor
the setup token is reachable over HTTP. SQLite is the default; MySQL and
MariaDB are supported through `config.php`. Migrations run automatically and
are additive.

## Tags

| Tag | Points at |
| --- | --- |
| `latest` | the newest released version |
| `1.2.3`, `1.2`, `1` | a specific release, from a `v1.2.3` git tag |
| `edge` | the latest commit on `main` |
| `sha-abc1234` | one exact commit |

`latest` and the semver tags only move when a release is tagged; `edge` moves
with `main`. Pin a semver tag in production and treat `edge` as a preview. The
app footer and `GET /api/version` report the commit the image was built from,
so you can tell what is actually running.

Built for `linux/amd64` and `linux/arm64` — a Raspberry Pi or an ARM VPS works.

## Provenance

Images are built by
[GitHub Actions](https://github.com/marcomattes/coffetime/blob/main/.github/workflows/docker.yml)
only after the full test suite passes, and carry a signed build provenance
attestation. The identical manifest is published to the GitHub Container
Registry as `ghcr.io/marcomattes/coffetime`.

```bash
gh attestation verify oci://docker.io/marcomattes/coffetime:latest --owner marcomattes
```

## License

MIT — see
[LICENSE](https://github.com/marcomattes/coffetime/blob/main/LICENSE).
