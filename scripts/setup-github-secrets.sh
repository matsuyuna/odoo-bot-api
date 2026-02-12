#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   ./scripts/setup-github-secrets.sh [owner/repo]
# If owner/repo is omitted, current git remote repository is used.

if ! command -v gh >/dev/null 2>&1; then
  echo "Error: GitHub CLI (gh) no está instalado."
  echo "Instálalo desde: https://cli.github.com/"
  exit 1
fi

if ! gh auth status >/dev/null 2>&1; then
  echo "Error: no hay sesión en GitHub CLI."
  echo "Ejecuta: gh auth login"
  exit 1
fi

REPO="${1:-}"
if [[ -z "$REPO" ]]; then
  REMOTE_URL="$(git config --get remote.origin.url || true)"
  if [[ -z "$REMOTE_URL" ]]; then
    echo "Error: no se pudo detectar el repo automáticamente."
    echo "Uso: $0 owner/repo"
    exit 1
  fi

  # Normalize SSH/HTTPS git remotes to owner/repo
  REPO="$(printf '%s' "$REMOTE_URL" | sed -E 's#(git@github.com:|https://github.com/)##; s#\.git$##')"
fi

read -r -p "FTP_SERVER: " FTP_SERVER
read -r -p "FTP_USERNAME: " FTP_USERNAME
read -r -s -p "FTP_PASSWORD: " FTP_PASSWORD
printf '\n'
read -r -p "FTP_SERVER_DIR (ej: /home/USER/public_html/app/): " FTP_SERVER_DIR
read -r -p "FTP_PORT [21]: " FTP_PORT
FTP_PORT="${FTP_PORT:-21}"
read -r -p "FTP_PROTOCOL [ftps]: " FTP_PROTOCOL
FTP_PROTOCOL="${FTP_PROTOCOL:-ftps}"

case "$FTP_PROTOCOL" in
  ftp|ftps|ftps-legacy) ;;
  *)
    echo "Error: FTP_PROTOCOL inválido: '$FTP_PROTOCOL'"
    echo "Valores permitidos: ftp, ftps, ftps-legacy"
    exit 1
    ;;
esac

echo "Configurando secrets FTP_* en $REPO ..."
printf '%s' "$FTP_SERVER" | gh secret set FTP_SERVER --repo "$REPO"
printf '%s' "$FTP_USERNAME" | gh secret set FTP_USERNAME --repo "$REPO"
printf '%s' "$FTP_PASSWORD" | gh secret set FTP_PASSWORD --repo "$REPO"
printf '%s' "$FTP_SERVER_DIR" | gh secret set FTP_SERVER_DIR --repo "$REPO"
printf '%s' "$FTP_PORT" | gh secret set FTP_PORT --repo "$REPO"
printf '%s' "$FTP_PROTOCOL" | gh secret set FTP_PROTOCOL --repo "$REPO"

echo "Configurando secrets SFTP_* por compatibilidad ..."
printf '%s' "$FTP_SERVER" | gh secret set SFTP_HOST --repo "$REPO"
printf '%s' "$FTP_USERNAME" | gh secret set SFTP_USERNAME --repo "$REPO"
printf '%s' "$FTP_PASSWORD" | gh secret set SFTP_PASSWORD --repo "$REPO"
printf '%s' "$FTP_SERVER_DIR" | gh secret set SFTP_SERVER_DIR --repo "$REPO"
printf '%s' "$FTP_PORT" | gh secret set SFTP_PORT --repo "$REPO"
printf '%s' "$FTP_PROTOCOL" | gh secret set SFTP_PROTOCOL --repo "$REPO"

echo "OK. Secrets FTP_* y SFTP_* cargados correctamente."
echo "Puedes validar con: gh secret list --repo $REPO"
