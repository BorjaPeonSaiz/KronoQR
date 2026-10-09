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
KQ_MSG_ES[d_f_scheduler_down]="el planificador esta parado. Sin el NO se hacen la copia nocturna, ni la verificacion de la cadena de auditoria, ni la conciliacion del registro horario, ni las purgas, ni las metricas del WAL; el fichaje sigue, pero nada de eso avisa. Arrancalo con: sudo docker compose -f %s up -d scheduler. Si vuelve a pararse, mira por que: sudo docker compose -f %s logs --tail 100 scheduler. Cuando este en marcha, comprueba que la ultima copia es de hoy o de anoche (docs/runbooks/restaurar-backup.md §2)."
KQ_MSG_ES[d_f_horizon_down]="el procesador de trabajos en cola esta parado. El fichaje sigue, pero no termina ninguna exportacion integra (la que se entrega a la Inspeccion o a un empleado que pide sus datos), ningun informe en diferido ni ningun aviso de incidencias a los responsables: se quedan esperando. Arrancalo con: sudo docker compose -f %s up -d horizon. Si vuelve a pararse, mira por que: sudo docker compose -f %s logs --tail 100 horizon."
KQ_MSG_ES[d_w_reverb_down]="el servidor de tiempo real esta parado: el panel deja de actualizar la presencia en vivo (recargar la pagina da el dato bueno). No se pierde nada. Arrancalo con: sudo docker compose -f %s up -d reverb. Si vuelve a pararse: sudo docker compose -f %s logs --tail 100 reverb."
KQ_MSG_ES[d_c_other_dir]="Este doctor.sh es el del directorio %s (version %s), que no es el de la instalacion vigente"
KQ_MSG_ES[d_w_other_dir]="el diagnostico de arriba es de la instalacion vigente, %s (version %s), pero trabajar desde otro directorio es la forma de acabar lanzando 'docker compose' contra la version equivocada. Si es el de una version anterior a una actualizacion, ya no se usa: cd %s y repite ./doctor.sh desde alli."
KQ_MSG_ES[d_f_external_failed]="al menos una comprobacion ha fallado: las de este script (las lineas en [FALLA], antes y despues del informe de 'product:doctor') o las del propio 'product:doctor'. Cada linea en [FALLA] dice que hacer; repite './doctor.sh' cuando lo hayas corregido."

KQ_MSG_ES[d_c_disk]="Espacio libre en %s: %s%% (%s GiB)"
KQ_MSG_ES[d_c_disk_unknown]="Espacio libre en %s"
KQ_MSG_ES[d_w_disk_unknown]="no se ha podido determinar el espacio total ni libre en %s. Compruebalo a mano con 'df -h %s'."
KQ_MSG_ES[d_f_disk_low]="el disco de %s tiene menos del diez por ciento libre. Amplialo o libera espacio: si se llena, las copias y el registro horario dejan de escribir."
KQ_MSG_ES[d_f_disk_critical]="el disco de %s tiene menos del cinco por ciento libre, o menos de 1 GiB: al borde de quedarse sin espacio. Amplialo o libera espacio YA: si se llena, las copias y el registro horario dejan de escribir."

KQ_MSG_ES[d_c_env_present]=".env presente en %s"
KQ_MSG_ES[d_c_env_mode]="%s con permisos 0600"
KQ_MSG_ES[d_f_env_mode]="%s tiene permisos %s. Contiene secretos: corrigelo con 'chmod 0600 %s'."
KQ_MSG_ES[d_w_env_mode_unknown]="no se ha podido comprobar los permisos de %s (no hay 'stat' en este servidor). Compruebalo a mano: tiene que ser 0600."

KQ_MSG_ES[d_c_accept_unauth]="KRONOQR_ACCEPT_UNAUTHENTICATED esta en el .env"
KQ_MSG_ES[d_f_accept_unauth]="%s define KRONOQR_ACCEPT_UNAUTHENTICATED: esa bandera deja restaurar copias de la 2.1.0 SIN autenticar y se pasa por invocacion, nunca se deja puesta (ADR-049). Borra la linea del .env."
KQ_MSG_ES[d_c_accept_unauth_cron]="KRONOQR_ACCEPT_UNAUTHENTICATED esta en una tabla de cron"
KQ_MSG_ES[d_f_accept_unauth_cron]="%s define KRONOQR_ACCEPT_UNAUTHENTICATED: un simulacro programado que acepta copias de la 2.1.0 SIN autenticar daria por buena una copia plantada. La bandera se pasa por invocacion (--accept-unauthenticated), nunca en una tarea programada (ADR-049). Borrala de esa linea."
KQ_MSG_ES[d_c_wal_key]="La clave del WAL deriva de BACKUP_ENCRYPTION_KEY y es la del archivo"
KQ_MSG_ES[d_w_wal_key_openssl]="no se ha podido derivar la clave del WAL: hace falta openssl con SHA3-256 (OpenSSL 1.1.1 o posterior) en este servidor."
KQ_MSG_ES[d_f_wal_key_mismatch]="BACKUP_WAL_KEY de %s no es la derivada de BACKUP_ENCRYPTION_KEY (falta, se roto la clave sin recalcularla, o se edito a mano): PostgreSQL archivaria con una clave que la restauracion no recalcularia y dejaria de archivar si falta. Recalculala y recrea postgres desde el directorio de la instalacion: 'cd %s && sudo bash ./backup.sh derive-wal-key --write-env .env && sudo docker compose up -d postgres', y repite './doctor.sh'. Ver docs/runbooks/restaurar-backup.md §4.2."
KQ_MSG_ES[d_f_wal_key_kid]="el segmento de WAL cifrado mas reciente se cifro con OTRA clave que la que deriva hoy de BACKUP_ENCRYPTION_KEY. Si se roto la clave de copias, recalcula BACKUP_WAL_KEY y recrea postgres ('cd %s && sudo bash ./backup.sh derive-wal-key --write-env .env && sudo docker compose up -d postgres') y conserva la clave anterior como BACKUP_ENCRYPTION_KEY_PREVIOUS (solo para restaurar) mientras haya segmentos con ella. Ver docs/runbooks/restaurar-backup.md §4.1."
KQ_MSG_ES[d_c_wal_legacy]="Quedan %s segmentos de WAL heredados sin cifrar"
KQ_MSG_ES[d_w_wal_legacy]="se cifran solos en minutos tras actualizar. Si no bajan a 0, lanza 'docker compose exec -T postgres kronoqr-wal-migrate' (es idempotente) y comprueba 'docker compose logs postgres'. Ver docs/runbooks/restaurar-backup.md §4.5."
KQ_MSG_ES[d_c_backup_root]="La aplicacion no puede escribir en la raiz de las copias (solo lectura)"
KQ_MSG_ES[d_f_backup_root_writable]="el contenedor horizon SI puede escribir en la raiz de BACKUP_PATH: el compose en uso (%s) es el de la 2.1.0 o se ha editado, y quien ejecute codigo en la aplicacion podria borrar copias o plantar una manipulada (A3-R2). Usa el docker-compose.yml de la version instalada y recrea los servicios. Ver docs/runbooks/restaurar-backup.md."
KQ_MSG_ES[d_c_update_logs]="Registro tecnico de la actualizacion en %s: purgado a %s dias"
KQ_MSG_ES[d_c_update_logs_old]="Hay %s registros tecnicos de actualizacion de mas de %s dias en %s"
KQ_MSG_ES[d_w_update_logs_old]="pueden llevar datos personales y su plazo es de 30 dias: ejecuta doctor.sh como root ('sudo ./doctor.sh') para purgarlos, o borralos de %s."
KQ_MSG_ES[d_c_backup_role]="El rol de las copias (%s) es de solo lectura"
KQ_MSG_ES[d_c_backup_role_check]="Rol de las copias (%s)"
KQ_MSG_ES[d_f_backup_role_privileged]="el rol de las copias (%s) es superusuario, o puede crear roles o bases, o se salta RLS. Una copia solo necesita leer: con ese rol, quien ejecute codigo en el contenedor 'scheduler' podria reescribir el registro legal (AUD-1). BACKUP_DB_USERNAME y BACKUP_DB_PASSWORD del .env tienen que ser los de fichaje_backup, no los de migracion. Sigue docs/runbooks/rotacion-secretos.md (seccion «El rol de las copias»)."
KQ_MSG_ES[d_w_backup_role_missing]="el rol de las copias (%s) no existe en PostgreSQL: las copias fallaran. Ejecuta ./update.sh (lo aprovisiona) o sigue docs/runbooks/rotacion-secretos.md (seccion «El rol de las copias»)."
KQ_MSG_ES[d_w_backup_role_name]="BACKUP_DB_USERNAME del .env (%s) no es un nombre de rol valido (minusculas, digitos y guion bajo): no se ha podido comprobar el rol de las copias. Corrigelo en el .env."
KQ_MSG_ES[d_w_backup_role_unknown]="no se ha podido comprobar el rol de las copias (%s): PostgreSQL no responde o el servicio 'postgres' esta parado. Arrancalo y repite ./doctor.sh."

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

KQ_MSG_ES[d_c_redis_stable]="Redis: %s, %s reinicio(s) desde que se creo el contenedor"
KQ_MSG_ES[d_c_redis_loop]="Redis se esta reiniciando en bucle (%s reinicios)"
KQ_MSG_ES[d_f_redis_loop_aof]="el registro de Redis dice que su fichero AOF esta danado, lo habitual tras un corte de luz. Con Redis caido el fichaje NO se para —los quioscos encolan y /scan sigue respondiendo—, pero caen el acceso al panel y al portal (las sesiones viven en Redis), las colas de trabajos y el tiempo real, y /ready responde 503. Reparalo asi; solo se pierde la ultima escritura corrupta del fichero:
  docker compose -f %s stop redis
  echo y | docker compose -f %s run --rm -T --no-deps --entrypoint redis-check-aof redis --fix /data/appendonlydir/appendonly.aof.manifest
  docker compose -f %s up -d redis
Despues repite ./doctor.sh. El procedimiento completo esta en docs/runbooks/errores-en-el-panel.md."
KQ_MSG_ES[d_f_redis_loop_other]="Redis no se mantiene en pie y su registro no apunta al fichero AOF. Mira por que: docker compose -f %s logs --tail 50 redis. Las causas habituales son disco lleno o falta de memoria; si el registro habla de 'append only file', sigue docs/runbooks/errores-en-el-panel.md. Con Redis caido el fichaje no se para, pero caen el panel, el portal, las colas y el tiempo real."
KQ_MSG_ES[d_c_storage_volume]="Volumen de ficheros generados (app-storage) creado"
KQ_MSG_ES[d_c_storage_volume_missing]="Falta el volumen de ficheros generados (app-storage)"
KQ_MSG_ES[d_f_storage_volume_missing]="sin el, cada contenedor tiene su propio storage/app: la exportacion integra pedida desde el panel no se puede descargar y las purgas no ven los ficheros que deben borrar. Comprueba que el compose es el del paquete (docker compose -f %s config contiene app-storage) y recrea los servicios con docker compose -f %s up -d. Si la instalacion es anterior a la 2.2.0, actualiza con update.sh."
KQ_MSG_ES[d_c_storage_mounted]="%s monta app-storage en /var/www/html/storage/app"
KQ_MSG_ES[d_c_storage_not_mounted]="%s NO monta app-storage en /var/www/html/storage/app"
KQ_MSG_ES[d_f_storage_not_mounted]="recrea el servicio con el compose del paquete: docker compose -f %s up -d --force-recreate %s. Mientras no lo monte, lo que genera un servicio no lo ve otro y se pierde al recrear el contenedor."
KQ_MSG_ES[d_c_storage_shared]="Un fichero escrito desde horizon se lee desde app"
KQ_MSG_ES[d_c_storage_not_shared]="Un fichero escrito desde horizon NO se lee desde app"
KQ_MSG_ES[d_f_storage_not_shared]="los dos montan un volumen pero no el mismo, o horizon no puede escribir en el. Mira docker compose -f %s ps y docker compose -f %s logs --tail 50 horizon, y recrea con docker compose -f %s up -d --force-recreate app horizon scheduler."
KQ_MSG_ES[d_c_storage_probe_skipped]="No se ha podido comprobar que horizon y app comparten el volumen"
KQ_MSG_ES[d_w_storage_probe_skipped]="horizon no esta en marcha o no ha respondido. Arrancalo con docker compose -f %s up -d horizon y repite doctor.sh: sin horizon no se generan exportaciones ni informes en diferido."
KQ_MSG_ES[d_c_storage_root]="La raiz del volumen (storage/app) es app:app 0700"
KQ_MSG_ES[d_c_storage_root_bad]="La raiz del volumen (storage/app) es %s con modo %s, y debe ser app:app 0700"
KQ_MSG_ES[d_f_storage_root]="los ficheros del volumen pueden contener todos los datos personales (la exportacion integra) y no deben ser legibles por otro usuario. Corrigelo con docker compose -f %s exec -u root app sh -c 'chown app:app /var/www/html/storage/app && chmod 0700 /var/www/html/storage/app'. Un volumen nuevo hereda ese modo de la imagen; si lo creo otra version, vuelve a comprobarlo."
KQ_MSG_ES[d_c_storage_size]="Tamano de los ficheros generados (app-storage): %s"

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
KQ_MSG_EN[d_f_scheduler_down]="the scheduler is stopped. Without it there is NO nightly backup, no audit chain verification, no work record reconciliation, no purges and no WAL metrics; clocking goes on, but none of that raises an alarm. Start it with: sudo docker compose -f %s up -d scheduler. If it stops again, find out why: sudo docker compose -f %s logs --tail 100 scheduler. Once it runs, check that the latest backup is from today or last night (docs/runbooks/restaurar-backup.md §2, in Spanish)."
KQ_MSG_EN[d_f_horizon_down]="the queued job worker is stopped. Clocking goes on, but no full export (the one handed to the Labour Inspectorate or to an employee asking for their data), no deferred report and no incident notice to managers gets done: they wait. Start it with: sudo docker compose -f %s up -d horizon. If it stops again, find out why: sudo docker compose -f %s logs --tail 100 horizon."
KQ_MSG_EN[d_w_reverb_down]="the real-time server is stopped: the panel stops updating live presence (reloading the page gives the right figure). Nothing is lost. Start it with: sudo docker compose -f %s up -d reverb. If it stops again: sudo docker compose -f %s logs --tail 100 reverb."
KQ_MSG_EN[d_c_other_dir]="This doctor.sh belongs to directory %s (version %s), which is not the current installation's"
KQ_MSG_EN[d_w_other_dir]="the diagnosis above is of the current installation, %s (version %s), but working from another directory is how you end up running 'docker compose' against the wrong version. If it is the one from before an update, it is no longer used: cd %s and run ./doctor.sh again from there."
KQ_MSG_EN[d_f_external_failed]="at least one check failed: this script's (the [FAIL] lines before and after the 'product:doctor' report) or those of 'product:doctor' itself. Every [FAIL] line says what to do; run './doctor.sh' again once fixed."

KQ_MSG_EN[d_c_disk]="Free disk on %s: %s%% (%s GiB)"
KQ_MSG_EN[d_c_disk_unknown]="Free disk on %s"
KQ_MSG_EN[d_w_disk_unknown]="could not determine the total or free space on %s. Check it by hand with 'df -h %s'."
KQ_MSG_EN[d_f_disk_low]="the disk holding %s has less than ten percent free. Grow it or free up space: if it fills up, backups and the time record stop being written."
KQ_MSG_EN[d_f_disk_critical]="the disk holding %s has less than five percent free, or less than 1 GiB: on the edge of running out of space. Grow it or free up space NOW: if it fills up, backups and the time record stop being written."

KQ_MSG_EN[d_c_env_present]=".env present at %s"
KQ_MSG_EN[d_c_env_mode]="%s with mode 0600"
KQ_MSG_EN[d_f_env_mode]="%s has mode %s. It holds secrets: fix it with 'chmod 0600 %s'."
KQ_MSG_EN[d_w_env_mode_unknown]="could not check the permissions of %s (no 'stat' on this server). Check it by hand: it must be 0600."

KQ_MSG_EN[d_c_accept_unauth]="KRONOQR_ACCEPT_UNAUTHENTICATED is in the .env"
KQ_MSG_EN[d_f_accept_unauth]="%s defines KRONOQR_ACCEPT_UNAUTHENTICATED: that flag lets you restore 2.1.0 backups WITHOUT authentication and is passed per invocation, never left on (ADR-049). Delete the line from the .env."
KQ_MSG_EN[d_c_accept_unauth_cron]="KRONOQR_ACCEPT_UNAUTHENTICATED is in a cron table"
KQ_MSG_EN[d_f_accept_unauth_cron]="%s defines KRONOQR_ACCEPT_UNAUTHENTICATED: a scheduled drill that accepts 2.1.0 backups WITHOUT authentication would pass a planted copy as good. The flag is passed per invocation (--accept-unauthenticated), never in a scheduled job (ADR-049). Remove it from that line."
KQ_MSG_EN[d_c_wal_key]="The WAL key derives from BACKUP_ENCRYPTION_KEY and is the one the archive uses"
KQ_MSG_EN[d_w_wal_key_openssl]="the WAL key could not be derived: this server needs openssl with SHA3-256 (OpenSSL 1.1.1 or later)."
KQ_MSG_EN[d_f_wal_key_mismatch]="BACKUP_WAL_KEY in %s is not the one derived from BACKUP_ENCRYPTION_KEY (missing, the key was rotated without recomputing it, or it was edited by hand): PostgreSQL would archive with a key the restore would not recompute, and stop archiving if it is missing. Recompute it and recreate postgres from the installation directory: 'cd %s && sudo bash ./backup.sh derive-wal-key --write-env .env && sudo docker compose up -d postgres', then run './doctor.sh' again. See docs/runbooks/restaurar-backup.md §4.2 (in Spanish)."
KQ_MSG_EN[d_f_wal_key_kid]="the most recent encrypted WAL segment was encrypted with a DIFFERENT key than the one derived today from BACKUP_ENCRYPTION_KEY. If the backup key was rotated, recompute BACKUP_WAL_KEY and recreate postgres ('cd %s && sudo bash ./backup.sh derive-wal-key --write-env .env && sudo docker compose up -d postgres') and keep the old key as BACKUP_ENCRYPTION_KEY_PREVIOUS (restore only) while segments made with it remain. See docs/runbooks/restaurar-backup.md §4.1 (in Spanish)."
KQ_MSG_EN[d_c_wal_legacy]="%s legacy WAL segments are still unencrypted"
KQ_MSG_EN[d_w_wal_legacy]="they encrypt themselves within minutes of updating. If the count does not drop to 0, run 'docker compose exec -T postgres kronoqr-wal-migrate' (it is idempotent) and check 'docker compose logs postgres'. See docs/runbooks/restaurar-backup.md §4.5 (in Spanish)."
KQ_MSG_EN[d_c_backup_root]="The application cannot write to the backup root (read-only)"
KQ_MSG_EN[d_f_backup_root_writable]="the horizon container CAN write to the BACKUP_PATH root: the compose file in use (%s) is the 2.1.0 one or was edited, and whoever runs code in the application could delete backups or plant a tampered one (A3-R2). Use the docker-compose.yml of the installed version and recreate the services. See docs/runbooks/restaurar-backup.md (in Spanish)."
KQ_MSG_EN[d_c_update_logs]="Update technical log in %s: purged at %s days"
KQ_MSG_EN[d_c_update_logs_old]="There are %s update technical logs older than %s days in %s"
KQ_MSG_EN[d_w_update_logs_old]="they may hold personal data and their term is 30 days: run doctor.sh as root ('sudo ./doctor.sh') to purge them, or delete them from %s."
KQ_MSG_EN[d_c_backup_role]="The backup role (%s) is read-only"
KQ_MSG_EN[d_c_backup_role_check]="Backup role (%s)"
KQ_MSG_EN[d_f_backup_role_privileged]="the backup role (%s) is a superuser, or can create roles or databases, or bypasses RLS. A backup only needs to read: with that role, whoever runs code in the 'scheduler' container could rewrite the legal record (AUD-1). BACKUP_DB_USERNAME and BACKUP_DB_PASSWORD in the .env must be those of fichaje_backup, not the migration ones. Follow docs/runbooks/rotacion-secretos.md (section «El rol de las copias»)."
KQ_MSG_EN[d_w_backup_role_missing]="the backup role (%s) does not exist in PostgreSQL: backups will fail. Run ./update.sh (it provisions it) or follow docs/runbooks/rotacion-secretos.md (section «El rol de las copias»)."
KQ_MSG_EN[d_w_backup_role_name]="BACKUP_DB_USERNAME in the .env (%s) is not a valid role name (lowercase letters, digits and underscore): the backup role could not be checked. Fix it in the .env."
KQ_MSG_EN[d_w_backup_role_unknown]="could not check the backup role (%s): PostgreSQL does not answer or the 'postgres' service is stopped. Start it and run ./doctor.sh again."

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

KQ_MSG_EN[d_c_redis_stable]="Redis: %s, %s restart(s) since the container was created"
KQ_MSG_EN[d_c_redis_loop]="Redis is restarting in a loop (%s restarts)"
KQ_MSG_EN[d_f_redis_loop_aof]="the Redis log says its AOF file is damaged, which is usual after a power cut. With Redis down clocking-in does NOT stop —kiosks queue and /scan keeps answering—, but panel and portal sign-in (sessions live in Redis), job queues and real time go down, and /ready answers 503. Repair it like this; only the last corrupt write of the file is lost:
  docker compose -f %s stop redis
  echo y | docker compose -f %s run --rm -T --no-deps --entrypoint redis-check-aof redis --fix /data/appendonlydir/appendonly.aof.manifest
  docker compose -f %s up -d redis
Then run ./doctor.sh again. The full procedure is in docs/runbooks/errores-en-el-panel.md (in Spanish)."
KQ_MSG_EN[d_f_redis_loop_other]="Redis does not stay up and its log does not point at the AOF file. Find out why: docker compose -f %s logs --tail 50 redis. Usual causes are a full disk or lack of memory; if the log mentions 'append only file', follow docs/runbooks/errores-en-el-panel.md (in Spanish). With Redis down clocking-in does not stop, but the panel, the portal, the queues and real time do."
KQ_MSG_EN[d_c_storage_volume]="Generated-files volume (app-storage) exists"
KQ_MSG_EN[d_c_storage_volume_missing]="The generated-files volume (app-storage) is missing"
KQ_MSG_EN[d_f_storage_volume_missing]="without it every container has its own storage/app: the full export requested from the panel cannot be downloaded and the purges cannot see the files they must delete. Check that the compose file is the one in the package (docker compose -f %s config contains app-storage) and recreate the services with docker compose -f %s up -d. If the installation predates 2.2.0, update with update.sh."
KQ_MSG_EN[d_c_storage_mounted]="%s mounts app-storage at /var/www/html/storage/app"
KQ_MSG_EN[d_c_storage_not_mounted]="%s does NOT mount app-storage at /var/www/html/storage/app"
KQ_MSG_EN[d_f_storage_not_mounted]="recreate the service with the package compose file: docker compose -f %s up -d --force-recreate %s. Until it mounts it, what one service generates another cannot see, and it is lost when the container is recreated."
KQ_MSG_EN[d_c_storage_shared]="A file written from horizon is read from app"
KQ_MSG_EN[d_c_storage_not_shared]="A file written from horizon is NOT read from app"
KQ_MSG_EN[d_f_storage_not_shared]="both mount a volume but not the same one, or horizon cannot write to it. Look at docker compose -f %s ps and docker compose -f %s logs --tail 50 horizon, and recreate with docker compose -f %s up -d --force-recreate app horizon scheduler."
KQ_MSG_EN[d_c_storage_probe_skipped]="Could not check that horizon and app share the volume"
KQ_MSG_EN[d_w_storage_probe_skipped]="horizon is not running or did not answer. Start it with docker compose -f %s up -d horizon and run doctor.sh again: without horizon no exports or deferred reports are generated."
KQ_MSG_EN[d_c_storage_root]="The volume root (storage/app) is app:app 0700"
KQ_MSG_EN[d_c_storage_root_bad]="The volume root (storage/app) is %s with mode %s, and must be app:app 0700"
KQ_MSG_EN[d_f_storage_root]="the volume files may hold all personal data (the full export) and must not be readable by another user. Fix it with docker compose -f %s exec -u root app sh -c 'chown app:app /var/www/html/storage/app && chmod 0700 /var/www/html/storage/app'. A new volume inherits that mode from the image; if another version created it, check again."
KQ_MSG_EN[d_c_storage_size]="Size of generated files (app-storage): %s"

KQ_MSG_EN[d_summary_fail]="External diagnostic: %s checks, %s failure(s), %s warning(s)."
KQ_MSG_EN[d_summary_ok]="External diagnostic: %s checks, no failures, %s warning(s)."
KQ_MSG_EN[d_how_to_start]="How to start the application:
  docker compose -f %s up -d

Then run again: ./doctor.sh"
