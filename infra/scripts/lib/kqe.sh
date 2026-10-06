#!/usr/bin/env bash
#
# KronoQR — formato KQE1 de las copias cifradas y AUTENTICADAS (ADR-049).
#
# NO SE EJECUTA SOLO: lo cargan backup-common.sh (backup.sh, restore.sh,
# restore-drill.sh, wal-metrics.sh, update.sh, install.sh, doctor.sh) y, copiado
# a la imagen de PostgreSQL, archive-wal.sh y kronoqr-restore-wal. UNA sola
# implementacion, una sola bateria de pruebas (KqeFormatTest).
#
# FORMATO (un fichero):
#
#   linea 1  KQE1 kind=<dump|base|wal> kid=<8 hex> iter=<N> created=<UTC ISO> name=<nombre> [src=legacy]
#   cuerpo   salida de `openssl enc` AES-256-CBC (Salted__ + sal de 8 bytes + datos),
#            PBKDF2-SHA512; identica a la de la 2.1.0
#   trailer  <64 hex minusculas> y salto de linea: MAC = SHA3-256 sobre
#            "KQE1-MAC" NUL K_mac(hex) cabecera cuerpo
#
# Cifrar y DESPUES autenticar; se verifica el MAC ANTES de descifrar (sin oraculo
# de relleno) y sobre los MISMOS bytes que luego se descifran: `kqe_open` copia el
# fichero UNA vez a un directorio privado y todo lo demas opera sobre esa copia
# (un administrador del recurso de red no puede cambiar el cuerpo entre la
# comprobacion y el uso).
#
# REGISTRO DE ETIQUETAS v1 (ADR-049; cambiar una es romper todas las copias):
#
#   sal PBKDF2 de la subclave del WAL        kqwal-v1  (hex 6b7177616c2d7631), 600000 iter, SHA-512
#   sal PBKDF2 de K_mac de volcado/copia     kqe1-mac  (hex 6b7165312d6d6163), 600000 iter, SHA-512
#   prefijo del MAC                          "KQE1-MAC" NUL
#   K_mac del WAL                            SHA3-256("KQE1-MAC-KEY" NUL WAL_KEY)
#   kid                                      SHA3-256("KQE1-KID" NUL K_mac)[0:8]
#   MAC del manifiesto                       SHA3-256("KQE1-MANIFEST" NUL K_mac nombre LF manifiesto)
#
# NINGUNA CLAVE EN LA LINEA DE ORDENES NI EN LA SALIDA (C1). Las claves entran a
# openssl por un descriptor (`-pass fd:3`) o, donde el sistema no lo admite
# (Git Bash en Windows), por una variable de entorno que solo ve ESE proceso. El
# MAC entra por la entrada estandar de `openssl dgst` (SHA3 no sufre extension de
# longitud). Aqui no hay trazado de la shell, y las variables de clave no se
# exportan y se borran con `kqe_forget`.
#
# CODIGOS de `kqe_open` (tambien en KQE_STATUS):
#   0  autenticada            10 heredada (sin MAC: Salted__)
#   11 el MAC no cuadra       12 cabecera/trailer invalidos
#   13 clave distinta (kid)   14 la cabecera no es la esperada (kind/name/iter)
#   15 no se ha podido copiar o leer el fichero

# Las variables KQE_* son el resultado de `kqe_open`: las leen quienes cargan esta
# biblioteca, y ShellCheck no lo ve desde aqui.
# shellcheck disable=SC2034

set -euo pipefail
IFS=$'\n\t'

readonly KQE_ITER_DUMP=600000
readonly KQE_ITER_WAL=10000
readonly KQE_SALT_WAL_HEX="6b7177616c2d7631"
readonly KQE_SALT_MAC_HEX="6b7165312d6d6163"
readonly KQE_TRAILER_BYTES=65
readonly KQE_WAL_NAME_RE='^([0-9A-F]{24}(\.partial|\.[0-9A-F]{8}\.backup)?|[0-9A-F]{8}\.history)$'

# Resultado de `kqe_open`: lo leen los scripts que cargan esta biblioteca.
# shellcheck disable=SC2034
KQE_STATUS=""
KQE_REASON=""
KQE_KIND=""
KQE_KID=""
KQE_CREATED=""
KQE_NAME=""
KQE_SRC=""
KQE_ITER=""
KQE_COPY=""
KQE_HDR_LEN=0
KQE_BODY_END=0
# Internas (no se exportan).
_KQE_PASS=""
_KQE_KMAC=""
_KQE_SPEC=""
_KQE_KID=""

kqe_forget() {
  _KQE_PASS=""
  _KQE_KMAC=""
  unset _KQE_PASS _KQE_KMAC
  _KQE_PASS=""
  _KQE_KMAC=""
}

# Como se entrega la clave a openssl: fd:3 si el sistema lo admite, y si no una
# variable que solo ve el proceso hijo.
_kqe_pass_spec() {
  if [ -z "$_KQE_SPEC" ]; then
    if printf 'x' | openssl enc -aes-256-cbc -pbkdf2 -pass fd:3 3< <(printf 'k') >/dev/null 2>&1; then
      _KQE_SPEC="fd:3"
    else
      _KQE_SPEC="env:KQE_PW"
    fi
  fi
  printf '%s' "$_KQE_SPEC"
}

# _kqe_with_pass CLAVE ORDEN...  — ejecuta ORDEN con `-pass` apuntando a CLAVE.
_kqe_with_pass() {
  local pass="$1"
  shift
  if [ "$(_kqe_pass_spec)" = "fd:3" ]; then
    "$@" -pass fd:3 3< <(printf '%s' "$pass")
  else
    KQE_PW="$pass" "$@" -pass env:KQE_PW
  fi
}

kqe_require() {
  command -v openssl >/dev/null 2>&1 || return 1
  openssl dgst -sha3-256 </dev/null >/dev/null 2>&1
}

_kqe_sha3() {
  openssl dgst -sha3-256 -r | cut -d' ' -f1
}

# PBKDF2-SHA512 de CLAVE con una sal fija: 64 hex (32 bytes). Solo a variable.
#   _kqe_pbkdf2_key CLAVE SAL_HEX ITER
_kqe_pbkdf2_key() {
  local out
  out="$(_kqe_with_pass "$1" openssl enc -P -aes-256-cbc -pbkdf2 -iter "$3" -md sha512 -S "$2" </dev/null)" || return 1
  printf '%s\n' "$out" | sed -n 's/^key=//p' | tr 'A-F' 'a-f'
}

# Subclave del WAL derivada de la maestra (la que recibe PostgreSQL).
#   kqe_derive_wal_key MAESTRA
kqe_derive_wal_key() {
  _kqe_pbkdf2_key "$1" "$KQE_SALT_WAL_HEX" "$KQE_ITER_DUMP"
}

# K_mac de volcado y copia fisica, a partir de la maestra.
_kqe_kmac_master() {
  _kqe_pbkdf2_key "$1" "$KQE_SALT_MAC_HEX" "$KQE_ITER_DUMP"
}

# K_mac del WAL, a partir de la subclave del WAL.
_kqe_kmac_wal() {
  printf 'KQE1-MAC-KEY\0%s' "$1" | _kqe_sha3
}

_kqe_kid_of() {
  printf 'KQE1-KID\0%s' "$1" | _kqe_sha3 | cut -c1-8
}

# kid de una subclave del WAL: lo que `doctor.sh` compara con la cabecera.
kqe_wal_kid() {
  _kqe_kid_of "$(_kqe_kmac_wal "$1")"
}

# Una subclave del WAL valida: 64 hex.
kqe_wal_key_valid() {
  [[ "${1:-}" =~ ^[0-9a-f]{64}$ ]]
}

# MAC de los primeros LEN bytes de FICHERO con la K_mac dada.
#   _kqe_mac_of FICHERO LEN KMAC
_kqe_mac_of() {
  local file="$1" len="$2" kmac="$3"
  {
    printf 'KQE1-MAC\0%s' "$kmac"
    head -c "$len" "$file"
  } | _kqe_sha3
}

# Prepara la clave para CIFRAR: fija _KQE_PASS y _KQE_KMAC y el kid.
#   _kqe_key_for_encrypt KIND  ->  deja el kid en _KQE_KID (no usar en $(...): perderia las claves)
_kqe_key_for_encrypt() {
  local kind="$1"
  if [ "$kind" = "wal" ]; then
    kqe_wal_key_valid "${BACKUP_WAL_KEY:-}" || return 1
    _KQE_PASS="${BACKUP_WAL_KEY}"
    _KQE_KMAC="$(_kqe_kmac_wal "$_KQE_PASS")"
  else
    [ -n "${BACKUP_ENCRYPTION_KEY:-}" ] || return 1
    _KQE_PASS="${BACKUP_ENCRYPTION_KEY}"
    _KQE_KMAC="$(_kqe_kmac_master "$_KQE_PASS")"
  fi
  [[ "$_KQE_KMAC" =~ ^[0-9a-f]{64}$ ]] || return 1
  _KQE_KID="$(_kqe_kid_of "$_KQE_KMAC")"
}

_kqe_now_iso() {
  date -u +%Y-%m-%dT%H:%M:%SZ
}

# Cifra la entrada estandar y escribe en SALIDA el fichero KQE1 completo.
#   kqe_encrypt KIND NOMBRE SALIDA [legacy]
# KIND dump|base|wal. NOMBRE: el nombre sin extension (dump/base) o el segmento
# (wal). `legacy` marca (`src=legacy`, bajo el MAC) lo que era un .gz sin cifrar.
kqe_encrypt() {
  local kind="$1" name="$2" out="$3" src="${4:-}"
  local iter="$KQE_ITER_DUMP" kid header size mac

  [[ "$kind" =~ ^(dump|base|wal)$ ]] || return 1
  [[ "$name" =~ ^[A-Za-z0-9._-]{1,80}$ ]] || return 1
  [ "$kind" != "wal" ] || iter="$KQE_ITER_WAL"
  [ -z "$src" ] || [ "$src" = "legacy" ] || return 1

  _kqe_key_for_encrypt "$kind" || return 1
  kid="$_KQE_KID"
  header="KQE1 kind=${kind} kid=${kid} iter=${iter} created=$(_kqe_now_iso) name=${name}"
  [ -z "$src" ] || header="${header} src=legacy"

  printf '%s\n' "$header" >"$out"
  if ! _kqe_with_pass "$_KQE_PASS" openssl enc -aes-256-cbc -md sha512 -pbkdf2 -iter "$iter" -salt >>"$out"; then
    kqe_forget
    return 1
  fi
  size="$(wc -c <"$out" | tr -d '[:space:]')"
  mac="$(_kqe_mac_of "$out" "$size" "$_KQE_KMAC")" || {
    kqe_forget
    return 1
  }
  printf '%s\n' "$mac" >>"$out"
  kqe_forget
}

# Cabecera de un fichero (primera linea, tope 300 bytes), sin interpretarla.
kqe_header_of() {
  head -c 300 "$1" | head -n 1
}

# Lee y COPIA una sola vez. Deja la copia en KQE_COPY y todo lo que dice la
# cabecera AUTENTICADA en KQE_*.
#   kqe_open ORIGEN DIRECTORIO_PRIVADO KIND [NOMBRE_ESPERADO]
kqe_open() {
  local src="$1" workdir="$2" want_kind="$3" want_name="${4:-}"
  local copy size header trailer hdr_re kind kid iter created name srcflag cand kmac mac_calc last

  KQE_STATUS=""
  KQE_REASON=""
  KQE_COPY=""
  KQE_KIND=""
  KQE_KID=""
  KQE_CREATED=""
  KQE_NAME=""
  KQE_SRC=""
  KQE_ITER=""
  kqe_forget

  [ -d "$workdir" ] && [ ! -L "$workdir" ] || {
    KQE_STATUS=15
    KQE_REASON="el directorio de trabajo no existe o es un enlace"
    return 15
  }
  copy="${workdir}/kqe.in"
  rm -f -- "$copy"
  if ! (
    set -C
    umask 077
    cat -- "$src" >"$copy"
  ) 2>/dev/null; then
    KQE_STATUS=15
    KQE_REASON="no se ha podido leer '${src}'"
    return 15
  fi
  KQE_COPY="$copy"
  size="$(wc -c <"$copy" | tr -d '[:space:]')"

  if [ "$size" -ge 8 ] && [ "$(head -c 8 "$copy")" = "Salted__" ]; then
    KQE_STATUS=10
    KQE_REASON="copia heredada de la 2.1.0: cifrada, sin autenticar"
    return 10
  fi

  # Cabecera: lexica, solo para elegir clave. Su significado se comprueba DESPUES del MAC.
  header="$(kqe_header_of "$copy")"
  hdr_re='^KQE1 kind=(dump|base|wal) kid=([0-9a-f]{8}) iter=([0-9]{1,8}) created=([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z) name=([A-Za-z0-9._-]{1,80})( src=legacy)?$'
  if [ "$size" -le "$KQE_TRAILER_BYTES" ] || [[ ! "$header" =~ $hdr_re ]]; then
    KQE_STATUS=12
    KQE_REASON="cabecera KQE1 invalida o fichero truncado"
    return 12
  fi
  kind="${BASH_REMATCH[1]}"
  kid="${BASH_REMATCH[2]}"
  iter="${BASH_REMATCH[3]}"
  created="${BASH_REMATCH[4]}"
  name="${BASH_REMATCH[5]}"
  srcflag=""
  [ -z "${BASH_REMATCH[6]}" ] || srcflag="legacy"
  KQE_HDR_LEN=$((${#header} + 1))

  last="$(tail -c 1 "$copy" | od -An -c | tr -d '[:space:]')"
  trailer="$(tail -c "$KQE_TRAILER_BYTES" "$copy" | head -c 64)"
  if [ "$last" != '\n' ] || [[ ! "$trailer" =~ ^[0-9a-f]{64}$ ]]; then
    KQE_STATUS=12
    KQE_REASON="trailer KQE1 invalido: falta el MAC"
    return 12
  fi
  KQE_BODY_END=$((size - KQE_TRAILER_BYTES))

  # Clave: la que produce ese kid (la actual y, si existe, la anterior).
  if [ "$kind" = "wal" ]; then
    for cand in "${BACKUP_WAL_KEY:-}" "${BACKUP_WAL_KEY_PREVIOUS:-}"; do
      kqe_wal_key_valid "$cand" || continue
      kmac="$(_kqe_kmac_wal "$cand")"
      if [ "$(_kqe_kid_of "$kmac")" = "$kid" ]; then
        _KQE_PASS="$cand"
        _KQE_KMAC="$kmac"
        break
      fi
    done
  else
    for cand in "${BACKUP_ENCRYPTION_KEY:-}" "${BACKUP_ENCRYPTION_KEY_PREVIOUS:-}"; do
      [ -n "$cand" ] || continue
      kmac="$(_kqe_kmac_master "$cand")"
      if [ "$(_kqe_kid_of "$kmac")" = "$kid" ]; then
        _KQE_PASS="$cand"
        _KQE_KMAC="$kmac"
        break
      fi
    done
  fi
  if [ -z "$_KQE_KMAC" ]; then
    KQE_STATUS=13
    KQE_REASON="clave distinta o cabecera alterada"
    kqe_forget
    return 13
  fi

  mac_calc="$(_kqe_mac_of "$copy" "$KQE_BODY_END" "$_KQE_KMAC")"
  if [ "$mac_calc" != "$trailer" ]; then
    KQE_STATUS=11
    KQE_REASON="el MAC no cuadra: el fichero esta alterado o danado"
    kqe_forget
    return 11
  fi

  # Gancho SOLO para las pruebas (TOCTOU): se ejecuta justo DESPUES de verificar y
  # recibe la ruta del ORIGEN. La prueba lo usa para cambiar un bit del origen y
  # comprobar que lo que se descifra sigue siendo la copia ya verificada. Es una
  # ruta a un ejecutable, nunca texto a evaluar.
  if [ -n "${KQE_TEST_HOOK_AFTER_VERIFY:-}" ] && [ -x "${KQE_TEST_HOOK_AFTER_VERIFY}" ]; then
    "${KQE_TEST_HOOK_AFTER_VERIFY}" "$src" || true
  fi

  # Ya autenticada: ahora SI se interpreta.
  if [ "$kind" != "$want_kind" ]; then
    KQE_STATUS=14
    KQE_REASON="es una copia de tipo '${kind}' y se esperaba '${want_kind}'"
    kqe_forget
    return 14
  fi
  if [ -n "$want_name" ] && [ "$name" != "$want_name" ]; then
    KQE_STATUS=14
    KQE_REASON="la cabecera dice que es '${name}' y el fichero se llama '${want_name}': renombrado o sustituido"
    kqe_forget
    return 14
  fi
  if [ "$kind" = "wal" ]; then
    [ "$iter" = "$KQE_ITER_WAL" ] && [[ "$name" =~ $KQE_WAL_NAME_RE ]] || {
      KQE_STATUS=14
      KQE_REASON="parametros de segmento de WAL no permitidos"
      kqe_forget
      return 14
    }
  else
    [ "$iter" = "$KQE_ITER_DUMP" ] || {
      KQE_STATUS=14
      KQE_REASON="parametros de cifrado no permitidos"
      kqe_forget
      return 14
    }
  fi

  KQE_STATUS=0
  KQE_KIND="$kind"
  KQE_KID="$kid"
  KQE_ITER="$iter"
  KQE_CREATED="$created"
  KQE_NAME="$name"
  KQE_SRC="$srcflag"
  return 0
}

# Descifra el cuerpo de la COPIA ya autenticada por kqe_open, a la salida estandar.
kqe_decrypt_copy() {
  [ "$KQE_STATUS" = "0" ] && [ -f "$KQE_COPY" ] && [ -n "$_KQE_PASS" ] || return 1
  head -c "$KQE_BODY_END" "$KQE_COPY" | tail -c +"$((KQE_HDR_LEN + 1))" |
    _kqe_with_pass "$_KQE_PASS" openssl enc -d -aes-256-cbc -md sha512 -pbkdf2 -iter "$KQE_ITER"
}

# Descifra una copia HEREDADA (2.1.0) ya copiada por kqe_open (codigo 10).
#   kqe_decrypt_legacy_copy [dump|base]
#
# Cada clave candidata (la actual y, si existe, la anterior) descifra a un fichero
# del directorio privado de KQE_COPY (0600), NUNCA directamente a la salida: con la
# clave equivocada `openssl enc -d` ya ha escrito casi todo el cuerpo (basura) cuando
# falla el relleno, y a veces (~1/256) el relleno sale valido y devuelve 0. Por eso,
# ademas del codigo de salida, se comprueba la FIRMA del contenido: `PGDMP` en un
# volcado, `1f8b` (gzip) en una copia fisica. Solo el candidato que supera las dos
# cosas se vuelca a la salida estandar.
kqe_decrypt_legacy_copy() {
  local want="${1:-}" cand out magic ok
  [ "$KQE_STATUS" = "10" ] && [ -f "$KQE_COPY" ] && [ -n "${BACKUP_ENCRYPTION_KEY:-}" ] || return 1
  out="${KQE_COPY%/*}/kqe.legacy.out"
  for cand in "${BACKUP_ENCRYPTION_KEY}" "${BACKUP_ENCRYPTION_KEY_PREVIOUS:-}"; do
    [ -n "$cand" ] || continue
    rm -f -- "$out"
    if ! (
      set -C
      umask 077
      _kqe_with_pass "$cand" openssl enc -d -aes-256-cbc -md sha512 -pbkdf2 -iter "$KQE_ITER_DUMP" <"$KQE_COPY" >"$out"
    ) 2>/dev/null; then
      continue
    fi
    ok=0
    case "$want" in
    dump) [ "$(head -c 5 "$out")" = "PGDMP" ] && ok=1 ;;
    base) [ "$(head -c 2 "$out" | od -An -tx1 | tr -d '[:space:]')" = "1f8b" ] && ok=1 ;;
    *)
      magic="$(head -c 2 "$out" | od -An -tx1 | tr -d '[:space:]')"
      { [ "$(head -c 5 "$out")" = "PGDMP" ] || [ "$magic" = "1f8b" ]; } && ok=1
      ;;
    esac
    if [ "$ok" -eq 1 ]; then
      cat -- "$out"
      rm -f -- "$out"
      return 0
    fi
  done
  rm -f -- "$out"
  return 1
}

# MAC del manifiesto de un volcado, a quien pertenece por su nombre.
#   kqe_manifest_mac NOMBRE_DEL_VOLCADO MANIFIESTO       (usa la clave de kqe_open o la actual)
kqe_manifest_mac() {
  local name="$1" file="$2"
  [ -n "$_KQE_KMAC" ] || return 1
  {
    printf 'KQE1-MANIFEST\0%s%s\n' "$_KQE_KMAC" "$name"
    cat -- "$file"
  } | _kqe_sha3
}

# Sella el manifiesto recien escrito con la clave ACTUAL. Escribe SALIDA.
kqe_manifest_seal() {
  local name="$1" file="$2" out="$3" mac
  _kqe_key_for_encrypt dump || return 1
  mac="$(kqe_manifest_mac "$name" "$file")" || {
    kqe_forget
    return 1
  }
  printf '%s\n' "$mac" >"$out"
  kqe_forget
}

# Verifica un manifiesto (YA copiado al directorio privado) contra su MAC. Exige
# que se haya llamado antes a kqe_open (la clave es la del volcado).
#   kqe_manifest_check NOMBRE MANIFIESTO_COPIA FICHERO_MAC_COPIA
kqe_manifest_check() {
  local name="$1" file="$2" macfile="$3" want got
  [ -f "$file" ] && [ -f "$macfile" ] || return 1
  want="$(head -n 1 "$macfile")"
  [[ "$want" =~ ^[0-9a-f]{64}$ ]] || return 1
  got="$(kqe_manifest_mac "$name" "$file")" || return 1
  [ "$got" = "$want" ]
}
