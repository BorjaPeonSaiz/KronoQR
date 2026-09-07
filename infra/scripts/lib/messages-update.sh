#!/usr/bin/env bash
#
# KronoQR — mensajes de update.sh en espanol e ingles.
#
# NO SE EJECUTA SOLO: lo carga update.sh DESPUES de lib/messages.sh, cuyo
# catalogo amplia. Las claves de este fichero llevan el prefijo `u_` para no
# pisar las del instalador; las comunes a los dos scripts —`check_ok`,
# `check_warn`, `check_fail`, `fix`, `exit_line`, `waiting`, las de Docker— se
# usan tal cual de messages.sh y por eso no se repiten aqui.
#
# Mismas reglas que el catalogo del instalador: cada mensaje de error dice QUE
# HACER; los identificadores van en ingles y el texto en los dos idiomas;
# `kq_msg_check_catalog` comprueba que ninguna clave falta en el otro idioma;
# y NINGUN MENSAJE LLEVA UN SECRETO —los formatos reciben rutas, versiones,
# numeros y ordenes, nunca el valor de una clave—.

# Las dos tablas las consume kq_text, que vive en messages.sh: para ShellCheck
# aqui "no se usan". La supresion es de FICHERO porque en el solo hay tablas.
# shellcheck disable=SC2034

set -euo pipefail
IFS=$'\n\t'

# Declarados de nuevo SIN `=()`: bash conserva lo que messages.sh ya cargo y
# ShellCheck sabe que los indices son claves de texto, no expresiones.
declare -A KQ_MSG_ES KQ_MSG_EN

#------------------------------------------------------------------------------
# Espanol
#------------------------------------------------------------------------------
KQ_MSG_ES[u_usage]="KronoQR — actualizador de una instalacion existente.

Uso:
  update.sh [opciones]            actualiza la instalacion a la version de este paquete
  update.sh --check-only          solo el paso 1 (precondiciones). No toca nada
  update.sh --supported-sources   desde que versiones se puede actualizar a esta
  update.sh --chain VERSION       que versiones intermedias se aplicarian desde VERSION

Opciones:
  --current RUTA      Directorio de la instalacion actual (donde estan su
                      docker-compose.yml y su .env). Por defecto se localiza
                      preguntando a Docker por el proyecto en marcha.
  --compose-file RUTA Fichero de compose de ESTE paquete. Por defecto, el que
                      acompana al actualizador.
  --lang es|en        Idioma de los mensajes. Por defecto, el del sistema.
  --help              Esta ayuda.
  --version           Version del producto que instalaria este paquete.

Se ejecuta DESDE EL DIRECTORIO DEL PAQUETE NUEVO, descomprimido junto al de la
version actual, nunca encima. El script copia el .env y el certificado de la
instalacion actual, y el directorio anterior queda intacto para poder volver.

Pasos: precondiciones · mantenimiento (los quioscos siguen fichando) · copia
verificada, bloqueante · migraciones version a version con punto de control ·
arranque y verificacion sin exponer la version nueva · vuelta atras automatica
si algo falla · informe en el servidor.

Codigos de salida: 0 actualizado y verificado · 1 uso incorrecto · 2 precondicion
no cumplida o copia previa fallida (la instalacion NO se ha tocado) · 3 ya esta
en la version de destino, o no hay instalacion que actualizar (nada tocado) ·
4 fallo con vuelta atras completada: la version anterior esta en marcha ·
5 fallo con vuelta atras INCOMPLETA: el mensaje dice que hacer a mano. El 6 no
lo usa este script: toda verificacion fallida deshace. Tabla completa en
docs/cliente/operacion.md."

KQ_MSG_ES[u_phase_1]="Paso 1 de 7 — precondiciones. Todavia no se toca la instalacion."
KQ_MSG_ES[u_phase_2]="Paso 2 de 7 — modo mantenimiento. El panel responde 'en mantenimiento'; los quioscos siguen fichando y encolan."
KQ_MSG_ES[u_phase_3]="Paso 3 de 7 — copia de seguridad completa y verificada. BLOQUEANTE: sin copia no se continua."
KQ_MSG_ES[u_phase_4]="Paso 4 de 7 — migraciones, version a version, con punto de control entre cada una."
KQ_MSG_ES[u_phase_5]="Paso 5 de 7 — arranque de la version %s y comprobacion de salud, sin exponerla todavia."
KQ_MSG_ES[u_phase_6]="Paso 6 de 7 — VUELTA ATRAS AUTOMATICA a la copia previa."
KQ_MSG_ES[u_phase_7]="Paso 7 de 7 — informe."

KQ_MSG_ES[u_bad_option]="opcion desconocida: %s. Ejecuta \"update.sh --help\" para ver las que hay."
KQ_MSG_ES[u_check_only_done]="Solo comprobacion (--check-only): no se ha tocado nada. Vuelve a ejecutar sin la opcion para actualizar."
KQ_MSG_ES[u_req_summary_ok]="Precondiciones cumplidas: %d comprobaciones, %d avisos."
KQ_MSG_ES[u_req_summary_fail]="Precondiciones NO cumplidas: %d fallos. La instalacion no se ha tocado y sigue en la version %s."

KQ_MSG_ES[u_c_package_file]="Fichero del paquete %s"
KQ_MSG_ES[u_f_package_file]="falta %s. Este paquete esta incompleto: vuelve a descomprimirlo entero (docker-compose.yml, .env.example, VERSION, versions.txt, lib/ y los cinco scripts) y ejecuta update.sh desde ese directorio."
KQ_MSG_ES[u_c_versions_file]="Matriz de versiones %s (%d versiones publicadas)"
KQ_MSG_ES[u_f_versions_file]="%s no se puede leer o esta mal formado (%s). Es un fichero del paquete, no lo edites: vuelve a descomprimir el paquete."
KQ_MSG_ES[u_c_target_version]="Version de destino %s (fichero VERSION del paquete)"
KQ_MSG_ES[u_f_target_unlisted]="la version de este paquete, %s, no figura en %s. Es un defecto del paquete, no de tu servidor: no actualices con el y avisa al fabricante."
KQ_MSG_ES[u_c_docker_root]="Directorio de datos de Docker %s"

KQ_MSG_ES[u_c_installation]="Instalacion actual en %s"
KQ_MSG_ES[u_f_installation_missing]="no se ha encontrado ninguna instalacion de KronoQR en este servidor: Docker no conoce el proyecto '%s'. Si es un servidor nuevo, lo que quieres es install.sh. Si la instalacion existe pero sus contenedores fueron eliminados, indica su directorio con --current RUTA."
KQ_MSG_ES[u_f_installation_ambiguous]="Docker tiene contenedores del proyecto '%s' creados desde MAS DE UN directorio: %s. Solo puede haber una instalacion. Retira los contenedores que sobren o indica el directorio correcto con --current RUTA."
KQ_MSG_ES[u_f_installation_files]="en %s no estan los ficheros de una instalacion: falta %s. Indica el directorio correcto con --current RUTA."
KQ_MSG_ES[u_c_in_place]="El paquete se ha descomprimido ENCIMA de la instalacion actual (%s)"
KQ_MSG_ES[u_f_in_place]="funciona, pero el docker-compose.yml de la version anterior ya no existe y una vuelta atras tendria que usar el nuevo con las imagenes antiguas. La proxima vez descomprime el paquete en un directorio nuevo, al lado del actual."
KQ_MSG_ES[u_f_env_conflict]="este paquete ya tiene un .env con secretos (%s) que NO es el de la instalacion actual (%s). Para no mezclar dos instalaciones, retira ese fichero y vuelve a ejecutar: el actualizador copia el .env correcto."

KQ_MSG_ES[u_c_source_version]="Version instalada %s (IMAGE_TAG de %s)"
KQ_MSG_ES[u_f_source_version_missing]="el .env de la instalacion (%s) no dice que version corre: IMAGE_TAG esta vacio o no es una version. Mira la que publica la sonda (curl -k https://127.0.0.1:%s/api/v1/health), escribe IMAGE_TAG=<esa version> en ese .env y vuelve a ejecutar."
KQ_MSG_ES[u_f_source_unlisted]="la version instalada, %s, no es ninguna version publicada de KronoQR (no esta en %s). Comprueba IMAGE_TAG en %s y, si es correcta, avisa al fabricante antes de actualizar."
KQ_MSG_ES[u_c_already_target]="La instalacion YA esta en la version %s. No hay nada que hacer y no se ha tocado nada."
KQ_MSG_ES[u_f_downgrade]="la instalacion esta en la %s, POSTERIOR a la %s de este paquete. Este script no retrocede versiones: una version anterior no sabe leer el esquema de una posterior. Usa el paquete de una version igual o mas nueva."
KQ_MSG_ES[u_c_supported]="Salto %s → %s dentro de la matriz de versiones soportadas"
KQ_MSG_ES[u_f_unsupported_hop]="desde la %s no se puede saltar directamente a la %s: solo se soporta la version menor vigente y las dos anteriores (%s). Actualiza PRIMERO a la %s con su paquete, verifica, y despues a esta."
KQ_MSG_ES[u_f_unsupported_major]="la %s es de la serie %s.x y este paquete es de la %s.x. El salto de version mayor no es automatico: tiene una ventana de migracion anunciada por el fabricante. Actualiza primero a la ultima de tu serie (%s) y consulta las instrucciones del salto en docs/runbooks/actualizacion-cliente.md."
KQ_MSG_ES[u_f_unsupported_none]="desde la %s no hay camino de actualizacion publicado hacia la %s. Contacta con el fabricante indicando las dos versiones."
KQ_MSG_ES[u_c_chain]="Versiones intermedias que se aplicaran en orden: %s"

KQ_MSG_ES[u_c_images]="Imagenes de la version %s presentes en este servidor"
KQ_MSG_ES[u_images_pull]="Descargando las imagenes de la version %s que faltan (no se toca la instalacion)..."
KQ_MSG_ES[u_f_images]="no se han podido obtener las imagenes de la version %s de %s. Comprueba el acceso al registro. En una instalacion sin salida a internet, cargalas antes con 'docker load -i imagenes-%s.tar' (docs/cliente/instalacion.md §7) y vuelve a ejecutar. La instalacion no se ha tocado."
KQ_MSG_ES[u_c_space_backup]="Espacio en %s: %d GiB libres (la base ocupa %d GiB; se exigen %d)"
KQ_MSG_ES[u_f_space_backup]="la copia previa necesita al menos %d GiB libres en %s y quedan %d. Libera espacio (backup.sh prune retira copias caducadas) o monta un destino mayor y vuelve a ejecutar."
KQ_MSG_ES[u_c_space_docker]="Espacio en %s: %d GiB libres (se exigen %d)"
KQ_MSG_ES[u_f_space_docker]="las imagenes nuevas y la migracion necesitan al menos %d GiB libres en %s y quedan %d. 'docker image prune' retira imagenes sin uso; despues vuelve a ejecutar."
KQ_MSG_ES[u_c_service]="Servicio %s en marcha y sano"
KQ_MSG_ES[u_f_service]="el servicio %s no esta sano (estado: %s). Actualizar sobre un sistema que ya falla mezcla dos problemas en uno: arregla primero ('docker compose -f %s ps' y 'logs %s') y vuelve a ejecutar."
KQ_MSG_ES[u_c_probe]="La sonda %s responde por loopback"
KQ_MSG_ES[u_f_probe]="la sonda %s no responde en https://127.0.0.1:%s. La instalacion actual no esta sana: revisa 'docker compose -f %s logs nginx app' y arreglalo antes de actualizar."
KQ_MSG_ES[u_c_version_mismatch]="La sonda dice version %s y el .env dice %s"
KQ_MSG_ES[u_c_probe_management]="La ruta de gestion %s responde 401 sin sesion (es lo que la vuelta atras exigira)"
KQ_MSG_ES[u_f_probe_management]="la ruta de gestion %s responde %s sin sesion y se esperaba 401. La instalacion actual no atiende a la API de gestion: revisa 'docker compose -f %s logs app' y arreglalo antes de actualizar, porque la vuelta atras no podria verificarse."
KQ_MSG_ES[u_f_version_mismatch]="se usara la del .env (%s) como version de origen. Si la sonda tiene razon, corrige IMAGE_TAG en %s antes de seguir: es lo que decide que migraciones se aplican."
KQ_MSG_ES[u_c_backup_key]="Clave de cifrado de las copias presente en el .env"
KQ_MSG_ES[u_f_backup_key]="BACKUP_ENCRYPTION_KEY esta vacia en %s. Sin ella no hay copia previa y sin copia no hay actualizacion (RF-PD-10). Recuperala del gestor de secretos del hotel; si se perdio, sigue docs/runbooks/rotacion-secretos.md ANTES de actualizar."
KQ_MSG_ES[u_c_backup_path]="Destino de copias %s existe y admite escritura"
KQ_MSG_ES[u_f_backup_path]="el destino de copias %s no existe o no admite escritura. Si es un recurso de red, montalo; si no, crealo con 'sudo install -d -o 1000 -g 1000 -m 0750 %s'. Sin destino no hay copia previa."
KQ_MSG_ES[u_c_audit_chain]="Cadena de auditoria integra ANTES de actualizar"
KQ_MSG_ES[u_f_audit_chain]="compliance:verify-audit-chain ha detectado una ROTURA en la cadena de auditoria antes de tocar nada. Es un incidente de seguridad, no un fallo de la actualizacion: sigue docs/runbooks/rotura-cadena-auditoria.md (preserva la evidencia) y NO actualices hasta resolverlo. Si esperaras, nadie podria distinguir si la rotura la causo la actualizacion."
KQ_MSG_ES[u_c_env_new_keys]="El .env.example de esta version trae %d claves que tu .env no tiene: %s"
KQ_MSG_ES[u_f_env_new_keys]="no es un fallo: cada una usa su valor de serie. Su significado esta en docs/cliente/configuracion.md; anadelas a %s despues de actualizar si quieres otro valor."
KQ_MSG_ES[u_c_root]="Ejecutado como root"
KQ_MSG_ES[u_f_root]="ejecuta 'sudo ./update.sh'. Hace falta root para copiar el certificado conservando su propietario (uid 101), escribir el .env en 0600, hablar con Docker y dejar el informe legible por la aplicacion."
KQ_MSG_ES[u_c_lock]="Ninguna otra actualizacion en curso"
KQ_MSG_ES[u_f_lock]="ya hay una actualizacion en curso o interrumpida (candado %s, de hace %s). Si no hay ningun update.sh ejecutandose, revisa el ultimo informe en %s, retira el candado con 'sudo rmdir %s' y vuelve a ejecutar."
KQ_MSG_ES[u_c_report_dir]="Informes en %s"
KQ_MSG_ES[u_c_changelog]="Que cambia entre la %s y la %s: seccion correspondiente de docs/CHANGELOG.md del paquete"

KQ_MSG_ES[u_prepare_env]="Copiando el .env de la instalacion actual al paquete nuevo (%s)."
KQ_MSG_ES[u_prepare_certs]="Copiando el certificado de %s a %s, con su propietario."
KQ_MSG_ES[u_f_prepare_certs]="no se ha podido copiar el certificado de %s a %s. Copialo a mano con 'sudo cp -a %s %s' y vuelve a ejecutar."

KQ_MSG_ES[u_maintenance_on]="Panel, portal y API de gestion en mantenimiento (responden 503 con Retry-After). Los quioscos encolan en local y sincronizaran al terminar: la hora real de cada fichaje se conserva."
KQ_MSG_ES[u_f_maintenance_on]="no se ha podido poner la aplicacion en mantenimiento ('php artisan down' ha fallado). Revisa 'docker compose -f %s logs app'. No se ha tocado nada mas."
KQ_MSG_ES[u_stop_workers]="Parando horizon y scheduler de la version %s para que nada escriba durante la migracion."
KQ_MSG_ES[u_f_stop_workers]="no se han podido parar horizon y scheduler. Revisa 'docker compose -f %s ps'."
KQ_MSG_ES[u_maintenance_off]="Mantenimiento retirado: la version %s vuelve a atender."

KQ_MSG_ES[u_backup_start]="Copia logica cifrada y verificada, con la version %s (php artisan backup:run)..."
KQ_MSG_ES[u_backup_done]="Copia previa verificada: %s"
KQ_MSG_ES[u_f_backup]="LA COPIA PREVIA HA FALLADO (backup.sh salio con %d: %s). RF-PD-10: sin copia verificada la actualizacion no continua, y no hay bandera para saltarselo. La instalacion no se ha tocado y sigue en la %s, con el mantenimiento retirado. Si coincide con la copia nocturna (backup.sh toma su propio candado), espera a que termine. Si no, resuelve la copia (docs/runbooks/restaurar-backup.md §2) y vuelve a ejecutar."
KQ_MSG_ES[u_f_backup_pointer]="backup.sh ha terminado bien pero no deja rastro de que fichero escribio (%s no apunta a ninguna copia). No se continua sin saber que copia restauraria la vuelta atras."

KQ_MSG_ES[u_infra_up]="Relanzando postgres y redis con las imagenes de la version %s (los datos estan en volumenes y no se tocan)."
KQ_MSG_ES[u_f_infra_up]="postgres o redis no han arrancado con las imagenes de la %s. Se deshace."
KQ_MSG_ES[u_migrations_list]="Migraciones que trae la version %s: %d; pendientes de aplicar en esta instalacion: %d."
KQ_MSG_ES[u_f_migrations_list]="no se ha podido leer la lista de migraciones de la imagen de la version %s."
KQ_MSG_ES[u_migrating_version]="Version %s: %d migraciones..."
KQ_MSG_ES[u_migrating_version_none]="Version %s: sin migraciones nuevas (punto de control)."
KQ_MSG_ES[u_checkpoint]="PUNTO DE CONTROL %s alcanzado: %d migraciones aplicadas en %d s (lote %s de la tabla migrations)."
KQ_MSG_ES[u_f_migrating_version]="la migracion de la version %s ha fallado. Ultimo punto de control alcanzado: %s. Se deshace a la copia previa."
KQ_MSG_ES[u_no_pending]="Ninguna migracion pendiente tras la cadena: el esquema esta en la version %s."
KQ_MSG_ES[u_f_pending_left]="tras aplicar la cadena quedan migraciones pendientes: %s. La matriz de versiones (versions.txt) no las atribuye a ninguna version y el actualizador no adivina. Se deshace. Avisa al fabricante con este informe."

KQ_MSG_ES[u_app_up]="Arrancando la aplicacion %s SIN borde: nginx, horizon, scheduler y reverb esperan a que la verificacion pase."
KQ_MSG_ES[u_f_app_up]="la aplicacion de la version %s no ha llegado a estar sana. Se deshace."
KQ_MSG_ES[u_verify_probe_ok]="%s responde %s desde dentro de la aplicacion"
KQ_MSG_ES[u_f_verify_probe]="%s responde %s (se esperaba %s) desde dentro de la aplicacion. Se deshace."
KQ_MSG_ES[u_verify_version_ok]="La aplicacion dice ser la version %s"
KQ_MSG_ES[u_f_verify_version]="la aplicacion dice ser la version '%s' y se esperaba la %s. Se deshace."
KQ_MSG_ES[u_verify_chain_ok]="Cadena de auditoria integra DESPUES de migrar"
KQ_MSG_ES[u_f_verify_chain]="compliance:verify-audit-chain falla despues de migrar y estaba integra antes: la actualizacion la ha roto. Se deshace y hay que avisar al fabricante con este informe."
KQ_MSG_ES[u_verify_constraints_ok]="Las restricciones de RN-01 y RN-02 siguen presentes y validas"
KQ_MSG_ES[u_f_verify_constraints]="falta o no es valida la restriccion %s de shift_entries (RN-01/RN-02). Un esquema sin ella acepta dos turnos abiertos o solapados: se deshace."
KQ_MSG_ES[u_verify_license]="Consulta de licencia correcta (una licencia caducada NO impide actualizar: regla dura 15)"
KQ_MSG_ES[u_verify_license_warn]="license:show ha fallado. No bloquea: el registro horario y la actualizacion no dependen de la licencia. Revisalo despues con 'docker compose -f %s exec app php artisan license:show'."
KQ_MSG_ES[u_edge_up]="Verificacion superada. Arrancando nginx, horizon, scheduler y reverb de la version %s."
KQ_MSG_ES[u_f_edge_up]="el borde o los procesos de fondo de la version %s no han arrancado sanos. Se deshace."
KQ_MSG_ES[u_edge_probe_ok]="%s responde por loopback a traves del borde de la version %s"
KQ_MSG_ES[u_f_edge_probe]="la aplicacion esta sana pero %s no responde a traves del borde (https://127.0.0.1:%s). Se deshace: revisa despues 'docker compose -f %s logs nginx'."
KQ_MSG_ES[u_reconcile]="attendance:reconcile sobre las jornadas de la ventana de mantenimiento (daily_totals es una proyeccion reconstruible)."

KQ_MSG_ES[u_rollback_reason]="MOTIVO: %s"
KQ_MSG_ES[u_rollback_evidence]="Estado fallido preservado en el informe (contenedores y ultimas lineas de log) para el diagnostico."
KQ_MSG_ES[u_rollback_stop]="Parando la version %s."
KQ_MSG_ES[u_rollback_restore]="Restaurando la copia previa %s con restore.sh (la base fallida se conserva como <base>_pre_restore_<marca> durante 7 dias)."
KQ_MSG_ES[u_rollback_restore_ok]="Copia previa restaurada y conteos comprobados."
KQ_MSG_ES[u_f_rollback_restore]="restore.sh ha salido con %d (%s) y NO se ha restaurado la copia."
KQ_MSG_ES[u_rollback_relaunch]="Relanzando la version %s desde %s."
KQ_MSG_ES[u_f_rollback_relaunch]="la version %s no ha arrancado sana tras restaurar."
KQ_MSG_ES[u_rollback_verify_ok]="La version %s responde: %s"
KQ_MSG_ES[u_rollback_done]="VUELTA ATRAS COMPLETADA. La instalacion esta operativa en la version %s con los datos de la copia previa. Los fichajes hechos durante la ventana siguen en las colas de los quioscos y entraran ahora. Envia el informe %s al fabricante para saber por que fallo antes de reintentar."
KQ_MSG_ES[u_rollback_incomplete]="VUELTA ATRAS INCOMPLETA. NO SE TOCA NADA MAS: hace falta una persona delante.

Que hay que hacer, en este orden:
  1. Parar lo que escribe:       docker compose --env-file %s -f %s stop app horizon scheduler reverb nginx
  2. Restaurar la copia previa:  docker compose --env-file %s -f %s run --rm --no-deps app \\
                                   bash /opt/kronoqr/scripts/restore.sh --file %s --yes
  3. Relanzar la version %s:   docker compose --env-file %s -f %s up -d --remove-orphans
  4. Comprobar:                  curl -k https://127.0.0.1:%s/api/v1/health  (debe decir %s)
Procedimiento completo y tiempos: docs/runbooks/restaurar-backup.md §6 y docs/runbooks/actualizacion-cliente.md §5.
Los quioscos siguen fichando mientras tanto: encolan en local."
KQ_MSG_ES[u_f_unexpected]="fallo inesperado en la linea %s del actualizador. Es un defecto del producto: guarda el informe entero y envialo al fabricante."
KQ_MSG_ES[u_interrupted]="interrumpido por el operador (senal %s)."

KQ_MSG_ES[u_done_title]="KronoQR actualizado y verificado: %s → %s"
KQ_MSG_ES[u_done_report]="Informe guardado en %s: es lo que hay que adjuntar si se abre un caso con el fabricante. El detalle tecnico esta en %s, solo de root, y PUEDE CONTENER DATOS PERSONALES: no lo envies sin revisarlo."
KQ_MSG_ES[u_done_old_dir]="El directorio de la version anterior, %s, queda intacto: conservalo hasta la siguiente actualizacion por si hiciera falta volver a mano (docs/runbooks/actualizacion-cliente.md §5). Su .env lleva los mismos secretos que el nuevo."
KQ_MSG_ES[u_done_queue]="Los quioscos estan sincronizando lo que encolaron durante la ventana de mantenimiento. Cada fichaje conserva su hora real (occurred_at); la hora de recepcion sera posterior, y eso es correcto. Si la ventana supero el umbral de retraso, la bandeja mostrara incidencias de sincronizacion: no son un fallo."
KQ_MSG_ES[u_done_backup]="Copia previa a la actualizacion: %s (verificada). Se conserva con la retencion normal."

KQ_MSG_ES[u_report_title]="INFORME DE ACTUALIZACION DE KRONOQR"
KQ_MSG_ES[u_report_started]="Inicio (UTC): %s"
KQ_MSG_ES[u_report_finished]="Fin (UTC): %s · duracion %d s"
KQ_MSG_ES[u_report_versions]="Version de origen: %s · version de destino: %s"
KQ_MSG_ES[u_report_dirs]="Instalacion actual: %s · paquete nuevo: %s"
KQ_MSG_ES[u_report_chain]="Cadena de versiones: %s"
KQ_MSG_ES[u_report_backup]="Copia previa: %s (%s)"
KQ_MSG_ES[u_report_maintenance]="Ventana de mantenimiento: %d s"
KQ_MSG_ES[u_report_checkpoint]="Punto de control %s: %s (%d migraciones, %d s, lote %s)"
KQ_MSG_ES[u_report_check]="Comprobacion · %s: %s"
KQ_MSG_ES[u_report_rollback]="Vuelta atras: en el paso %s, motivo: %s · resultado: %s"
KQ_MSG_ES[u_report_final]="Estado final: version %s · %s"
KQ_MSG_ES[u_report_exit]="Salida %d (%s)"
KQ_MSG_ES[u_report_no_pii]="Este informe no contiene secretos ni datos personales y se puede adjuntar al paquete de diagnostico."
KQ_MSG_ES[u_report_none]="(ninguna)"
KQ_MSG_ES[u_report_ok]="correcto"
KQ_MSG_ES[u_report_failed]="FALLIDO"
KQ_MSG_ES[u_report_not_run]="no ejecutado"
KQ_MSG_ES[u_report_operational]="operativa"
KQ_MSG_ES[u_report_needs_person]="REQUIERE INTERVENCION"

KQ_MSG_ES[u_supported_none]="(ninguna: no hay version publicada anterior desde la que se pueda actualizar a la %s)"
KQ_MSG_ES[u_chain_none]="(ninguna: %s no es anterior a %s)"

# --- tarea 5.7, tras las revisiones de correccion y seguridad ---
KQ_MSG_ES[u_checkpoint_none]="PUNTO DE CONTROL %s alcanzado: sin migraciones nuevas en esta instalacion."
KQ_MSG_ES[u_detail_title]="DETALLE TECNICO DE LA ACTUALIZACION (salida cruda; puede contener datos personales; solo root). Informe: %s"
KQ_MSG_ES[u_report_detail]="Este informe no contiene secretos ni datos personales y se puede adjuntar al paquete de diagnostico. El detalle tecnico (salida de migraciones, copia, restauracion y logs) esta en %s, es solo de root y PUEDE CONTENER DATOS PERSONALES: revisalo antes de enviarlo a nadie."
KQ_MSG_ES[u_rollback_incomplete_maintenance]="VUELTA ATRAS INCOMPLETA, pero SOLO en una cosa: la instalacion sigue en la version %s CON SUS DATOS INTACTOS y no hay ninguna copia que restaurar. Lo unico que ha quedado a medias es el modo mantenimiento. NO RESTAURES NINGUNA COPIA: borrarias los fichajes del dia.

Que hacer:
  1. Retirar el mantenimiento:   docker compose --env-file %s -f %s exec -T app php artisan up
  2. Arrancar los procesos:      docker compose --env-file %s -f %s up -d horizon scheduler
  3. Comprobar:                  curl -k https://127.0.0.1/api/v1/auth/me   (debe responder 401, no 503)"
KQ_MSG_ES[u_f_unsupported_major_last]="la %s es la ultima de la serie %s.x y este paquete es de la %s.x. El salto de version mayor no es automatico: tiene una ventana de migracion anunciada por el fabricante, con sus instrucciones en docs/runbooks/actualizacion-cliente.md §7. No se ha tocado nada."
KQ_MSG_ES[u_f_backup_stale]="la copia que backup.sh declara como ultima (%s) es ANTERIOR al arranque de esta actualizacion: no es la copia previa de esta ejecucion y una vuelta atras con ella perderia los fichajes del dia. Suele pasar si BACKUP_DAILY_MODE no es 'dump' o si otra copia estaba en curso (la nocturna): espera a que termine y vuelve a ejecutar. La instalacion no se ha tocado y el mantenimiento se ha retirado."
KQ_MSG_ES[u_f_prepare_env]="no se ha podido escribir %s (disco lleno, permisos o /tmp sin espacio). La instalacion no se ha tocado; retira ese fichero si ha quedado a medias y vuelve a ejecutar."
KQ_MSG_ES[u_maintenance_new]="Poniendo la version %s en mantenimiento ANTES de publicar el borde: hasta que la verificacion por loopback pase, ningun fichaje sale de las colas de los quioscos."
KQ_MSG_ES[u_f_maintenance_off]="no se ha podido retirar el mantenimiento de la version nueva ('php artisan up' ha fallado). Se deshace. Revisa despues 'docker compose -f %s logs app'."
KQ_MSG_ES[u_verify_lifted_ok]="Mantenimiento retirado de verdad: %s responde 401 (sin sesion), no 503"
KQ_MSG_ES[u_f_verify_lifted]="%s responde %s tras retirar el mantenimiento y se esperaba 401: la version nueva no atiende. Se deshace."
KQ_MSG_ES[u_f_rollback_management]="tras relanzar la version %s, %s responde %s y se esperaba 401: la version anterior no atiende a la API de gestion."
KQ_MSG_ES[u_workers_up]="Arrancando horizon, scheduler y reverb de la version %s."
KQ_MSG_ES[u_f_workers_up]="los procesos de fondo de la version %s no han arrancado. Se deshace."
KQ_MSG_ES[u_verify_privileges_ok]="El rol %s puede escribir fichajes y NO puede alterar audit_log (regla dura 6)"
KQ_MSG_ES[u_f_verify_privileges]="el rol de la aplicacion (%s) no tiene los privilegios que le corresponden: o no puede escribir en shift_entries, o puede alterar audit_log. Con ese esquema las sondas dirian 'operativo' y ningun fichaje se guardaria, o la auditoria dejaria de ser inalterable. Se deshace."
KQ_MSG_ES[u_rollback_evidence_at]="Estado fallido preservado en %s (contenedores y ultimas lineas de log; solo root) para el diagnostico."

#------------------------------------------------------------------------------
# English
#------------------------------------------------------------------------------
KQ_MSG_EN[u_usage]="KronoQR — updater for an existing installation.

Usage:
  update.sh [options]             update the installation to this package's version
  update.sh --check-only          step 1 only (preconditions). Touches nothing
  update.sh --supported-sources   which versions can be updated to this one
  update.sh --chain VERSION       which intermediate versions would apply from VERSION

Options:
  --current PATH      Directory of the current installation (where its
                      docker-compose.yml and .env live). By default it is
                      located by asking Docker for the running project.
  --compose-file PATH Compose file of THIS package. Defaults to the one next
                      to the updater.
  --lang es|en        Message language. Defaults to the system's.
  --help              This help.
  --version           Product version this package would install.

Run it FROM THE NEW PACKAGE DIRECTORY, unpacked next to the current version's
one, never on top of it. The script copies the .env and the certificate of the
current installation, and the previous directory stays intact to go back.

Steps: preconditions · maintenance (kiosks keep clocking) · verified backup,
blocking · migrations version by version with a checkpoint · start and verify
without exposing the new version · automatic rollback if anything fails ·
report on the server.

Exit codes: 0 updated and verified · 1 wrong usage · 2 precondition not met or
backup failed (the installation was NOT touched) · 3 already at the target
version, or no installation to update (nothing touched) · 4 failure with
rollback completed: the previous version is running · 5 failure with
INCOMPLETE rollback: the message says what to do by hand. This script never
exits 6: every failed verification rolls back. Full table in
docs/cliente/operacion.md."

KQ_MSG_EN[u_phase_1]="Step 1 of 7 — preconditions. The installation is not touched yet."
KQ_MSG_EN[u_phase_2]="Step 2 of 7 — maintenance mode. The panel answers 'under maintenance'; kiosks keep clocking and queue locally."
KQ_MSG_EN[u_phase_3]="Step 3 of 7 — full, verified backup. BLOCKING: without a backup nothing continues."
KQ_MSG_EN[u_phase_4]="Step 4 of 7 — migrations, version by version, with a checkpoint between each."
KQ_MSG_EN[u_phase_5]="Step 5 of 7 — starting version %s and checking its health, without exposing it yet."
KQ_MSG_EN[u_phase_6]="Step 6 of 7 — AUTOMATIC ROLLBACK to the previous backup."
KQ_MSG_EN[u_phase_7]="Step 7 of 7 — report."

KQ_MSG_EN[u_bad_option]="unknown option: %s. Run \"update.sh --help\" to see the available ones."
KQ_MSG_EN[u_check_only_done]="Check only (--check-only): nothing was touched. Run again without the option to update."
KQ_MSG_EN[u_req_summary_ok]="Preconditions met: %d checks, %d warnings."
KQ_MSG_EN[u_req_summary_fail]="Preconditions NOT met: %d failures. The installation was not touched and stays at version %s."

KQ_MSG_EN[u_c_package_file]="Package file %s"
KQ_MSG_EN[u_f_package_file]="%s is missing. This package is incomplete: unpack it again in full (docker-compose.yml, .env.example, VERSION, versions.txt, lib/ and the five scripts) and run update.sh from that directory."
KQ_MSG_EN[u_c_versions_file]="Version matrix %s (%d published versions)"
KQ_MSG_EN[u_f_versions_file]="%s cannot be read or is malformed (%s). It is a package file, do not edit it: unpack the package again."
KQ_MSG_EN[u_c_target_version]="Target version %s (package VERSION file)"
KQ_MSG_EN[u_f_target_unlisted]="this package's version, %s, is not listed in %s. That is a defect of the package, not of your server: do not update with it and tell the manufacturer."
KQ_MSG_EN[u_c_docker_root]="Docker data directory %s"

KQ_MSG_EN[u_c_installation]="Current installation at %s"
KQ_MSG_EN[u_f_installation_missing]="no KronoQR installation was found on this server: Docker does not know project '%s'. On a new server what you want is install.sh. If the installation exists but its containers were removed, point to its directory with --current PATH."
KQ_MSG_EN[u_f_installation_ambiguous]="Docker has containers of project '%s' created from MORE THAN ONE directory: %s. There can only be one installation. Remove the extra containers or point to the right directory with --current PATH."
KQ_MSG_EN[u_f_installation_files]="%s does not hold the files of an installation: %s is missing. Point to the right directory with --current PATH."
KQ_MSG_EN[u_c_in_place]="The package was unpacked ON TOP of the current installation (%s)"
KQ_MSG_EN[u_f_in_place]="it works, but the previous version's docker-compose.yml is gone and a rollback would have to use the new one with the old images. Next time unpack the package into a new directory next to the current one."
KQ_MSG_EN[u_f_env_conflict]="this package already holds a .env with secrets (%s) that is NOT the current installation's (%s). To avoid mixing two installations, remove that file and run again: the updater copies the right .env."

KQ_MSG_EN[u_c_source_version]="Installed version %s (IMAGE_TAG of %s)"
KQ_MSG_EN[u_f_source_version_missing]="the installation's .env (%s) does not say which version runs: IMAGE_TAG is empty or not a version. Look at what the probe publishes (curl -k https://127.0.0.1:%s/api/v1/health), write IMAGE_TAG=<that version> in that .env and run again."
KQ_MSG_EN[u_f_source_unlisted]="the installed version, %s, is not a published KronoQR version (not in %s). Check IMAGE_TAG in %s and, if it is right, tell the manufacturer before updating."
KQ_MSG_EN[u_c_already_target]="The installation is ALREADY at version %s. Nothing to do and nothing was touched."
KQ_MSG_EN[u_f_downgrade]="the installation is at %s, LATER than this package's %s. This script does not downgrade: an older version cannot read a newer schema. Use the package of an equal or newer version."
KQ_MSG_EN[u_c_supported]="Jump %s → %s within the supported version matrix"
KQ_MSG_EN[u_f_unsupported_hop]="from %s you cannot jump straight to %s: only the current minor version and the two previous ones are supported (%s). Update FIRST to %s with its package, verify, and then to this one."
KQ_MSG_EN[u_f_unsupported_major]="%s belongs to the %s.x series and this package to %s.x. A major version jump is not automatic: it has a migration window announced by the manufacturer. Update first to the last of your series (%s) and read the jump instructions in docs/runbooks/actualizacion-cliente.md."
KQ_MSG_EN[u_f_unsupported_none]="there is no published update path from %s to %s. Contact the manufacturer quoting both versions."
KQ_MSG_EN[u_c_chain]="Intermediate versions that will be applied in order: %s"

KQ_MSG_EN[u_c_images]="Images of version %s present on this server"
KQ_MSG_EN[u_images_pull]="Pulling the missing images of version %s (the installation is not touched)..."
KQ_MSG_EN[u_f_images]="could not obtain the images of version %s from %s. Check access to the registry. On an installation without internet access, load them first with 'docker load -i imagenes-%s.tar' (docs/cliente/instalacion.md §7) and run again. The installation was not touched."
KQ_MSG_EN[u_c_space_backup]="Space at %s: %d GiB free (the database takes %d GiB; %d required)"
KQ_MSG_EN[u_f_space_backup]="the backup needs at least %d GiB free at %s and %d are left. Free space (backup.sh prune removes expired backups) or mount a larger destination and run again."
KQ_MSG_EN[u_c_space_docker]="Space at %s: %d GiB free (%d required)"
KQ_MSG_EN[u_f_space_docker]="the new images and the migration need at least %d GiB free at %s and %d are left. 'docker image prune' removes unused images; then run again."
KQ_MSG_EN[u_c_service]="Service %s running and healthy"
KQ_MSG_EN[u_f_service]="service %s is not healthy (state: %s). Updating a system that already fails merges two problems into one: fix it first ('docker compose -f %s ps' and 'logs %s') and run again."
KQ_MSG_EN[u_c_probe]="Probe %s answers over loopback"
KQ_MSG_EN[u_f_probe]="probe %s does not answer at https://127.0.0.1:%s. The current installation is not healthy: check 'docker compose -f %s logs nginx app' and fix it before updating."
KQ_MSG_EN[u_c_version_mismatch]="The probe reports version %s and the .env says %s"
KQ_MSG_EN[u_c_probe_management]="Management route %s answers 401 without a session (what the rollback will require)"
KQ_MSG_EN[u_f_probe_management]="management route %s answers %s without a session and 401 was expected. The current installation is not serving the management API: check 'docker compose -f %s logs app' and fix it before updating, because the rollback could not be verified."
KQ_MSG_EN[u_f_version_mismatch]="the .env value (%s) will be used as the source version. If the probe is right, fix IMAGE_TAG in %s before going on: it decides which migrations apply."
KQ_MSG_EN[u_c_backup_key]="Backup encryption key present in the .env"
KQ_MSG_EN[u_f_backup_key]="BACKUP_ENCRYPTION_KEY is empty in %s. Without it there is no backup and without a backup there is no update (RF-PD-10). Recover it from the hotel's secret store; if it was lost, follow docs/runbooks/rotacion-secretos.md BEFORE updating."
KQ_MSG_EN[u_c_backup_path]="Backup destination %s exists and is writable"
KQ_MSG_EN[u_f_backup_path]="the backup destination %s does not exist or is not writable. If it is a network share, mount it; otherwise create it with 'sudo install -d -o 1000 -g 1000 -m 0750 %s'. Without a destination there is no backup."
KQ_MSG_EN[u_c_audit_chain]="Audit chain intact BEFORE updating"
KQ_MSG_EN[u_f_audit_chain]="compliance:verify-audit-chain found a BREAK in the audit chain before anything was touched. It is a security incident, not an update failure: follow docs/runbooks/rotura-cadena-auditoria.md (preserve the evidence) and do NOT update until it is resolved. Had you waited, nobody could tell whether the update caused the break."
KQ_MSG_EN[u_c_env_new_keys]="This version's .env.example brings %d keys your .env lacks: %s"
KQ_MSG_EN[u_f_env_new_keys]="not a failure: each one uses its factory value. Their meaning is in docs/cliente/configuracion.md; add them to %s after updating if you want another value."
KQ_MSG_EN[u_c_root]="Running as root"
KQ_MSG_EN[u_f_root]="run 'sudo ./update.sh'. Root is needed to copy the certificate keeping its owner (uid 101), write the .env as 0600, talk to Docker and leave the report readable by the application."
KQ_MSG_EN[u_c_lock]="No other update in progress"
KQ_MSG_EN[u_f_lock]="an update is already in progress or was interrupted (lock %s, from %s ago). If no update.sh is running, read the last report in %s, remove the lock with 'sudo rmdir %s' and run again."
KQ_MSG_EN[u_c_report_dir]="Reports at %s"
KQ_MSG_EN[u_c_changelog]="What changes between %s and %s: matching section of the package's docs/CHANGELOG.md"

KQ_MSG_EN[u_prepare_env]="Copying the current installation's .env into the new package (%s)."
KQ_MSG_EN[u_prepare_certs]="Copying the certificate from %s to %s, keeping its owner."
KQ_MSG_EN[u_f_prepare_certs]="could not copy the certificate from %s to %s. Copy it by hand with 'sudo cp -a %s %s' and run again."

KQ_MSG_EN[u_maintenance_on]="Panel, portal and management API under maintenance (they answer 503 with Retry-After). Kiosks queue locally and will sync when done: the real time of every clocking is preserved."
KQ_MSG_EN[u_f_maintenance_on]="could not put the application under maintenance ('php artisan down' failed). Check 'docker compose -f %s logs app'. Nothing else was touched."
KQ_MSG_EN[u_stop_workers]="Stopping horizon and scheduler of version %s so nothing writes during the migration."
KQ_MSG_EN[u_f_stop_workers]="could not stop horizon and scheduler. Check 'docker compose -f %s ps'."
KQ_MSG_EN[u_maintenance_off]="Maintenance lifted: version %s is serving again."

KQ_MSG_EN[u_backup_start]="Encrypted, verified logical backup with version %s (php artisan backup:run)..."
KQ_MSG_EN[u_backup_done]="Pre-update backup verified: %s"
KQ_MSG_EN[u_f_backup]="THE PRE-UPDATE BACKUP FAILED (backup.sh exited %d: %s). RF-PD-10: without a verified backup the update does not continue, and there is no flag to skip it. The installation was not touched and stays at %s, with maintenance lifted. If it overlaps the nightly backup (backup.sh takes its own lock), wait for it to finish. Otherwise fix the backup (docs/runbooks/restaurar-backup.md §2) and run again."
KQ_MSG_EN[u_f_backup_pointer]="backup.sh finished fine but left no trace of which file it wrote (%s points to no backup). Not continuing without knowing which backup a rollback would restore."

KQ_MSG_EN[u_infra_up]="Relaunching postgres and redis with the images of version %s (data lives in volumes and is not touched)."
KQ_MSG_EN[u_f_infra_up]="postgres or redis did not come up with the images of %s. Rolling back."
KQ_MSG_EN[u_migrations_list]="Migrations shipped by version %s: %d; pending on this installation: %d."
KQ_MSG_EN[u_f_migrations_list]="could not read the migration list from the image of version %s."
KQ_MSG_EN[u_migrating_version]="Version %s: %d migrations..."
KQ_MSG_EN[u_migrating_version_none]="Version %s: no new migrations (checkpoint)."
KQ_MSG_EN[u_checkpoint]="CHECKPOINT %s reached: %d migrations applied in %d s (batch %s of the migrations table)."
KQ_MSG_EN[u_f_migrating_version]="the migration of version %s failed. Last checkpoint reached: %s. Rolling back to the previous backup."
KQ_MSG_EN[u_no_pending]="No pending migration after the chain: the schema is at version %s."
KQ_MSG_EN[u_f_pending_left]="after applying the chain there are still pending migrations: %s. The version matrix (versions.txt) assigns them to no version and the updater does not guess. Rolling back. Tell the manufacturer, attaching this report."

KQ_MSG_EN[u_app_up]="Starting application %s WITHOUT the edge: nginx, horizon, scheduler and reverb wait until verification passes."
KQ_MSG_EN[u_f_app_up]="the application of version %s never became healthy. Rolling back."
KQ_MSG_EN[u_verify_probe_ok]="%s answers %s from inside the application"
KQ_MSG_EN[u_f_verify_probe]="%s answers %s (expected %s) from inside the application. Rolling back."
KQ_MSG_EN[u_verify_version_ok]="The application reports version %s"
KQ_MSG_EN[u_f_verify_version]="the application reports version '%s' and %s was expected. Rolling back."
KQ_MSG_EN[u_verify_chain_ok]="Audit chain intact AFTER migrating"
KQ_MSG_EN[u_f_verify_chain]="compliance:verify-audit-chain fails after migrating and it was intact before: the update broke it. Rolling back; tell the manufacturer, attaching this report."
KQ_MSG_EN[u_verify_constraints_ok]="The RN-01 and RN-02 constraints are still present and valid"
KQ_MSG_EN[u_f_verify_constraints]="constraint %s of shift_entries (RN-01/RN-02) is missing or invalid. A schema without it accepts two open or overlapping shifts: rolling back."
KQ_MSG_EN[u_verify_license]="License query fine (an expired license does NOT prevent updating: hard rule 15)"
KQ_MSG_EN[u_verify_license_warn]="license:show failed. Not blocking: the time record and the update do not depend on the license. Check it later with 'docker compose -f %s exec app php artisan license:show'."
KQ_MSG_EN[u_edge_up]="Verification passed. Starting nginx, horizon, scheduler and reverb of version %s."
KQ_MSG_EN[u_f_edge_up]="the edge or the background processes of version %s did not come up healthy. Rolling back."
KQ_MSG_EN[u_edge_probe_ok]="%s answers over loopback through the edge of version %s"
KQ_MSG_EN[u_f_edge_probe]="the application is healthy but %s does not answer through the edge (https://127.0.0.1:%s). Rolling back: check 'docker compose -f %s logs nginx' afterwards."
KQ_MSG_EN[u_reconcile]="attendance:reconcile over the work days of the maintenance window (daily_totals is a rebuildable projection)."

KQ_MSG_EN[u_rollback_reason]="REASON: %s"
KQ_MSG_EN[u_rollback_evidence]="Failed state preserved in the report (containers and last log lines) for diagnosis."
KQ_MSG_EN[u_rollback_stop]="Stopping version %s."
KQ_MSG_EN[u_rollback_restore]="Restoring the pre-update backup %s with restore.sh (the failed database is kept as <db>_pre_restore_<stamp> for 7 days)."
KQ_MSG_EN[u_rollback_restore_ok]="Pre-update backup restored and row counts checked."
KQ_MSG_EN[u_f_rollback_restore]="restore.sh exited %d (%s) and the backup was NOT restored."
KQ_MSG_EN[u_rollback_relaunch]="Relaunching version %s from %s."
KQ_MSG_EN[u_f_rollback_relaunch]="version %s did not come up healthy after restoring."
KQ_MSG_EN[u_rollback_verify_ok]="Version %s answers: %s"
KQ_MSG_EN[u_rollback_done]="ROLLBACK COMPLETED. The installation is operational at version %s with the data of the pre-update backup. Clockings made during the window are still in the kiosk queues and will come in now. Send the report %s to the manufacturer to learn why it failed before retrying."
KQ_MSG_EN[u_rollback_incomplete]="ROLLBACK INCOMPLETE. NOTHING ELSE IS TOUCHED: a person is needed.

What to do, in this order:
  1. Stop what writes:            docker compose --env-file %s -f %s stop app horizon scheduler reverb nginx
  2. Restore the pre-update backup: docker compose --env-file %s -f %s run --rm --no-deps app \\
                                   bash /opt/kronoqr/scripts/restore.sh --file %s --yes
  3. Relaunch version %s:       docker compose --env-file %s -f %s up -d --remove-orphans
  4. Check:                       curl -k https://127.0.0.1:%s/api/v1/health  (must report %s)
Full procedure and timings: docs/runbooks/restaurar-backup.md §6 and docs/runbooks/actualizacion-cliente.md §5.
Kiosks keep clocking meanwhile: they queue locally."
KQ_MSG_EN[u_f_unexpected]="unexpected failure on line %s of the updater. This is a product defect: keep the whole report and send it to the manufacturer."
KQ_MSG_EN[u_interrupted]="interrupted by the operator (signal %s)."

KQ_MSG_EN[u_done_title]="KronoQR updated and verified: %s → %s"
KQ_MSG_EN[u_done_report]="Report saved at %s: it is what to attach if a case is opened with the manufacturer. The technical detail is at %s, root-only, and MAY CONTAIN PERSONAL DATA: do not send it without reviewing it."
KQ_MSG_EN[u_done_old_dir]="The previous version's directory, %s, stays intact: keep it until the next update in case a manual rollback is needed (docs/runbooks/actualizacion-cliente.md §5). Its .env holds the same secrets as the new one."
KQ_MSG_EN[u_done_queue]="Kiosks are syncing what they queued during the maintenance window. Every clocking keeps its real time (occurred_at); the reception time will be later, and that is correct. If the window exceeded the delay threshold, the inbox will show sync incidents: they are not a failure."
KQ_MSG_EN[u_done_backup]="Pre-update backup: %s (verified). Kept under the normal retention."

KQ_MSG_EN[u_report_title]="KRONOQR UPDATE REPORT"
KQ_MSG_EN[u_report_started]="Start (UTC): %s"
KQ_MSG_EN[u_report_finished]="End (UTC): %s · duration %d s"
KQ_MSG_EN[u_report_versions]="Source version: %s · target version: %s"
KQ_MSG_EN[u_report_dirs]="Current installation: %s · new package: %s"
KQ_MSG_EN[u_report_chain]="Version chain: %s"
KQ_MSG_EN[u_report_backup]="Pre-update backup: %s (%s)"
KQ_MSG_EN[u_report_maintenance]="Maintenance window: %d s"
KQ_MSG_EN[u_report_checkpoint]="Checkpoint %s: %s (%d migrations, %d s, batch %s)"
KQ_MSG_EN[u_report_check]="Check · %s: %s"
KQ_MSG_EN[u_report_rollback]="Rollback: at step %s, reason: %s · result: %s"
KQ_MSG_EN[u_report_final]="Final state: version %s · %s"
KQ_MSG_EN[u_report_exit]="Exit %d (%s)"
KQ_MSG_EN[u_report_no_pii]="This report holds no secrets and no personal data and can be attached to the diagnostic package."
KQ_MSG_EN[u_report_none]="(none)"
KQ_MSG_EN[u_report_ok]="ok"
KQ_MSG_EN[u_report_failed]="FAILED"
KQ_MSG_EN[u_report_not_run]="not run"
KQ_MSG_EN[u_report_operational]="operational"
KQ_MSG_EN[u_report_needs_person]="NEEDS INTERVENTION"

KQ_MSG_EN[u_supported_none]="(none: there is no earlier published version from which %s can be reached)"
KQ_MSG_EN[u_chain_none]="(none: %s is not earlier than %s)"

# --- task 5.7, after the correctness and security reviews ---
KQ_MSG_EN[u_checkpoint_none]="CHECKPOINT %s reached: no new migrations on this installation."
KQ_MSG_EN[u_detail_title]="TECHNICAL DETAIL OF THE UPDATE (raw output; may contain personal data; root only). Report: %s"
KQ_MSG_EN[u_report_detail]="This report holds no secrets and no personal data and can be attached to the diagnostic package. The technical detail (migration, backup, restore and log output) is at %s, is root-only and MAY CONTAIN PERSONAL DATA: review it before sending it to anyone."
KQ_MSG_EN[u_rollback_incomplete_maintenance]="ROLLBACK INCOMPLETE, but ONLY in one thing: the installation is still at version %s WITH ITS DATA INTACT and there is no backup to restore. The only thing left half-done is maintenance mode. DO NOT RESTORE ANY BACKUP: you would erase the day's clockings.

What to do:
  1. Lift maintenance:        docker compose --env-file %s -f %s exec -T app php artisan up
  2. Start the workers:       docker compose --env-file %s -f %s up -d horizon scheduler
  3. Check:                   curl -k https://127.0.0.1/api/v1/auth/me   (must answer 401, not 503)"
KQ_MSG_EN[u_f_unsupported_major_last]="%s is the last of the %s.x series and this package belongs to %s.x. A major version jump is not automatic: it has a migration window announced by the manufacturer, with its instructions in docs/runbooks/actualizacion-cliente.md §7. Nothing was touched."
KQ_MSG_EN[u_f_backup_stale]="the backup that backup.sh declares as latest (%s) is OLDER than the start of this update: it is not this run's backup and rolling back to it would lose the day's clockings. It usually means BACKUP_DAILY_MODE is not 'dump' or another backup was running (the nightly one): wait for it to finish and run again. The installation was not touched and maintenance was lifted."
KQ_MSG_EN[u_f_prepare_env]="could not write %s (disk full, permissions or /tmp without space). The installation was not touched; remove that file if it was left half-written and run again."
KQ_MSG_EN[u_maintenance_new]="Putting version %s under maintenance BEFORE publishing the edge: until the loopback verification passes, no clocking leaves the kiosk queues."
KQ_MSG_EN[u_f_maintenance_off]="could not lift maintenance on the new version ('php artisan up' failed). Rolling back. Check 'docker compose -f %s logs app' afterwards."
KQ_MSG_EN[u_verify_lifted_ok]="Maintenance really lifted: %s answers 401 (no session), not 503"
KQ_MSG_EN[u_f_verify_lifted]="%s answers %s after lifting maintenance and 401 was expected: the new version is not serving. Rolling back."
KQ_MSG_EN[u_f_rollback_management]="after relaunching version %s, %s answers %s and 401 was expected: the previous version is not serving the management API."
KQ_MSG_EN[u_workers_up]="Starting horizon, scheduler and reverb of version %s."
KQ_MSG_EN[u_f_workers_up]="the background processes of version %s did not start. Rolling back."
KQ_MSG_EN[u_verify_privileges_ok]="Role %s can write clockings and CANNOT alter audit_log (hard rule 6)"
KQ_MSG_EN[u_f_verify_privileges]="the application role (%s) does not hold the privileges it should: either it cannot write shift_entries, or it can alter audit_log. With that schema the probes would say 'operational' and no clocking would be saved, or the audit trail would stop being tamper-proof. Rolling back."
KQ_MSG_EN[u_rollback_evidence_at]="Failed state preserved at %s (containers and last log lines; root only) for diagnosis."
