#!/usr/bin/env bash
#
# KronoQR — generacion y verificacion del CHANGELOG (doc 02 §10.5).
#
# El producto se versiona con SemVer y el CHANGELOG se GENERA a partir de los
# mensajes de commit con formato convencional. No se escribe a mano: un fichero
# escrito a mano se olvida justo en la version que hay que explicarle al cliente,
# y el actualizador de la tarea 5.7 lo necesita para decir que cambia ANTES de
# aplicar nada en el servidor de un hotel.
#
# Uso:
#   changelog.sh generate [--write]           Regenera la seccion [Unreleased]
#   changelog.sh generate --release 1.2.0 [--write]
#                                             Cierra [Unreleased] como version
#   changelog.sh check [VERSION]              Verifica formato y, con VERSION,
#                                             que esa version tiene entrada
#
# Sin --write, `generate` escribe el resultado en la salida estandar y no toca
# nada. Es idempotente: regenerar dos veces produce el mismo fichero.
#
# Codigos de salida:
#   0  correcto
#   1  falta la entrada de la version, o el fichero esta mal formado
#   2  error de uso (argumento desconocido, version que no es SemVer)
#   3  falta una herramienta o el repositorio git no esta disponible
#
# ESTA TABLA ES PROPIA Y NO LA COMUN de lib/exit-codes.sh, a proposito. Este
# script NO es un entregable del producto (§11.6.1): es una herramienta del
# repositorio, no viaja a ningun servidor de cliente, y lo ejecuta la CI, donde
# la convencion es «1 = la comprobacion ha fallado». Alinearlo con la tabla de
# los cinco scripts de operacion cambiaria el significado de su `1` sin que
# nadie lo necesitara. Este comentario existe para que la proxima persona no
# lea la diferencia como un descuido.
#
# No imprime nada del entorno: ninguna variable, ningun token. Se ejecuta en la
# CI y su salida es publica (doc 02 §7.7).

set -euo pipefail
IFS=$'\n\t'

readonly CHANGELOG_PATH="CHANGELOG.md"
readonly SEMVER_RE='^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'

# Cabecera fija del fichero. Cambiarla es cambiar el formato del entregable, y
# `check` la exige literalmente para que nadie la sustituya por prosa libre.
readonly HEADER_TITLE="# Registro de cambios"

err() {
  echo "changelog.sh: $*" >&2
}

die() {
  local code="$1"
  shift
  err "$@"
  exit "$code"
}

require_git() {
  command -v git >/dev/null 2>&1 || die 3 "git no esta disponible. Instalalo o ejecuta este script dentro del contenedor de desarrollo."
  git rev-parse --git-dir >/dev/null 2>&1 || die 3 "esto no es un repositorio git. Ejecuta el script desde la raiz del repositorio."
}

# Raiz del repositorio, para que el script funcione desde cualquier directorio.
cd_repo_root() {
  local root
  root="$(git rev-parse --show-toplevel)"
  cd "$root"
}

# `check` tiene que poder ejecutarse sobre un arbol exportado sin .git —el
# paquete de entrega al cliente lo es—, asi que ahi el cambio de directorio es
# opcional y la ausencia de git no es un error.
cd_repo_root_if_possible() {
  if git rev-parse --show-toplevel >/dev/null 2>&1; then
    cd "$(git rev-parse --show-toplevel)"
  fi
}

# Ultima etiqueta de version alcanzable, vacia si todavia no hay ninguna.
last_tag() {
  git describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null || true
}

# Titulo de la seccion de Keep a Changelog al que corresponde cada tipo de
# commit convencional. Lo que el cliente lee primero es lo que le afecta:
# funcionalidad nueva, correcciones y seguridad. El resto queda agrupado como
# trabajo interno, que sigue siendo util para el equipo pero no encabeza la
# lista.
section_for_type() {
  case "$1" in
  feat) echo "Anadido" ;;
  fix) echo "Corregido" ;;
  perf) echo "Cambiado" ;;
  revert) echo "Cambiado" ;;
  security) echo "Seguridad" ;;
  refactor | docs | test | build | ci | chore | style) echo "Interno" ;;
  *) echo "" ;;
  esac
}

# Orden de aparicion de las secciones. Fijo: un CHANGELOG que cambia el orden
# entre versiones es mas dificil de leer en diagonal, que es como se lee.
readonly SECTIONS=(
  "Cambios incompatibles"
  "Anadido"
  "Cambiado"
  "Corregido"
  "Seguridad"
  "Interno"
)

# Lee los commits del rango y emite lineas "SECCION<TAB>texto".
#
# Formato convencional:  tipo(ambito)!: asunto
# El `!` y un pie "BREAKING CHANGE:" marcan cambio incompatible, que en SemVer
# obliga a subir la version mayor: por eso encabeza el listado.
classify_commits() {
  local range="$1"
  local line type scope subject section breaking

  while IFS= read -r line; do
    [ -n "$line" ] || continue

    if [[ "$line" =~ ^([a-z]+)(\(([^\)]+)\))?(!)?:[[:space:]]+(.+)$ ]]; then
      type="${BASH_REMATCH[1]}"
      scope="${BASH_REMATCH[3]}"
      breaking="${BASH_REMATCH[4]}"
      subject="${BASH_REMATCH[5]}"
      section="$(section_for_type "$type")"

      if [ -z "$section" ]; then
        section="Interno"
      fi

      if [ -n "$breaking" ]; then
        section="Cambios incompatibles"
      fi

      if [ -n "$scope" ]; then
        printf '%s\t%s (%s)\n' "$section" "$subject" "$scope"
      else
        printf '%s\t%s\n' "$section" "$subject"
      fi
    else
      # Un commit que no sigue el formato no se inventa ni se descarta en
      # silencio: se avisa por stderr y se deja fuera del fichero. Callarlo
      # produciria un CHANGELOG incompleto sin que nadie se entere.
      err "aviso: commit sin formato convencional, no entra en el CHANGELOG: ${line}"
    fi
  done < <(git log --no-merges --format='%s' "$range" 2>/dev/null || true)
}

# Cuerpo de una seccion de version: los commits del rango, ya agrupados.
render_entries() {
  local range="$1"
  local classified section body any=0

  classified="$(classify_commits "$range")"

  for section in "${SECTIONS[@]}"; do
    body="$(printf '%s\n' "$classified" | awk -F'\t' -v s="$section" '$1 == s { print "- " $2 }' || true)"
    if [ -n "$body" ]; then
      any=1
      printf '### %s\n\n%s\n\n' "$section" "$body"
    fi
  done

  if [ "$any" -eq 0 ]; then
    printf '_Sin cambios registrados._\n\n'
  fi
}

generate() {
  local release="$1"
  local write="$2"
  local tag range heading tmp today

  tag="$(last_tag)"
  if [ -n "$tag" ]; then
    range="${tag}..HEAD"
  else
    range="HEAD"
  fi

  today="$(date -u +%Y-%m-%d)"

  if [ -n "$release" ]; then
    heading="## [${release}] - ${today}"
  else
    heading="## [Unreleased]"
  fi

  tmp="$(mktemp)"
  # Sin trap RETURN: el fichero temporal se retira explicitamente en cada salida
  # de esta funcion, y `set -e` con mktemp en /tmp no deja restos relevantes.

  {
    cat <<EOF
${HEADER_TITLE}

Todas las novedades relevantes de KronoQR. El formato sigue
[Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/) y el producto se
versiona con [SemVer](https://semver.org/lang/es/) (doc 02 §10.5).

**Este fichero se genera**, no se edita a mano:

    make changelog          # regenera la seccion [Unreleased]

La fuente son los mensajes de commit con formato convencional. Un commit que no
lo siga no aparece aqui, y el generador lo avisa por la salida de error.
Ninguna version se publica sin su entrada: \`make changelog-check VERSION=1.2.3\`
falla si no la encuentra, y la CI ejecuta esa comprobacion al etiquetar.

EOF
    printf '%s\n\n' "$heading"
    render_entries "$range"

    # El historico previo se conserva tal cual: lo ya publicado no se reescribe
    # nunca. Se descartan la cabecera y la seccion [Unreleased], que son
    # justamente lo que esta funcion acaba de regenerar.
    if [ -f "$CHANGELOG_PATH" ]; then
      awk '
        /^## \[Unreleased\]/ { skip = 1; started = 1; next }
        /^## \[/            { skip = 0; started = 1 }
        started && !skip    { print }
      ' "$CHANGELOG_PATH"
    fi
  } >"$tmp"

  if [ "$write" -eq 1 ]; then
    mv "$tmp" "$CHANGELOG_PATH"
    echo "changelog.sh: ${CHANGELOG_PATH} regenerado desde ${range}."
    echo "changelog.sh: revisa el resultado antes de publicar la version."
  else
    cat "$tmp"
    rm -f "$tmp"
  fi
}

check() {
  local version="$1"
  local entry_line body

  if [ ! -f "$CHANGELOG_PATH" ]; then
    die 1 "no existe ${CHANGELOG_PATH}. Generalo con: make changelog"
  fi

  # Sin tuberia: `head | grep -q` deja a `grep` cerrando el descriptor en
  # cuanto decide, y bajo `pipefail` un SIGPIPE de `head` se leeria como
  # "falta la cabecera". Misma clase de fallo que el de install.sh.
  if [ "$(head -n 1 "$CHANGELOG_PATH")" != "$HEADER_TITLE" ]; then
    die 1 "${CHANGELOG_PATH} no empieza por '${HEADER_TITLE}'. Regeneralo con: make changelog"
  fi

  if ! grep -q '^## \[' "$CHANGELOG_PATH"; then
    die 1 "${CHANGELOG_PATH} no tiene ninguna seccion de version. Regeneralo con: make changelog"
  fi

  if [ -z "$version" ]; then
    echo "changelog.sh: ${CHANGELOG_PATH} bien formado."
    return 0
  fi

  if ! printf '%s' "$version" | grep -qE "$SEMVER_RE"; then
    die 2 "'${version}' no es una version SemVer valida. Formato esperado: 1.2.3 o 1.2.3-rc.1"
  fi

  entry_line="## [${version}]"

  if ! grep -qF "$entry_line" "$CHANGELOG_PATH"; then
    err "la version ${version} no tiene entrada en ${CHANGELOG_PATH}."
    err "Una version sin entrada no se publica: el cliente no puede saber que cambia antes de actualizar (doc 02 §10.5)."
    err "Que hacer:"
    err "  1. bash infra/scripts/changelog.sh generate --release ${version} --write"
    err "  2. revisa el resultado y ajusta lo que el commit no supo contar"
    err "  3. commitea el CHANGELOG y vuelve a etiquetar v${version}"
    exit 1
  fi

  # Una entrada vacia es tan inutil como no tenerla.
  body="$(awk -v v="$entry_line" '
    index($0, v) == 1 { inside = 1; next }
    inside && /^## \[/ { inside = 0 }
    inside && /^- / { print }
  ' "$CHANGELOG_PATH")"

  if [ -z "$body" ]; then
    err "la entrada de ${version} en ${CHANGELOG_PATH} no enumera ningun cambio."
    err "Regenerala con: bash infra/scripts/changelog.sh generate --release ${version} --write"
    exit 1
  fi

  echo "changelog.sh: la version ${version} tiene entrada con cambios en ${CHANGELOG_PATH}."
}

usage() {
  sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
}

main() {
  local command="${1:-}"
  shift || true

  case "$command" in
  generate)
    local release="" write=0
    while [ $# -gt 0 ]; do
      case "$1" in
      --write) write=1 ;;
      --release)
        shift
        release="${1:-}"
        [ -n "$release" ] || die 2 "--release necesita una version. Ejemplo: --release 1.2.0"
        printf '%s' "$release" | grep -qE "$SEMVER_RE" ||
          die 2 "'${release}' no es una version SemVer valida. Formato esperado: 1.2.3"
        ;;
      -h | --help)
        usage
        return 0
        ;;
      *) die 2 "argumento desconocido '$1'. Ejecuta: changelog.sh --help" ;;
      esac
      shift
    done
    require_git
    cd_repo_root
    generate "$release" "$write"
    ;;
  check)
    cd_repo_root_if_possible
    check "${1:-}"
    ;;
  -h | --help | "")
    usage
    ;;
  *)
    die 2 "orden desconocida '${command}'. Ejecuta: changelog.sh --help"
    ;;
  esac
}

main "$@"
