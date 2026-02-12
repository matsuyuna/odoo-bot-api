# Deploy a cPanel (Namecheap) con GitHub Actions

Este repo trae un workflow en `.github/workflows/main.yml` para desplegar por FTP/FTPS a cPanel.

> Nota: aunque los secretos se llamen `SFTP_*` por compatibilidad histórica, la acción usada por GitHub Actions acepta protocolos `ftp`, `ftps` o `ftps-legacy` (no `sftp`).

## 1) Secretos requeridos en GitHub

En tu repo: **Settings → Secrets and variables → Actions → New repository secret**.

Crea estos secretos (**usados por el workflow actual**):

- `SFTP_HOST`: host de cPanel (ej. `ftp.tudominio.com` o hostname del servidor).
- `SFTP_USERNAME`: usuario SFTP/FTP.
- `SFTP_PASSWORD`: password SFTP/FTP.
- `SFTP_SERVER_DIR`: ruta remota absoluta donde vive el proyecto, por ejemplo:
  - `/home/USUARIO/public_html/odoo-bot-api/`
  - o `/home/USUARIO/tudominio.com/odoo-bot-api/`

Opcionales:

- `SFTP_PORT`: normalmente `21` para FTPS explícito (o el puerto que te dé Namecheap).
- `SFTP_PROTOCOL`: `ftps` (recomendado), `ftp` o `ftps-legacy`.

Compatibilidad: si ya usabas `FTP_*`, el workflow también los acepta como fallback.

## 1.1) ¿Me los puedes crear tú los secrets?

Sí, te lo puedo dejar automatizado, pero por seguridad **debe hacerse con tu sesión de GitHub**.
Yo no puedo crear secretos en tu cuenta sin tus credenciales.

### Opción A: por interfaz web (rápida)

1. Ve a tu repo en GitHub.
2. **Settings** → **Secrets and variables** → **Actions**.
3. Clic en **New repository secret**.
4. Crea uno por uno:
   - `SFTP_HOST`
   - `SFTP_USERNAME`
   - `SFTP_PASSWORD`
   - `SFTP_SERVER_DIR`
   - (opcionales) `SFTP_PORT`, `SFTP_PROTOCOL`

### Opción B: por CLI (automatizada)

Este repo incluye el script `scripts/setup-github-secrets.sh` (crea `SFTP_*`).

```bash
# 1) login en GitHub CLI
gh auth login

# 2) ejecutar script (desde la raíz del repo)
./scripts/setup-github-secrets.sh

# 3) validar
 gh secret list
```

También puedes pasar el repo explícito:

```bash
./scripts/setup-github-secrets.sh OWNER/REPO
```

## 2) Variables de entorno Laravel

No subas `.env` desde el repo. En cPanel, crea/edita el `.env` en el servidor con valores reales:

- `APP_KEY`
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL`
- `DB_*`
- credenciales de APIs externas (Odoo, Wati, etc.)

Si no tienes `APP_KEY`, genera una localmente con `php artisan key:generate --show` y cópiala al `.env` del hosting.

## 3) Estructura recomendada en cPanel

En hosting compartido, idealmente:

- Código en carpeta privada (fuera de `public_html`) o subcarpeta dedicada.
- `public/` de Laravel apuntando al document root del subdominio o dominio.

Si no puedes cambiar document root, puedes publicar como subcarpeta y ajustar rutas, pero no es lo ideal.

## 4) Deploy por Git (alternativa a FTP)

Sí, también se puede “pusheando” al server, pero requiere:

1. Acceso SSH habilitado en cPanel.
2. Git disponible en el server.
3. Llave SSH de `GitHub Actions` autorizada en cPanel.
4. Script remoto de post-deploy (`composer install`, `php artisan config:cache`, etc.).

Si quieres esta variante, te configuro un workflow por SSH (más confiable que FTP para Laravel).

## 5) Checklist rápido de diagnóstico cuando falla

- Host/usuario/password incorrectos.
- `FTP_SERVER_DIR` mal (slash final y ruta exacta importan).
- Puerto/protocolo incompatibles (`ftp`, `ftps`, `ftps-legacy`; **no** `sftp` con esta acción).
- Firewall del hosting bloqueando IPs.
- `.env` ausente o con `APP_KEY` inválida.
- Permisos en `storage/` y `bootstrap/cache/`.
