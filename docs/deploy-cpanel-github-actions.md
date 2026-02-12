# Deploy a cPanel (Namecheap) con GitHub Actions

Este repo trae un workflow en `.github/workflows/main.yml` para desplegar por FTP/FTPS a cPanel.

## 1) Secretos requeridos en GitHub

En tu repo: **Settings → Secrets and variables → Actions → New repository secret**.

Crea estos secretos:

- `FTP_SERVER`: host FTP de cPanel (ej. `ftp.tudominio.com` o hostname del servidor).
- `FTP_USERNAME`: usuario FTP.
- `FTP_PASSWORD`: password FTP.
- `FTP_SERVER_DIR`: ruta remota absoluta donde vive el proyecto, por ejemplo:
  - `/home/USUARIO/public_html/odoo-bot-api/`
  - o `/home/USUARIO/tudominio.com/odoo-bot-api/`

Opcionales:

- `FTP_PORT`: normalmente `21` (FTPS explícito) o el que te dé Namecheap.
- `FTP_PROTOCOL`: `ftps` (recomendado) o `ftp`.

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
- Puerto/protocolo incompatibles (`ftp` vs `ftps`).
- Firewall del hosting bloqueando IPs.
- `.env` ausente o con `APP_KEY` inválida.
- Permisos en `storage/` y `bootstrap/cache/`.
