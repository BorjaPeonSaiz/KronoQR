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
#   0  estan las tres y su sintaxis CIDR es valida
#   1  falta alguna o no es un CIDR IPv4 valido; se nombran todas, no solo la primera
#
# SINTAXIS. Cada variable lleva UN solo CIDR IPv4 con prefijo (a.b.c.d/n, octetos
# 0-255 y n entre 0 y 32). Sin la validacion, un `10.0.0.0/33` o una coma de
# mas llegaba hasta `nginx -t` y moria con un `[emerg]` sobre un fichero generado
# que no nombra la variable. IPv6 NO se acepta a proposito: el borde solo escucha
# en IPv4 (`listen 8443 ssl`), asi que un rango IPv6 seria un candado que nunca
# casa con nadie -en KIOSK_VLAN_CIDR, quioscos frenados a 30 r/m sin aviso-.
# Una direccion suelta se escribe con /32.
#
# AVISO (no error): PORTAL_INTERNAL_CIDR=0.0.0.0/0 publica el portal a internet.
# Es una decision legitima del cliente (RF-ID-08), pero tiene que ser visible.
# Prueba: infra/scripts/nginx-entrypoint-test.sh

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

readonly OCTETO='(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])'
readonly PREFIJO='(3[0-2]|[12]?[0-9])'
readonly CIDR_IPV4="^${OCTETO}\.${OCTETO}\.${OCTETO}\.${OCTETO}/${PREFIJO}\$"

invalidas=0

for variable in KIOSK_VLAN_CIDR PORTAL_INTERNAL_CIDR METRICS_ALLOW_CIDR; do
  valor="${!variable}"
  # Solo caracteres inocuos: el valor va dentro de una linea de registro JSON.
  visible="${valor//[^0-9A-Za-z./: ,-]/?}"
  if ! [[ "${valor}" =~ ${CIDR_IPV4} ]]; then
    log "error" "${variable}='${visible}' no es un CIDR IPv4 valido (se espera a.b.c.d/n, por ejemplo 10.20.0.0/24, con un solo rango)."
    log "error" "Que hacer: corrige ${variable} en el .env de la instalacion. Una direccion suelta se escribe con /32 (10.20.0.5/32); IPv6 no se admite porque el borde solo escucha en IPv4. Explicado en docs/cliente/instalacion.md, seccion 6."
    invalidas=1
  fi
done

if [ "${invalidas}" -ne 0 ]; then
  exit 1
fi

if [ "${PORTAL_INTERNAL_CIDR}" = "0.0.0.0/0" ]; then
  log "warn" "PORTAL_INTERNAL_CIDR=0.0.0.0/0: portal abierto a internet. Es una decision explicita del cliente (RF-ID-08): anotala en el acta de instalacion y revisa docs/cliente/endurecimiento.md."
fi

log "info" "Las tres redes del borde estan definidas y son CIDR IPv4 validos."
