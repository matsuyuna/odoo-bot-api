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

read -r -p "SFTP_HOST: " SFTP_HOST
read -r -p "SFTP_USERNAME: " SFTP_USERNAME
read -r -s -p "SFTP_PASSWORD: " SFTP_PASSWORD
printf '\n'
read -r -p "SFTP_SERVER_DIR (ej: /home/USER/public_html/app/): " SFTP_SERVER_DIR
read -r -p "SFTP_PORT [21]: " SFTP_PORT
SFTP_PORT="${SFTP_PORT:-21}"
read -r -p "SFTP_PROTOCOL [ftps]: " SFTP_PROTOCOL
SFTP_PROTOCOL="${SFTP_PROTOCOL:-ftps}"

case "$SFTP_PROTOCOL" in
  ftp|ftps|ftps-legacy) ;;
  *)
    echo "Error: SFTP_PROTOCOL inválido: '$SFTP_PROTOCOL'"
    echo "Valores permitidos: ftp, ftps, ftps-legacy"
    exit 1
    ;;
esac

echo "Configurando secrets SFTP_* en $REPO ..."
printf '%s' "$SFTP_HOST" | gh secret set SFTP_HOST --repo "$REPO"
printf '%s' "$SFTP_USERNAME" | gh secret set SFTP_USERNAME --repo "$REPO"
printf '%s' "$SFTP_PASSWORD" | gh secret set SFTP_PASSWORD --repo "$REPO"
printf '%s' "$SFTP_SERVER_DIR" | gh secret set SFTP_SERVER_DIR --repo "$REPO"
printf '%s' "$SFTP_PORT" | gh secret set SFTP_PORT --repo "$REPO"
printf '%s' "$SFTP_PROTOCOL" | gh secret set SFTP_PROTOCOL --repo "$REPO"

echo "OK. Secrets SFTP_* cargados correctamente."
echo "Puedes validar con: gh secret list --repo $REPO"
