#!/bin/sh
#
# KronoQR — guarda de arranque de Loki y Tempo: valida LOG_RETENTION_HOURS antes de que
# arranquen (RL-11, RGPD art. 5.1.e).
#
# POR QUE EXISTE. Loki y Tempo expanden `${LOG_RETENTION_HOURS:-2160}h` al leer su
# fichero (`-config.expand-env=true`). `install.sh`, `update.sh` y `doctor.sh` validan
# la variable, pero `docker compose up -d loki` no pasa por ellos, y dos cosas no pueden
# llegar al binario:
#   · `0` (o `0h`): en las dos herramientas 0 DESACTIVA la retencion y los datos se
#     conservan para siempre, que es lo contrario de minimizar;
#   · texto que no sea un entero: la expansion es de TEXTO y un valor con saltos de linea
#     podria inyectar claves en el YAML.
# Aqui se exige `^[0-9]+$` y 24..17520 antes de ejecutar nada. Vacia o ausente vale 2160.
#
# USO (lo pone el compose): corre en el servicio de un solo uso `retention-guard`, con
# `command: /bin/true`; Loki y Tempo dependen de el con `service_completed_successfully`.
# Desde Loki 3.7 su imagen no trae shell, asi que la guarda ya no puede ser su entrypoint.
# Arrancar Loki o Tempo con `--no-deps` se salta la guarda: ningun guion del producto lo hace
# y `doctor.sh` vuelve a validar la variable. NINGUN SECRETO. Codigo 64 = valor invalido.

set -eu
IFS="$(printf '\n\t')"

horas="${LOG_RETENTION_HOURS:-2160}"

case "${horas}" in
*[!0-9]*)
  printf 'LOG_RETENTION_HOURS no es un numero entero de horas. Ponlo en el .env (2160 = 90 dias) y recrea el servicio.\n' >&2
  exit 64
  ;;
esac

# Ceros a la izquierda fuera: en aritmetica de shell "0100" es OCTAL (64), no 100.
while [ "${horas#0}" != "${horas}" ]; do horas="${horas#0}"; done
horas="${horas:-0}"

if [ "${#horas}" -gt 6 ] || [ "${horas}" -lt 24 ] || [ "${horas}" -gt 17520 ]; then
  printf 'LOG_RETENTION_HOURS debe estar entre 24 y 17520 horas (2160 = 90 dias, RL-11). 0 desactivaria la retencion. Corrigelo en el .env y recrea el servicio.\n' >&2
  exit 64
fi

# El valor validado se exporta y se ejecuta lo que venga detras (en el compose, /bin/true;
# en LogRetentionSettingTest, un eco del valor): asi la prueba ve lo que la guarda deja pasar.
LOG_RETENTION_HOURS="${horas}"
export LOG_RETENTION_HOURS

[ "$#" -gt 0 ] || {
  printf 'retention-guard.sh: falta el binario que arrancar.\n' >&2
  exit 64
}
exec "$@"
