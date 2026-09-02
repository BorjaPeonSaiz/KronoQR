#!/usr/bin/env bash
#
# KronoQR — las variables del borde que NO pueden tener valor por defecto.
#
# Se ejecuta antes que nada (04-, delante del 20-envsubst de la imagen base).
#
# POR QUE EXISTE. `envsubst` sustituye una variable ausente por la cadena vacia
# y sigue tan tranquilo. El resultado es una directiva rota y un arranque que
# muere con, por ejemplo:
#
#   [emerg] "client_max_body_size" directive invalid value in kronoqr.conf:161
#
# Un mensaje que no nombra la variable que falta, no dice de donde sale y manda
# a quien lo lea a abrir un fichero generado. Para las variables con un valor
# por defecto sensato, la respuesta esta en el Dockerfile (`ENV`), que es donde
# un defecto es barato. Aqui quedan las OTRAS: las tres redes, donde adivinar
# seria peor que parar.
#
# NINGUNA DE LAS TRES PUEDE TENER VALOR POR DEFECTO, y cada una por su motivo:
#
#   KIOSK_VLAN_CIDR       decide que trafico entra en la zona de fichaje de 600
#                         r/m y cual en la de 30. Un defecto equivocado frena
#                         el fichaje en el cambio de turno, y el sintoma -«el
#                         quiosco va lento a las 06:00»- no apunta aqui.
#   PORTAL_INTERNAL_CIDR  decide desde donde se puede abrir el portal del
#                         empleado, que entra con codigo y PIN de 6 digitos. Un
#                         defecto abierto lo publicaria a internet; uno cerrado
#                         dejaria a la plantilla sin poder consultar su
#                         jornada. Exponerlo es una decision EXPLICITA
#                         (RF-ID-08), nunca una omision.
#   METRICS_ALLOW_CIDR    decide quien puede leer /metrics, que expone el
#                         estado interno del sistema.
#
# Codigos de salida:
#   0  estan las tres
#   1  falta alguna; se nombran todas las que faltan, no solo la primera

set -euo pipefail
IFS=$'\n\t'

log() {
  printf '{"level":"%s","service":"nginx","step":"config","message":"%s"}\n' "$1" "$2" >&2
}

faltan=""

for variable in KIOSK_VLAN_CIDR PORTAL_INTERNAL_CIDR METRICS_ALLOW_CIDR; do
  if [ -z "${!variable:-}" ]; then
    faltan="${faltan}${variable} "
  fi
done

if [ -n "${faltan}" ]; then
  log "error" "Faltan variables obligatorias del borde HTTP: ${faltan}"
  log "error" "Que hacer: rellenalas en el .env de la instalacion. Estan marcadas [CLIENTE] y explicadas en docs/cliente/instalacion.md, seccion 6. NO tienen valor por defecto a proposito: adivinar el rango de la VLAN de quioscos frena el fichaje en el cambio de turno, y adivinar el del portal lo publicaria a internet o dejaria a la plantilla sin consultar su jornada."
  exit 1
fi

log "info" "Las tres redes del borde estan definidas."
