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
#   0  estan las tres y su sintaxis CIDR es valida (y la de TRUSTED_PROXY_CIDR, si viene)
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
# TRUSTED_PROXY_CIDR (PP-03) es OPCIONAL: vacia, no hay proxy de confianza. Con un
# proxy o una CDN por delante, lleva la lista de sus direcciones, separadas por
# comas, cada una un CIDR IPv4. `06-kronoqr-real-ip.envsh` la convierte en
# `set_real_ip_from`. Se RECHAZA 0.0.0.0/0: confiar en todo el mundo deja que
# cualquiera falsifique X-Forwarded-For y se haga pasar por un quiosco.
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

# ADMIN_INTERNAL_CIDR (PP-10) es OPCIONAL: vacia, `/admin/`, `/api/v1/auth/*` y `/api/v1/setup/*` no se
# filtran; con valor, UN solo CIDR IPv4 (la misma sintaxis que las tres redes).
if [ -n "${ADMIN_INTERNAL_CIDR:-}" ]; then
  visible="${ADMIN_INTERNAL_CIDR//[^0-9A-Za-z./: ,-]/?}"
  if ! [[ "${ADMIN_INTERNAL_CIDR}" =~ ${CIDR_IPV4} ]]; then
    log "error" "ADMIN_INTERNAL_CIDR='${visible}' no es un CIDR IPv4 valido (se espera a.b.c.d/n, por ejemplo 10.20.0.0/24, con un solo rango)."
    log "error" "Que hacer: corrige ADMIN_INTERNAL_CIDR en el .env de la instalacion, o dejala vacia para no filtrar el panel por red. Una direccion suelta se escribe con /32; IPv6 no se admite. Explicado en docs/cliente/endurecimiento.md."
    invalidas=1
  fi
fi

# Opcional: solo se valida si viene. Una lista con comas, un CIDR por elemento.
if [ -n "${TRUSTED_PROXY_CIDR:-}" ]; then
  visible="${TRUSTED_PROXY_CIDR//[^0-9A-Za-z./: ,-]/?}"
  IFS=',' read -r -a proxies <<<"${TRUSTED_PROXY_CIDR}"
  for proxy in "${proxies[@]}"; do
    proxy="${proxy#"${proxy%%[![:space:]]*}"}"
    proxy="${proxy%"${proxy##*[![:space:]]}"}"
    if ! [[ "${proxy}" =~ ${CIDR_IPV4} ]]; then
      log "error" "TRUSTED_PROXY_CIDR='${visible}' contiene un elemento que no es un CIDR IPv4 valido (se espera a.b.c.d/n, separados por comas, por ejemplo 10.0.0.5/32,10.0.1.0/24)."
      log "error" "Que hacer: corrige TRUSTED_PROXY_CIDR en el .env de la instalacion, o dejala vacia si no hay proxy ni balanceador delante. Una direccion suelta se escribe con /32. Explicado en docs/cliente/instalacion.md."
      invalidas=1
      break
    fi
    if [ "${proxy}" = "0.0.0.0/0" ]; then
      log "error" "TRUSTED_PROXY_CIDR incluye 0.0.0.0/0: confiaria en cualquier origen y cualquiera podria falsificar su IP con X-Forwarded-For, saltandose el limite de peticiones y el candado del portal."
      log "error" "Que hacer: pon solo las direcciones del proxy o balanceador (por ejemplo 10.0.0.5/32), o dejala vacia si no lo hay."
      invalidas=1
      break
    fi
  done
fi

if [ "${invalidas}" -ne 0 ]; then
  exit 1
fi

if [ -n "${ADMIN_INTERNAL_CIDR:-}" ]; then
  log "info" "ADMIN_INTERNAL_CIDR definida: /admin/, /api/v1/auth/ y /api/v1/setup/ solo se sirven a ese rango."
else
  log "info" "ADMIN_INTERNAL_CIDR vacia: /admin/, /api/v1/auth/ y /api/v1/setup/ no se filtran por red (decision del propietario, ver docs/cliente/endurecimiento.md)."
fi

if [ "${PORTAL_INTERNAL_CIDR}" = "0.0.0.0/0" ]; then
  log "warn" "PORTAL_INTERNAL_CIDR=0.0.0.0/0: portal abierto a internet. Es una decision explicita del cliente (RF-ID-08): anotala en el acta de instalacion y revisa docs/cliente/endurecimiento.md."
fi

log "info" "Las tres redes del borde estan definidas y son CIDR IPv4 validos."
if [ -n "${TRUSTED_PROXY_CIDR:-}" ]; then
  log "info" "TRUSTED_PROXY_CIDR definida: nginx tomara la IP del visitante de X-Forwarded-For solo si la peticion llega de esos proxies."
fi
