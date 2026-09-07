#!/usr/bin/env bash
#
# KronoQR — comprobaciones de requisitos comunes a install.sh y update.sh.
#
# NO SE EJECUTA SOLO: lo cargan los dos scripts despues de lib/messages.sh, del
# que toman los textos (`check_ok`, `check_fail`, `c_docker`, ...). Hasta la
# tarea 5.7 estas funciones vivian dentro de install.sh; el actualizador las
# necesita identicas —mismo umbral de Docker, mismo mensaje de "que hacer"— y
# dos copias son la forma segura de que un dia digan cosas distintas a la misma
# persona.
#
# CONTRATO CON EL SCRIPT QUE LO CARGA: declara los contadores CHECKS_RUN,
# CHECKS_FAILED y CHECKS_WARNED antes de llamar a nada de aqui. Cada `check_*`
# los incrementa; el script decide al final de su fase de requisitos si sigue
# o sale con 2.

set -euo pipefail
IFS=$'\n\t'

# Umbral publicado (doc 02 §11.6.2). Es el MINIMO, no el recomendado.
readonly KQ_MIN_DOCKER_MAJOR=24

check_pass() {
  CHECKS_RUN=$((CHECKS_RUN + 1))
  kq_msg check_ok "$1"
}

check_warn() {
  CHECKS_RUN=$((CHECKS_RUN + 1))
  CHECKS_WARNED=$((CHECKS_WARNED + 1))
  kq_msg check_warn "$1"
  kq_msg fix "$2"
}

check_fail() {
  CHECKS_RUN=$((CHECKS_RUN + 1))
  CHECKS_FAILED=$((CHECKS_FAILED + 1))
  kq_msg check_fail "$1"
  kq_msg fix "$2"
}

# Docker Engine accesible y en version soportada, y el plugin de Compose v2.
check_docker() {
  local version major compose_version

  if ! command -v docker >/dev/null 2>&1; then
    check_fail "$(kq_format c_docker "$(kq_text absent)")" "$(kq_text f_docker_missing)"
    return 0
  fi

  version="$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
  if [ -z "${version}" ]; then
    check_fail "$(kq_text c_docker_access)" "$(kq_text f_docker_access)"
    return 0
  fi
  check_pass "$(kq_text c_docker_access)"

  major="${version%%.*}"
  if [ -n "${major}" ] && [ "${major}" -ge "${KQ_MIN_DOCKER_MAJOR}" ] 2>/dev/null; then
    check_pass "$(kq_format c_docker "${version}")"
  else
    check_fail "$(kq_format c_docker "${version}")" \
      "$(kq_format f_docker_old "${version}")"
  fi

  compose_version="$(docker compose version --short 2>/dev/null || true)"
  if [ -n "${compose_version}" ]; then
    check_pass "$(kq_format c_compose "${compose_version}")"
  else
    check_fail "$(kq_format c_compose "$(kq_text absent)")" "$(kq_text f_compose)"
  fi
}

# Las dos herramientas del sistema que usan los scripts ademas de Docker.
check_tools() {
  if command -v openssl >/dev/null 2>&1; then
    check_pass "$(kq_text c_openssl)"
  else
    check_fail "$(kq_text c_openssl)" "$(kq_text f_openssl)"
  fi

  if command -v curl >/dev/null 2>&1; then
    check_pass "$(kq_text c_curl)"
  else
    check_fail "$(kq_text c_curl)" "$(kq_text f_curl)"
  fi
}
