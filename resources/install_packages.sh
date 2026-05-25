#!/bin/bash
# Installation des dépendances Ecovacs
# Appelé par Jeedom : install_packages.sh <progress_file>
# Jeedom capture stdout/stderr → fichier log
# $1 = fichier de progression (écrit par Jeedom)

PROGRESS_FILE="${1:-/tmp/ecovacs_dep_progress}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VENV_DIR="${SCRIPT_DIR}/python_venv"
PYENV_ROOT="/opt/pyenv"
PYTHON_VERSION="3.14.0"
PYTHON_TARGET="${PYENV_ROOT}/versions/${PYTHON_VERSION}/bin/python3"

log()  { echo "$(date '+%Y-%m-%d %H:%M:%S') $*"; }
fail() { log "ERREUR : $*"; exit 1; }

echo 0 > "$PROGRESS_FILE"
log "=== Début installation dépendances Ecovacs ==="
log "Répertoire  : ${SCRIPT_DIR}"
log "Virtualenv  : ${VENV_DIR}"
log "Python cible: ${PYTHON_VERSION}"
log "Utilisateur : $(whoami)"
log "Python sys  : $(python3 --version 2>&1)"
log "OS          : $(cat /etc/os-release 2>/dev/null | grep PRETTY | cut -d= -f2 | tr -d '\"')"

# ── Étape 1 : Dépendances système ─────────────────────────────────────────────
echo 5 > "$PROGRESS_FILE"
log "Installation des dépendances de compilation…"
apt-get install -y \
    build-essential libssl-dev zlib1g-dev libbz2-dev \
    libreadline-dev libsqlite3-dev libncursesw5-dev \
    libxml2-dev libxmlsec1-dev libffi-dev liblzma-dev \
    curl git tk-dev uuid-dev \
    || fail "Impossible d'installer les dépendances de compilation"
echo 10 > "$PROGRESS_FILE"
log "Dépendances système OK"

# ── Étape 2 : pyenv ───────────────────────────────────────────────────────────
echo 12 > "$PROGRESS_FILE"
if [ ! -d "${PYENV_ROOT}/.git" ]; then
    log "Installation de pyenv dans ${PYENV_ROOT}…"
    git clone https://github.com/pyenv/pyenv.git "${PYENV_ROOT}" \
        || fail "Impossible de cloner pyenv"
else
    log "Mise à jour de pyenv…"
    cd "${PYENV_ROOT}" && git pull
fi

export PYENV_ROOT
export PATH="${PYENV_ROOT}/bin:${PYENV_ROOT}/shims:${PATH}"
echo 15 > "$PROGRESS_FILE"
log "pyenv OK : $(${PYENV_ROOT}/bin/pyenv --version 2>&1)"

# ── Étape 3 : Python 3.14 ────────────────────────────────────────────────────
echo 18 > "$PROGRESS_FILE"
if [ -f "${PYTHON_TARGET}" ]; then
    log "Python ${PYTHON_VERSION} déjà installé : $(${PYTHON_TARGET} --version 2>&1)"
else
    log "Compilation de Python ${PYTHON_VERSION} (5-15 min)…"
    "${PYENV_ROOT}/bin/pyenv" install "${PYTHON_VERSION}" \
        || fail "Échec compilation Python ${PYTHON_VERSION}"
    log "Python ${PYTHON_VERSION} OK : $(${PYTHON_TARGET} --version 2>&1)"
fi
echo 50 > "$PROGRESS_FILE"

# ── Étape 4 : Permissions ─────────────────────────────────────────────────────
chown -R www-data:www-data "${SCRIPT_DIR}" 2>/dev/null || true

# ── Étape 5 : Virtualenv Python 3.14 ─────────────────────────────────────────
echo 55 > "$PROGRESS_FILE"
VENV_PY_VER=""
if [ -f "${VENV_DIR}/bin/python3" ]; then
    VENV_PY_VER=$("${VENV_DIR}/bin/python3" --version 2>&1)
fi

if echo "$VENV_PY_VER" | grep -q "3\.14"; then
    log "Venv Python 3.14 existant – mise à jour"
else
    if [ -d "${VENV_DIR}" ]; then
        log "Suppression de l'ancien venv (${VENV_PY_VER})…"
        rm -rf "${VENV_DIR}"
    fi
    log "Création du virtualenv Python 3.14…"
    "${PYTHON_TARGET}" -m venv "${VENV_DIR}" \
        || fail "Impossible de créer le virtualenv"
    chown -R www-data:www-data "${VENV_DIR}" 2>/dev/null || true
    log "Virtualenv créé : $("${VENV_DIR}/bin/python3" --version 2>&1)"
fi
echo 60 > "$PROGRESS_FILE"

VENV_PIP="${VENV_DIR}/bin/pip"
VENV_PYTHON="${VENV_DIR}/bin/python3"

# ── Étape 6 : pip ────────────────────────────────────────────────────────────
log "Mise à jour pip…"
"${VENV_PIP}" install --quiet --upgrade pip
echo 65 > "$PROGRESS_FILE"

# ── Étape 7 : aiohttp ────────────────────────────────────────────────────────
log "Installation aiohttp…"
"${VENV_PIP}" install --quiet --upgrade "aiohttp>=3.9.0" \
    || fail "Impossible d'installer aiohttp"
echo 75 > "$PROGRESS_FILE"
log "aiohttp : $("${VENV_PYTHON}" -c 'import aiohttp; print(aiohttp.__version__)' 2>&1)"

# ── Étape 8 : deebot-client 18.x ─────────────────────────────────────────────
log "Installation deebot-client>=18.0.0…"
"${VENV_PIP}" install --quiet --upgrade "deebot-client>=18.0.0" \
    || fail "Impossible d'installer deebot-client"
echo 92 > "$PROGRESS_FILE"
log "deebot-client : $("${VENV_PYTHON}" -c 'import deebot_client; print(getattr(deebot_client,"__version__","?"))' 2>&1)"

# ── Étape 9 : Vérification finale ─────────────────────────────────────────────
log "Vérification des imports…"
"${VENV_PYTHON}" -c "
import aiohttp, deebot_client
from deebot_client.events.water_info import WaterAmount, WaterAmountEvent, WaterSweepTypeEvent, MopAttachedEvent, SweepType
from deebot_client.api_client import ApiClient, Devices
from deebot_client.mqtt_client import MqttClient
from deebot_client.device import Device
print('OK – tous les imports validés')
" || fail "Vérification finale des imports échouée"

echo 100 > "$PROGRESS_FILE"
log "=== Installation terminée avec succès ==="
log "    Python     : $("${VENV_PYTHON}" --version 2>&1)"
DEEBOT_VER=$("${VENV_PYTHON}" -m pip show deebot-client 2>/dev/null | grep "^Version" | cut -d' ' -f2)
log "    deebot     : ${DEEBOT_VER}"
log "    aiohttp    : $("${VENV_PYTHON}" -c 'import aiohttp; print(aiohttp.__version__)' 2>&1)"