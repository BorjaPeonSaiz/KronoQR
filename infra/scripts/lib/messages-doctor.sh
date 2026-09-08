#!/usr/bin/env bash
#
# KronoQR — mensajes de doctor.sh en espanol e ingles.
#
# NO SE EJECUTA SOLO: lo carga doctor.sh DESPUES de lib/messages.sh, cuyo
# catalogo amplia. Las claves de este fichero llevan el prefijo `d_` para no
# pisar las del instalador ni las del actualizador; las comunes a los tres
# scripts —`check_ok`, `check_warn`, `check_fail`, `fix`, `exit_line`,
# `bad_option`, `missing_value`, `bad_lang`, `req_summary_fail`, las de
# Docker (`c_docker*`)— se usan tal cual de messages.sh y por eso no se
# repiten aqui. Lo mismo con `c_disk`/`f_disk`: son las del instalador, y
# doctor.sh comprueba el disco con el MISMO umbral (KQ_MIN_DISK_GIB en
# doctor.sh), asi que tiene sentido que hablen igual.
#
# Mismas reglas que los otros dos catalogos: cada mensaje de error dice QUE
# HACER; los identificadores van en ingles y el texto en los dos idiomas;
# `kq_msg_check_catalog` comprueba que ninguna clave falta en el otro idioma;
# y NINGUN MENSAJE LLEVA UN SECRETO —del `.env` solo se leen rutas, puertos y
# nombres de fichero, nunca el valor de una clave (regla dura 21)—.

# Las dos tablas las consume kq_text, que vive en messages.sh: para
# ShellCheck aqui "no se usan". La supresion es de FICHERO porque en el solo
# hay tablas.
# shellcheck disable=SC2034

set -euo pipefail
IFS=$'\n\t'

# Declarados de nuevo SIN `=()`: bash conserva lo que messages.sh ya cargo y
# ShellCheck sabe que los indices son claves de texto, no expresiones.
declare -A KQ_MSG_ES KQ_MSG_EN

#------------------------------------------------------------------------------
# Espanol
#------------------------------------------------------------------------------
KQ_MSG_ES[d_usage]="KronoQR — diagnostico de una instalacion existente, sin entrar al contenedor.

Uso:
  doctor.sh [opciones]

Opciones:
  --current RUTA   Directorio de la instalacion (donde estan su
                   docker-compose.yml y su .env). Por defecto se localiza
                   preguntando a Docker por el proyecto en marcha.
  --lang es|en     Idioma del informe. Por defecto, el del sistema.
  --help           Esta ayuda.

Que hace: si el contenedor 'app' esta en marcha y sano, delega en el
diagnostico real del producto ('php artisan product:doctor') y muestra su
salida. Si 'app' esta parada, comprueba desde fuera lo que se puede —Docker,
cada servicio, el '.env', el disco, el certificado, los puertos— y dice como
arrancarla.

Codigos de salida: 0 correcto (puede haber avisos: se muestran y no
bloquean) · 1 uso incorrecto · 2 Docker no responde · 3 no hay instalacion
que diagnosticar · 6 el diagnostico ha encontrado al menos un fallo. Los
codigos 4 y 5 no los usa este script: no escribe ni deshace nada. Tabla
completa en docs/cliente/operacion.md."

KQ_MSG_ES[d_title]="KronoQR — diagnostico (doctor.sh)"

KQ_MSG_ES[d_c_installation]="Instalacion localizada en %s"
KQ_MSG_ES[d_f_installation_missing]="no se ha encontrado ninguna instalacion de KronoQR en este servidor: Docker no tiene ningun contenedor 'app' del proyecto '%s'. Si es un servidor nuevo, lo que hace falta es install.sh. Si la instalacion existe pero sus contenedores fueron eliminados, indica su directorio con --current RUTA."
KQ_MSG_ES[d_f_installation_ambiguous]="Docker tiene contenedores 'app' del proyecto '%s' creados desde MAS DE UN directorio: %s. Indica el directorio correcto con --current RUTA."
KQ_MSG_ES[d_f_installation_files]="en %s no estan los ficheros de una instalacion: falta %s. Indica el directorio correcto con --current RUTA."

KQ_MSG_ES[d_delegating]="El contenedor 'app' esta en marcha: delegando en 'product:doctor'."
KQ_MSG_ES[d_doctor_ok]="'product:doctor' no ha encontrado ningun problema."
KQ_MSG_ES[d_doctor_warn]="'product:doctor' ha encontrado avisos."
KQ_MSG_ES[d_doctor_warn_fix]="revisa las lineas marcadas arriba: ningun aviso impide declarar la instalacion operativa, pero suelen ser la primera senal de un problema que hoy no bloquea y manana si."
KQ_MSG_ES[d_f_doctor_failed]="'product:doctor' ha encontrado al menos un fallo. Revisa el informe de arriba —cada linea en [FALLA] dice que hacer— y repite './doctor.sh' cuando lo hayas corregido."
KQ_MSG_ES[d_f_doctor_missing_command]="esta instalacion corre una version de la aplicacion que no incluye 'product:doctor'. Actualiza con ./update.sh a una version que lo traiga; mientras tanto usa las sondas y los comandos de docs/cliente/operacion.md."
KQ_MSG_ES[d_f_doctor_unexpected]="'product:doctor' ha terminado de forma inesperada (codigo %s). Revisa la salida de arriba y 'docker compose -f %s logs app'."

KQ_MSG_ES[d_app_down_diagnosing]="El contenedor 'app' no esta en marcha o no esta sano. Comprobando desde fuera lo que se puede."
KQ_MSG_ES[d_c_app_down]="Contenedor 'app' en marcha y sano"
KQ_MSG_ES[d_f_app_down]="arrancalo con: docker compose -f %s up -d. Si no llega a estar sano en un par de minutos, revisa: docker compose -f %s logs app."

KQ_MSG_ES[d_c_service_state]="Servicio %s: %s"
KQ_MSG_ES[d_f_service_down]="arrancalo con: docker compose -f %s up -d %s. Si no arranca, revisa su registro: docker compose -f %s logs %s."

KQ_MSG_ES[d_c_disk]="Espacio libre en %s: %s%% (%s GiB)"
KQ_MSG_ES[d_c_disk_unknown]="Espacio libre en %s"
KQ_MSG_ES[d_w_disk_unknown]="no se ha podido determinar el espacio total ni libre en %s. Compruebalo a mano con 'df -h %s'."
KQ_MSG_ES[d_f_disk_low]="el disco de %s tiene menos del diez por ciento libre. Amplialo o libera espacio: si se llena, las copias y el registro horario dejan de escribir."
KQ_MSG_ES[d_f_disk_critical]="el disco de %s tiene menos del cinco por ciento libre, o menos de 1 GiB: al borde de quedarse sin espacio. Amplialo o libera espacio YA: si se llena, las copias y el registro horario dejan de escribir."

KQ_MSG_ES[d_c_env_present]=".env presente en %s"
KQ_MSG_ES[d_c_env_mode]="%s con permisos 0600"
KQ_MSG_ES[d_f_env_mode]="%s tiene permisos %s. Contiene secretos: corrigelo con 'chmod 0600 %s'."
KQ_MSG_ES[d_w_env_mode_unknown]="no se ha podido comprobar los permisos de %s (no hay 'stat' en este servidor). Compruebalo a mano: tiene que ser 0600."

KQ_MSG_ES[d_c_cert_present]="Certificado presente en %s"
KQ_MSG_ES[d_c_cert_missing]="Falta el certificado %s"
KQ_MSG_ES[d_f_cert_missing]="coloca el certificado del hotel en %s. Sin el, nginx no arranca. Procedimiento en docs/cliente/instalacion.md, seccion 1.2."
KQ_MSG_ES[d_c_cert_expiry]="Certificado %s: caduca %s"
KQ_MSG_ES[d_c_cert_expiry_unknown]="Caducidad de %s"
KQ_MSG_ES[d_f_cert_expired]="el certificado %s CADUCO el %s. Sustituyelo por uno vigente: los quioscos dejaran de confiar en la conexion."
KQ_MSG_ES[d_w_cert_expiring]="el certificado %s caduca el %s (menos de %s dias). Renuevalo antes de esa fecha."
KQ_MSG_ES[d_w_cert_unknown]="no se ha podido leer la caducidad de %s (falta 'openssl' en este servidor, o el fichero no es un certificado valido). Compruebalo a mano: 'openssl x509 -enddate -noout -in %s'."

KQ_MSG_ES[d_c_port_listening]="Puerto %s: algo escucha"
KQ_MSG_ES[d_c_port_not_listening]="Puerto %s: nada escucha"
KQ_MSG_ES[d_w_port_not_listening]="nada escucha en el puerto %s. Es lo esperable con la aplicacion parada: arrancala con 'docker compose -f %s up -d' y repite este diagnostico."
KQ_MSG_ES[d_c_port_unknown]="Puerto %s: no se ha podido comprobar"
KQ_MSG_ES[d_w_port_unknown]="este servidor no tiene 'ss' ni 'netstat', y tampoco se ha podido abrir una conexion de prueba al puerto %s. Compruebalo a mano."

KQ_MSG_ES[d_summary_fail]="Diagnostico externo: %s comprobaciones, %s fallo(s), %s aviso(s)."
KQ_MSG_ES[d_summary_ok]="Diagnostico externo: %s comprobaciones, sin fallos, %s aviso(s)."
KQ_MSG_ES[d_how_to_start]="Como arrancar la aplicacion:
  docker compose -f %s up -d

Despues, repite: ./doctor.sh"

#------------------------------------------------------------------------------
# English
#------------------------------------------------------------------------------
KQ_MSG_EN[d_usage]="KronoQR — diagnostic of an existing installation, without entering the container.

Usage:
  doctor.sh [options]

Options:
  --current PATH   Installation directory (where its docker-compose.yml and
                   .env are). Defaults to asking Docker for the running
                   project.
  --lang es|en     Report language. Defaults to the system locale.
  --help           This help.

What it does: if the 'app' container is up and healthy, it delegates on the
product's real diagnostic ('php artisan product:doctor') and shows its
output. If 'app' is down, it checks what it can from the outside —Docker,
each service, the '.env', disk space, the certificate, the ports— and says
how to start it.

Exit codes: 0 success (there may be warnings: they are shown and do not
block) · 1 wrong usage · 2 Docker does not answer · 3 no installation to
diagnose · 6 the diagnostic found at least one failure. Codes 4 and 5 are
not used by this script: it writes and undoes nothing. Full table in
docs/cliente/operacion.md."

KQ_MSG_EN[d_title]="KronoQR — diagnostic (doctor.sh)"

KQ_MSG_EN[d_c_installation]="Installation located at %s"
KQ_MSG_EN[d_f_installation_missing]="no KronoQR installation was found on this server: Docker has no 'app' container of project '%s'. On a new server what you need is install.sh. If the installation exists but its containers were removed, point to its directory with --current PATH."
KQ_MSG_EN[d_f_installation_ambiguous]="Docker has 'app' containers of project '%s' created from MORE THAN ONE directory: %s. Point to the right directory with --current PATH."
KQ_MSG_EN[d_f_installation_files]="%s does not hold the files of an installation: %s is missing. Point to the right directory with --current PATH."

KQ_MSG_EN[d_delegating]="The 'app' container is up: delegating on 'product:doctor'."
KQ_MSG_EN[d_doctor_ok]="'product:doctor' found no problem."
KQ_MSG_EN[d_doctor_warn]="'product:doctor' found warnings."
KQ_MSG_EN[d_doctor_warn_fix]="check the lines marked above: no warning stops the installation from being declared operational, but they are usually the first sign of a problem that does not block today and will tomorrow."
KQ_MSG_EN[d_f_doctor_failed]="'product:doctor' found at least one failure. Check the report above —every [FAIL] line says what to do— and run './doctor.sh' again once fixed."
KQ_MSG_EN[d_f_doctor_missing_command]="this installation runs a version of the application that does not include 'product:doctor'. Upgrade with ./update.sh to a version that has it; meanwhile use the probes and commands in docs/cliente/operacion.md."
KQ_MSG_EN[d_f_doctor_unexpected]="'product:doctor' ended unexpectedly (exit code %s). Check the output above and 'docker compose -f %s logs app'."

KQ_MSG_EN[d_app_down_diagnosing]="The 'app' container is not up or is not healthy. Checking what can be checked from the outside."
KQ_MSG_EN[d_c_app_down]="Container 'app' up and healthy"
KQ_MSG_EN[d_f_app_down]="start it with: docker compose -f %s up -d. If it does not become healthy within a couple of minutes, check: docker compose -f %s logs app."

KQ_MSG_EN[d_c_service_state]="Service %s: %s"
KQ_MSG_EN[d_f_service_down]="start it with: docker compose -f %s up -d %s. If it does not start, check its log: docker compose -f %s logs %s."

KQ_MSG_EN[d_c_disk]="Free disk on %s: %s%% (%s GiB)"
KQ_MSG_EN[d_c_disk_unknown]="Free disk on %s"
KQ_MSG_EN[d_w_disk_unknown]="could not determine the total or free space on %s. Check it by hand with 'df -h %s'."
KQ_MSG_EN[d_f_disk_low]="the disk holding %s has less than ten percent free. Grow it or free up space: if it fills up, backups and the time record stop being written."
KQ_MSG_EN[d_f_disk_critical]="the disk holding %s has less than five percent free, or less than 1 GiB: on the edge of running out of space. Grow it or free up space NOW: if it fills up, backups and the time record stop being written."

KQ_MSG_EN[d_c_env_present]=".env present at %s"
KQ_MSG_EN[d_c_env_mode]="%s with mode 0600"
KQ_MSG_EN[d_f_env_mode]="%s has mode %s. It holds secrets: fix it with 'chmod 0600 %s'."
KQ_MSG_EN[d_w_env_mode_unknown]="could not check the permissions of %s (no 'stat' on this server). Check it by hand: it must be 0600."

KQ_MSG_EN[d_c_cert_present]="Certificate present at %s"
KQ_MSG_EN[d_c_cert_missing]="Certificate missing: %s"
KQ_MSG_EN[d_f_cert_missing]="put the hotel certificate at %s. Without it nginx does not start. Procedure in docs/cliente/instalacion.md, section 1.2."
KQ_MSG_EN[d_c_cert_expiry]="Certificate %s: expires %s"
KQ_MSG_EN[d_c_cert_expiry_unknown]="Expiry of %s"
KQ_MSG_EN[d_f_cert_expired]="certificate %s EXPIRED on %s. Replace it with a valid one: kiosks will stop trusting the connection."
KQ_MSG_EN[d_w_cert_expiring]="certificate %s expires on %s (fewer than %s days). Renew it before that date."
KQ_MSG_EN[d_w_cert_unknown]="could not read the expiry of %s ('openssl' is missing on this server, or the file is not a valid certificate). Check it by hand: 'openssl x509 -enddate -noout -in %s'."

KQ_MSG_EN[d_c_port_listening]="Port %s: something is listening"
KQ_MSG_EN[d_c_port_not_listening]="Port %s: nothing is listening"
KQ_MSG_EN[d_w_port_not_listening]="nothing is listening on port %s. Expected while the application is down: start it with 'docker compose -f %s up -d' and run this diagnostic again."
KQ_MSG_EN[d_c_port_unknown]="Port %s: could not check"
KQ_MSG_EN[d_w_port_unknown]="this server has neither 'ss' nor 'netstat', and a test connection to port %s could not be opened either. Check it by hand."

KQ_MSG_EN[d_summary_fail]="External diagnostic: %s checks, %s failure(s), %s warning(s)."
KQ_MSG_EN[d_summary_ok]="External diagnostic: %s checks, no failures, %s warning(s)."
KQ_MSG_EN[d_how_to_start]="How to start the application:
  docker compose -f %s up -d

Then run again: ./doctor.sh"
