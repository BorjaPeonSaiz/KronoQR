#!/usr/bin/env bash
#
# KronoQR — mensajes de install.sh en espanol e ingles.
#
# NO SE EJECUTA SOLO: lo carga install.sh.
#
# POR QUE UN CATALOGO Y NO CADENAS SUELTAS. El doc 02 §3.5 exige que el mensaje
# diga QUE HACER, y la Definicion de Terminado exige que los textos que ve una
# persona esten en los dos idiomas. Con las cadenas repartidas por el script, la
# traduccion se olvida en el mensaje raro —que es justamente el que alguien
# leera a las tres de la mañana— y nadie se entera hasta casa del cliente. Aqui
# las dos tablas estan una al lado de la otra, y `kq_msg_check_catalog`
# comprueba que ninguna clave falta en la otra.
#
# Como se elige el idioma, en este orden:
#   1. --lang es|en
#   2. KRONOQR_LANG=es|en
#   3. LC_ALL / LC_MESSAGES / LANG del sistema (prefijo antes del "_")
#   4. es
#
# Los identificadores de clave van en INGLES, como el resto del codigo
# (CLAUDE.md, doc 02 §3.5 «Transversal»). Lo que va traducido es el texto.
#
# NINGUN MENSAJE LLEVA UN SECRETO. Los formatos reciben rutas, numeros, nombres
# de servicio y ordenes; nunca el valor de una clave.

set -euo pipefail
IFS=$'\n\t'

KQ_LANG="es"

declare -A KQ_MSG_ES=()
declare -A KQ_MSG_EN=()

#------------------------------------------------------------------------------
# Espanol
#------------------------------------------------------------------------------
KQ_MSG_ES[usage]="KronoQR — instalador para el servidor del cliente.

Uso:
  install.sh [opciones]

Opciones:
  --check-only        Ejecuta SOLO la fase 1 (comprobacion de requisitos) y
                      sale. No escribe nada. Util antes de reservar la ventana.
  --env-file RUTA     Fichero .env a crear. Por defecto, el que acompaña al
                      fichero de compose.
  --compose-file RUTA Fichero de compose de produccion. Por defecto, el que
                      acompaña al instalador.
  --lang es|en        Idioma de los mensajes. Por defecto, el del sistema.
  --help              Esta ayuda.
  --version           Version del producto que instalaria.

Codigos de salida: 0 correcto · 1 uso incorrecto · 2 requisitos no cumplidos
(nada escrito) · 3 hay una instalacion previa (nada escrito) · 4 fallo con
vuelta atras completada · 5 fallo con vuelta atras INCOMPLETA · 6 verificacion
posterior fallida. Tabla completa en docs/cliente/operacion.md."

KQ_MSG_ES[phase_1]="Fase 1 de 5 — comprobando requisitos. Todavia no se escribe nada."
KQ_MSG_ES[phase_2]="Fase 2 de 5 — buscando una instalacion previa."
KQ_MSG_ES[phase_3]="Fase 3 de 5 — generando secretos en ESTE servidor y escribiendo %s."
KQ_MSG_ES[phase_4]="Fase 4 de 5 — arrancando servicios y aplicando el esquema."
KQ_MSG_ES[phase_5]="Fase 5 de 5 — verificando la instalacion."

KQ_MSG_ES[check_ok]="  [ok]    %s"
KQ_MSG_ES[check_warn]="  [aviso] %s"
KQ_MSG_ES[check_fail]="  [FALLA] %s"
KQ_MSG_ES[fix]="          Que hacer: %s"

KQ_MSG_ES[req_summary_ok]="Requisitos cumplidos: %d comprobaciones, %d avisos."
KQ_MSG_ES[req_summary_fail]="Requisitos NO cumplidos: %d fallos. No se ha escrito nada; el servidor esta como estaba."
KQ_MSG_ES[check_only_done]="Solo comprobacion (--check-only): no se ha tocado nada. Vuelve a ejecutar sin la opcion para instalar."

KQ_MSG_ES[c_docker_access]="Permiso para hablar con Docker"
KQ_MSG_ES[f_docker_access]="ejecuta el instalador como root (sudo ./install.sh) o anade tu usuario al grupo docker con \"sudo usermod -aG docker \$USER\" y vuelve a entrar en la sesion."
KQ_MSG_ES[c_docker]="Docker %s (se exige 24 o superior)"
KQ_MSG_ES[f_docker_missing]="instala Docker Engine 24 o superior siguiendo https://docs.docker.com/engine/install/ y vuelve a ejecutar este script."
KQ_MSG_ES[f_docker_old]="actualiza Docker Engine a la version 24 o superior. La instalada es la %s."
KQ_MSG_ES[c_compose]="Docker Compose v2 (%s)"
KQ_MSG_ES[f_compose]="instala el plugin de Compose v2: \"docker compose version\" tiene que responder. El binario antiguo \"docker-compose\" no sirve, porque este producto usa la sintaxis v2."
KQ_MSG_ES[c_cpu]="CPU: %d nucleos (minimo publicado: %d)"
KQ_MSG_ES[f_cpu]="amplia la maquina a %d nucleos. Con menos, el cambio de turno de la manana encola fichajes."
KQ_MSG_ES[c_ram]="Memoria: %d MiB (minimo publicado: %d MiB)"
KQ_MSG_ES[f_ram]="amplia la maquina a %d MiB de RAM. Si no puedes, apaga la observabilidad dejando COMPOSE_PROFILES vacio en el .env: libera unos 700 MiB, pero pierdes las alertas de copia y de disco."
KQ_MSG_ES[c_disk]="Disco libre en %s: %d GiB (minimo publicado: %d GiB)"
KQ_MSG_ES[f_disk]="libera espacio o monta un disco mayor en %s. El registro horario se conserva 4 anos por ley: el almacenamiento tiene que dar para eso."
KQ_MSG_ES[c_port]="Puerto %s libre"
KQ_MSG_ES[c_port_unknown]="Puerto %s: no se ha podido comprobar si esta libre"
KQ_MSG_ES[f_port]="el puerto %s ya lo usa otro proceso. Paralo, o cambia HTTP_PORT/HTTPS_PORT en el .env. Para ver quien lo ocupa: sudo ss -lptn \"sport = :%s\"."
KQ_MSG_ES[c_writable]="Se puede escribir en %s"
KQ_MSG_ES[f_writable]="crea el directorio y dale permiso al usuario que ejecuta el instalador: sudo install -d -o \$(id -u) -g \$(id -g) -m 0750 %s"
KQ_MSG_ES[c_openssl]="openssl disponible para generar los secretos"
KQ_MSG_ES[f_openssl]="instala el paquete openssl (apt install openssl / dnf install openssl). El instalador genera con el todas las claves de esta instalacion."
KQ_MSG_ES[c_curl]="curl disponible para verificar la instalacion"
KQ_MSG_ES[f_curl]="instala el paquete curl (apt install curl / dnf install curl). Sin el, la fase 5 no puede comprobar que el sistema responde, y una instalacion sin verificar no se declara correcta."
KQ_MSG_ES[c_appurl]="APP_URL: %s"
KQ_MSG_ES[f_appurl]="pon en APP_URL la URL https por la que los quioscos y el panel llegaran a este servidor, con el mismo nombre que el certificado. Ejemplo: APP_URL=https://fichaje.mihotel.local"
KQ_MSG_ES[c_appurl_dns]="El nombre %s resuelve desde este servidor"
KQ_MSG_ES[w_appurl_dns]="el nombre %s no resuelve desde este servidor. No impide instalar —el DNS partido es habitual—, pero comprueba desde un quiosco que resuelve alli, o el fichaje no llegara."
KQ_MSG_ES[c_tls]="Certificado TLS en %s"
KQ_MSG_ES[f_tls]="coloca el certificado del hotel como tls.crt y su clave como tls.key dentro de %s. Sin ellos el servidor web no arranca. Un autofirmado hace que los quioscos avisen cada manana y alguien acabe desactivando la comprobacion."
KQ_MSG_ES[c_template]="Plantilla de configuracion %s"
KQ_MSG_ES[f_template]="copia el fichero .env.example que viene en el paquete de entrega junto al instalador."
KQ_MSG_ES[c_compose_file]="Fichero de compose %s"
KQ_MSG_ES[f_compose_file]="ejecuta el instalador desde el directorio del paquete de entrega, o indica la ruta con --compose-file."
KQ_MSG_ES[c_version]="Version que se instala: %s"
KQ_MSG_ES[f_version]="falta el fichero VERSION junto al instalador. Es lo que fija la etiqueta de imagen: sin el no se sabe que version se instalaria, asi que no se instala nada."
KQ_MSG_ES[c_env_key]="%s relleno en la plantilla"
KQ_MSG_ES[f_env_key]="rellena %s en %s antes de instalar. Lo que tienes que rellenar esta marcado con [CLIENTE] en ese fichero."

KQ_MSG_ES[existing_found]="Se ha encontrado una instalacion previa de KronoQR en este servidor:"
KQ_MSG_ES[existing_item]="  · %s"
KQ_MSG_ES[existing_stop]="Este script NO reinstala: hacerlo encima destruiria el registro horario, que hay que conservar cuatro anos por ley.

Que hacer:
  · Para ACTUALIZAR a una version nueva:  ./update.sh
    Procedimiento completo en docs/runbooks/actualizacion-cliente.md
  · Para ver como esta la instalacion:    ./doctor.sh

  (update.sh y doctor.sh se entregan a partir de la version 2.1. En la 2.0.0,
  para ver como esta la instalacion: docker compose ps y los dos comandos de
  docs/cliente/operacion.md.)
  · Si de verdad quieres empezar de cero, retira antes la instalacion actual a
    conciencia, con su copia de seguridad. El instalador no lo hace por ti, y
    es deliberado.

No se ha tocado nada."

KQ_MSG_ES[secrets_note]="Los secretos se generan AQUI y no salen de este servidor. El fabricante no los conoce y no puede recuperarlos."
KQ_MSG_ES[secrets_written]="Escrito %s con permisos 0600."
KQ_MSG_ES[secrets_custody]="CUSTODIA BACKUP_ENCRYPTION_KEY FUERA DE ESTE SERVIDOR. Sin ella las copias no se pueden restaurar, y un servidor perdido seria un registro horario perdido. El procedimiento esta en docs/cliente/operacion.md, seccion «Custodia de secretos». La clave no se imprime aqui a proposito."
KQ_MSG_ES[wal_dir]="Creado %s para el archivado de WAL (propietario 70:70, el usuario de PostgreSQL)."
KQ_MSG_ES[f_wal_dir]="no se ha podido crear %s. Ejecuta \"sudo install -d -o 70 -g 70 -m 0750 %s\" y vuelve a lanzar el instalador."

KQ_MSG_ES[images_pull]="Descargando las imagenes de la version %s. La primera vez tarda unos minutos."
KQ_MSG_ES[f_images]="no se han podido descargar las imagenes de la version %s desde %s. Comprueba que el servidor llega al registro y que has iniciado sesion con \"docker login %s\". Si esta instalacion no tiene salida a internet, carga las imagenes del paquete con \"docker load -i imagenes-%s.tar\" y vuelve a ejecutar."
KQ_MSG_ES[services_up]="Levantando los servicios."
KQ_MSG_ES[f_services_up]="los servicios no han arrancado. El detalle esta arriba; amplialo con \"docker compose -f %s logs\"."
KQ_MSG_ES[waiting]="Esperando a que %s este listo (hasta %d s)..."
KQ_MSG_ES[waiting_ok]="%s listo."
KQ_MSG_ES[f_waiting]="%s no ha llegado a estar listo en %d s. Mira su registro con \"docker compose -f %s logs %s\": la causa suele estar en las ultimas 20 lineas."
KQ_MSG_ES[migrating]="Aplicando el esquema de la base de datos."
KQ_MSG_ES[f_migrating]="las migraciones han fallado. El detalle esta arriba y en \"docker compose -f %s logs app\". La base se crea vacia y el instalador la retira al deshacer, asi que no queda nada a medias."
KQ_MSG_ES[seed_note]="Datos de arranque: el perfil de convenio y los catalogos los siembran las propias migraciones. NO se instala ningun dato de demostracion."

KQ_MSG_ES[verify_probe]="Sonda %s"
KQ_MSG_ES[f_verify_probe]="la sonda %s no responde correctamente (%s). Los servicios estan en pie: revisa \"docker compose -f %s logs nginx app\" y el certificado de %s. NO se deshace nada, porque la instalacion y sus datos existen."
KQ_MSG_ES[verify_license]="Estado de la licencia consultado sin errores"
KQ_MSG_ES[f_verify_license]="\"license:show\" ha terminado con error. No impide fichar ni consultar el registro —la licencia nunca bloquea el registro legal—. Ejecuta \"docker compose -f %s exec app php artisan license:show\" y sigue lo que diga."

KQ_MSG_ES[rollback_start]="Algo ha fallado. Deshaciendo lo que este instalador ha hecho en ESTA ejecucion."
KQ_MSG_ES[rollback_item]="  deshecho: %s"
KQ_MSG_ES[rollback_failed_item]="  NO se ha podido deshacer: %s"
KQ_MSG_ES[rollback_done]="Vuelta atras completada: el servidor esta como antes de ejecutar el instalador. Corrige la causa y vuelve a ejecutarlo."
KQ_MSG_ES[rollback_incomplete]="VUELTA ATRAS INCOMPLETA. Ha quedado algo a medias y hay que retirarlo a mano antes de volver a instalar. Lo que queda esta en la lista de arriba. Ordenes utiles:
  docker compose -f %s down -v --remove-orphans
  rm -f %s"

KQ_MSG_ES[done_title]="KronoQR %s instalado y verificado."
KQ_MSG_ES[done_admin]="  Panel de gestion:    %s/admin/"
KQ_MSG_ES[done_kiosk]="  Quiosco (tablet):    %s/kiosk/"
KQ_MSG_ES[done_portal]="  Portal del empleado: %s/portal/  (solo desde PORTAL_INTERNAL_CIDR)"
KQ_MSG_ES[done_next]="SIGUIENTE PASO: abre el panel de gestion. La primera vez te guia un asistente que crea la organizacion, el centro, el primer administrador y el primer quiosco. Hasta que lo termines no hay ninguna cuenta: el instalador no crea usuarios.
(El asistente se entrega a partir de la version 2.1. En la 2.0.0 la primera cuenta se crea por consola; el procedimiento esta en docs/cliente/instalacion.md.)"
KQ_MSG_ES[done_docs]="DOCUMENTACION, en %s
  instalacion.md           esta instalacion, y que hacer ante cada codigo de salida
  operacion.md             copias, actualizaciones, calendario y codigos de salida
  configuracion.md         cada parametro y que hace
  obligaciones-legales.md  lo que le corresponde al hotel"
KQ_MSG_ES[done_custody]="ANTES DE CERRAR LA SESION: custodia BACKUP_ENCRYPTION_KEY fuera de este servidor (docs/cliente/operacion.md, «Custodia de secretos»)."

KQ_MSG_ES[bad_option]="opcion desconocida: %s. Ejecuta \"install.sh --help\" para ver las que hay."
KQ_MSG_ES[missing_value]="la opcion %s necesita un valor."
KQ_MSG_ES[bad_lang]="idioma no soportado: %s. Los que hay son \"es\" y \"en\"."

KQ_MSG_ES[exit_line]="Salida %d — %s."
KQ_MSG_ES[version_unknown]="desconocida"
KQ_MSG_ES[no_previous_install]="No hay instalacion previa."
KQ_MSG_ES[f_template_copy]="copia la plantilla y rellena lo marcado [CLIENTE]: cp %s %s"
KQ_MSG_ES[f_port_unknown]="este servidor no tiene 'ss' ni 'netstat'. Si al levantar los servicios aparece 'port is already allocated', para el proceso que ocupa el puerto %s o cambia HTTP_PORT/HTTPS_PORT en el .env."
KQ_MSG_ES[w_appurl_no_resolver]="no hay 'getent' en este servidor y no se ha podido comprobar la resolucion de %s. Compruebalo a mano desde un quiosco."
KQ_MSG_ES[c_tls_self_signed]="TLS_ALLOW_SELF_SIGNED=true con el certificado en %s"
KQ_MSG_ES[f_tls_self_signed]="pon TLS_ALLOW_SELF_SIGNED=false y coloca el certificado del hotel. Con true, el servidor web se genera uno autofirmado: los quioscos avisaran de sitio no seguro cada manana y alguien acabara desactivando la comprobacion en las tablets. Desde ese dia, el canal por el que viajan los fichajes no lo protege nadie."
KQ_MSG_ES[w_tls_self_signed]="TLS_ALLOW_SELF_SIGNED=true es exclusivo de entornos de prueba. En el servidor de un cliente ponlo a false y coloca el certificado del hotel."
KQ_MSG_ES[c_tls_missing]="Falta el certificado TLS en %s"
KQ_MSG_ES[c_env_template]="%s sigue con el valor de ejemplo de la plantilla"
KQ_MSG_ES[f_env_template]="%s vale todavia '%s', que es el ejemplo que trae la plantilla. Ponle el valor de ESTA instalacion en %s. Instalar con el valor de ejemplo produce un sistema que arranca y al que ningun quiosco puede llegar, y eso no lo detecta ninguna comprobacion posterior."
KQ_MSG_ES[c_root]="Privilegios para asignar el propietario del archivo de WAL"
KQ_MSG_ES[f_root]="ejecuta el instalador como root: sudo ./install.sh. Asignar el propietario de %s exige root, y sin ese propietario PostgreSQL no puede archivar el WAL: se acumula hasta llenar el disco y la base acaba parandose. Pertenecer al grupo docker NO basta para esto. Alternativa, si no puedes usar sudo: crea el directorio a mano con 'sudo install -d -o 70 -g 70 -m 0750 %s' y vuelve a ejecutar."
KQ_MSG_ES[c_root_not_needed]="El archivo de WAL %s ya existe con el propietario correcto"
KQ_MSG_ES[c_owner_fallback]="Creado %s sin poder asignarle el propietario %s"
KQ_MSG_ES[f_owner_fallback]="ejecuta 'sudo chown -R %s:%s %s'. La copia diaria la escribe la aplicacion con ese uid; si el directorio no es suyo, fallara la primera vez que se ejecute y el aviso llegara semanas despues."
KQ_MSG_ES[c_metrics_dir]="No se ha podido crear %s"
KQ_MSG_ES[f_metrics_dir]="crealo con 'sudo install -d -o 1000 -g 1000 -m 0750 %s'. No impide instalar ni hacer copias, pero sin el nadie publica el RESULTADO de la copia como metrica, y una copia fallida deja de tener quien avise."
KQ_MSG_ES[f_secret_generation]="no se ha podido generar el secreto %s: se han obtenido %d caracteres y hacen falta al menos %d. Comprueba que openssl funciona ('openssl rand -base64 32') y que hay espacio en disco. El valor NO se ha escrito."
KQ_MSG_ES[f_unexpected]="fallo inesperado en la linea %s del instalador. Es un defecto del producto: guarda esta salida completa y mandasela al fabricante."
KQ_MSG_ES[undo_env]="%s devuelto a como estaba antes de instalar"
KQ_MSG_ES[undo_services]="servicios y volumenes creados por esta instalacion"
KQ_MSG_ES[undo_backup_dir]="directorio de copias %s"
KQ_MSG_ES[undo_wal_dir]="directorio de WAL %s"
KQ_MSG_ES[undo_metrics_dir]="directorio de metricas %s"
KQ_MSG_ES[f_app_env]="pon APP_ENV=production en %s. Con cualquier otro valor la aplicacion afloja comprobaciones pensadas para desarrollo."
KQ_MSG_ES[f_app_debug]="pon APP_DEBUG=false en %s. Con true, cualquier error muestra la traza y la configuracion —incluidas las claves— a quien lo provoque. La aplicacion se NIEGA a arrancar en produccion con APP_DEBUG=true, asi que instalar asi no llevaria a ninguna parte."

KQ_MSG_ES[c_port_busy]="Puerto %s OCUPADO por otro proceso"
KQ_MSG_ES[c_not_writable]="NO se puede escribir en %s"
KQ_MSG_ES[c_appurl_dns_no]="El nombre %s NO resuelve desde este servidor"

KQ_MSG_ES[c_env_missing]="%s sin rellenar en la plantilla"
KQ_MSG_ES[not_found]="(no encontrado)"
KQ_MSG_ES[unknown_value]="(desconocida)"
KQ_MSG_ES[absent]="(ausente)"

KQ_MSG_ES[c_template_missing]="Falta la plantilla de configuracion %s"

KQ_MSG_ES[empty_value]="(vacio)"

KQ_MSG_ES[prefix_warning]="AVISO"

KQ_MSG_ES[c_appurl_dns_unknown]="No se ha podido comprobar si %s resuelve desde este servidor"

KQ_MSG_ES[c_tls_readable]="El borde (uid %s) puede leer %s"
KQ_MSG_ES[c_tls_unreadable]="El borde (uid %s) NO puede leer %s"
KQ_MSG_ES[f_tls_unreadable]="el servidor web corre SIN PRIVILEGIOS dentro de su contenedor, con el uid %s, y no puede abrir un fichero de root. Si no lo arreglas, nginx entra en bucle de reinicio con 'Permission denied' y no se sirve nada. Dos caminos, segun de quien sea el directorio:

  · Si %s es del paquete de KronoQR (lo normal):
      sudo chown %s:%s %s
      sudo chmod 0400 %s          # 0444 si es el .crt

  · Si ese directorio lo comparte otro servicio del hotel (por ejemplo el de
    Let's Encrypt), NO le cambies el propietario: romperias ese otro servicio.
    Copia el certificado a un directorio propio, apunta ahi TLS_CERT_DIR y
    aplica alli las ordenes de arriba."
KQ_MSG_ES[c_tls_key_world_readable]="%s es legible por cualquier usuario del servidor"
KQ_MSG_ES[f_tls_key_world_readable]="el borde puede leerla, asi que la instalacion funcionara, pero una clave privada de TLS con permiso de lectura universal la puede copiar cualquiera con una sesion en esta maquina. Dejala solo para el borde: sudo chown %s:%s %s && sudo chmod 0400 %s"
KQ_MSG_ES[c_tls_unknown]="No se ha podido comprobar quien puede leer %s"
KQ_MSG_ES[f_tls_unknown]="este servidor no tiene 'stat', asi que no se sabe si el borde podra leer %s. Compruebalo a mano: el fichero tiene que ser legible por el uid %s. Si al levantar los servicios nginx reinicia con 'Permission denied', es esto."

KQ_MSG_ES[c_port_ours]="Puerto %s: lo ocupa esta misma instalacion de KronoQR"

#------------------------------------------------------------------------------
# English
#------------------------------------------------------------------------------
KQ_MSG_EN[usage]="KronoQR — installer for the customer server.

Usage:
  install.sh [options]

Options:
  --check-only        Runs ONLY phase 1 (requirement checks) and exits. Writes
                      nothing. Useful before booking the maintenance window.
  --env-file PATH     .env file to create. Defaults to the one next to the
                      compose file.
  --compose-file PATH Production compose file. Defaults to the one shipped
                      with the installer.
  --lang es|en        Message language. Defaults to the system locale.
  --help              This help.
  --version           Product version that would be installed.

Exit codes: 0 success · 1 wrong usage · 2 requirements not met (nothing
written) · 3 previous installation found (nothing written) · 4 failure, rolled
back · 5 failure, rollback INCOMPLETE · 6 post-install verification failed.
Full table in docs/cliente/operacion.md."

KQ_MSG_EN[phase_1]="Phase 1 of 5 — checking requirements. Nothing is written yet."
KQ_MSG_EN[phase_2]="Phase 2 of 5 — looking for a previous installation."
KQ_MSG_EN[phase_3]="Phase 3 of 5 — generating secrets on THIS server and writing %s."
KQ_MSG_EN[phase_4]="Phase 4 of 5 — starting services and applying the schema."
KQ_MSG_EN[phase_5]="Phase 5 of 5 — verifying the installation."

KQ_MSG_EN[check_ok]="  [ok]    %s"
KQ_MSG_EN[check_warn]="  [warn]  %s"
KQ_MSG_EN[check_fail]="  [FAIL]  %s"
KQ_MSG_EN[fix]="          What to do: %s"

KQ_MSG_EN[req_summary_ok]="Requirements met: %d checks, %d warnings."
KQ_MSG_EN[req_summary_fail]="Requirements NOT met: %d failures. Nothing has been written; the server is as it was."
KQ_MSG_EN[check_only_done]="Check only (--check-only): nothing was touched. Run again without the flag to install."

KQ_MSG_EN[c_docker_access]="Permission to talk to Docker"
KQ_MSG_EN[f_docker_access]="run the installer as root (sudo ./install.sh) or add your user to the docker group with \"sudo usermod -aG docker \$USER\" and log in again."
KQ_MSG_EN[c_docker]="Docker %s (24 or newer required)"
KQ_MSG_EN[f_docker_missing]="install Docker Engine 24 or newer following https://docs.docker.com/engine/install/ and run this script again."
KQ_MSG_EN[f_docker_old]="upgrade Docker Engine to 24 or newer. The installed one is %s."
KQ_MSG_EN[c_compose]="Docker Compose v2 (%s)"
KQ_MSG_EN[f_compose]="install the Compose v2 plugin: \"docker compose version\" has to answer. The old \"docker-compose\" binary will not do, because this product uses v2 syntax."
KQ_MSG_EN[c_cpu]="CPU: %d cores (published minimum: %d)"
KQ_MSG_EN[f_cpu]="grow the machine to %d cores. With fewer, the morning shift change queues clock-ins."
KQ_MSG_EN[c_ram]="Memory: %d MiB (published minimum: %d MiB)"
KQ_MSG_EN[f_ram]="grow the machine to %d MiB of RAM. If you cannot, switch observability off by leaving COMPOSE_PROFILES empty in the .env: it frees about 700 MiB, but you lose the backup and disk alerts."
KQ_MSG_EN[c_disk]="Free disk on %s: %d GiB (published minimum: %d GiB)"
KQ_MSG_EN[f_disk]="free space or mount a larger disk on %s. Time records are kept 4 years by law: the storage has to last that long."
KQ_MSG_EN[c_port]="Port %s free"
KQ_MSG_EN[c_port_unknown]="Port %s: could not check whether it is free"
KQ_MSG_EN[f_port]="port %s is already used by another process. Stop it, or change HTTP_PORT/HTTPS_PORT in the .env. To see who holds it: sudo ss -lptn \"sport = :%s\"."
KQ_MSG_EN[c_writable]="Writable: %s"
KQ_MSG_EN[f_writable]="create the directory and grant it to the user running the installer: sudo install -d -o \$(id -u) -g \$(id -g) -m 0750 %s"
KQ_MSG_EN[c_openssl]="openssl available to generate the secrets"
KQ_MSG_EN[f_openssl]="install the openssl package (apt install openssl / dnf install openssl). The installer generates every key of this installation with it."
KQ_MSG_EN[c_curl]="curl available to verify the installation"
KQ_MSG_EN[f_curl]="install the curl package (apt install curl / dnf install curl). Without it phase 5 cannot check that the system answers, and an unverified installation is not declared correct."
KQ_MSG_EN[c_appurl]="APP_URL: %s"
KQ_MSG_EN[f_appurl]="set APP_URL to the https URL the kiosks and the admin panel will use to reach this server, matching the certificate name. Example: APP_URL=https://timeclock.myhotel.local"
KQ_MSG_EN[c_appurl_dns]="Name %s resolves from this server"
KQ_MSG_EN[w_appurl_dns]="name %s does not resolve from this server. It does not stop the install —split DNS is common— but check from a kiosk that it resolves there, or clock-ins will not arrive."
KQ_MSG_EN[c_tls]="TLS certificate in %s"
KQ_MSG_EN[f_tls]="put the hotel certificate as tls.crt and its key as tls.key inside %s. Without them the web server does not start. A self-signed one makes the kiosks warn every morning until somebody turns the check off."
KQ_MSG_EN[c_template]="Configuration template %s"
KQ_MSG_EN[f_template]="copy the .env.example file shipped next to the installer in the delivery package."
KQ_MSG_EN[c_compose_file]="Compose file %s"
KQ_MSG_EN[f_compose_file]="run the installer from the delivery package directory, or pass the path with --compose-file."
KQ_MSG_EN[c_version]="Version being installed: %s"
KQ_MSG_EN[f_version]="the VERSION file next to the installer is missing. It is what pins the image tag: without it nobody knows which version would be installed, so nothing is installed."
KQ_MSG_EN[c_env_key]="%s filled in the template"
KQ_MSG_EN[f_env_key]="fill %s in %s before installing. What you have to fill is marked [CLIENTE] in that file."

KQ_MSG_EN[existing_found]="A previous KronoQR installation was found on this server:"
KQ_MSG_EN[existing_item]="  · %s"
KQ_MSG_EN[existing_stop]="This script does NOT reinstall: doing it on top would destroy the time record, which the law requires to be kept for four years.

What to do:
  · To UPGRADE to a new version:      ./update.sh
    Full procedure in docs/runbooks/actualizacion-cliente.md
  · To see how the installation is:   ./doctor.sh

  (update.sh and doctor.sh ship from version 2.1 onwards. On 2.0.0, to see how
  the installation is: docker compose ps and the two commands in
  docs/cliente/operacion.md.)
  · If you really want to start over, remove the current installation on
    purpose first, backup included. The installer will not do it for you, and
    that is deliberate.

Nothing has been touched."

KQ_MSG_EN[secrets_note]="Secrets are generated HERE and never leave this server. The manufacturer does not know them and cannot recover them."
KQ_MSG_EN[secrets_written]="Wrote %s with mode 0600."
KQ_MSG_EN[secrets_custody]="KEEP BACKUP_ENCRYPTION_KEY OUTSIDE THIS SERVER. Without it the backups cannot be restored, and a lost server would be a lost time record. The procedure is in docs/cliente/operacion.md, section «Custodia de secretos». The key is deliberately not printed here."
KQ_MSG_EN[wal_dir]="Created %s for WAL archiving (owner 70:70, the PostgreSQL user)."
KQ_MSG_EN[f_wal_dir]="could not create %s. Run \"sudo install -d -o 70 -g 70 -m 0750 %s\" and launch the installer again."

KQ_MSG_EN[images_pull]="Downloading the images for version %s. The first time takes a few minutes."
KQ_MSG_EN[f_images]="could not download the images for version %s from %s. Check the server reaches the registry and that you logged in with \"docker login %s\". If this installation has no internet access, load the images from the package with \"docker load -i imagenes-%s.tar\" and run again."
KQ_MSG_EN[services_up]="Starting the services."
KQ_MSG_EN[f_services_up]="the services did not start. The detail is above; expand it with \"docker compose -f %s logs\"."
KQ_MSG_EN[waiting]="Waiting for %s to be ready (up to %d s)..."
KQ_MSG_EN[waiting_ok]="%s ready."
KQ_MSG_EN[f_waiting]="%s did not become ready within %d s. Read its log with \"docker compose -f %s logs %s\": the cause is usually in the last 20 lines."
KQ_MSG_EN[migrating]="Applying the database schema."
KQ_MSG_EN[f_migrating]="migrations failed. The detail is above and in \"docker compose -f %s logs app\". The database is created empty and the installer removes it while rolling back, so nothing is left half done."
KQ_MSG_EN[seed_note]="Bootstrap data: the collective-agreement profile and the catalogues are seeded by the migrations themselves. NO demonstration data is installed."

KQ_MSG_EN[verify_probe]="Probe %s"
KQ_MSG_EN[f_verify_probe]="probe %s does not answer correctly (%s). The services are up: check \"docker compose -f %s logs nginx app\" and the certificate for %s. NOTHING is rolled back, because the installation and its data exist."
KQ_MSG_EN[verify_license]="License status read without errors"
KQ_MSG_EN[f_verify_license]="\"license:show\" ended with an error. It does not stop clock-in nor access to the record —the licence never blocks the legal record—. Run \"docker compose -f %s exec app php artisan license:show\" and follow what it says."

KQ_MSG_EN[rollback_start]="Something failed. Undoing what this installer did in THIS run."
KQ_MSG_EN[rollback_item]="  undone: %s"
KQ_MSG_EN[rollback_failed_item]="  COULD NOT undo: %s"
KQ_MSG_EN[rollback_done]="Rollback complete: the server is as it was before running the installer. Fix the cause and run it again."
KQ_MSG_EN[rollback_incomplete]="ROLLBACK INCOMPLETE. Something was left half done and has to be removed by hand before installing again. What is left is in the list above. Useful commands:
  docker compose -f %s down -v --remove-orphans
  rm -f %s"

KQ_MSG_EN[done_title]="KronoQR %s installed and verified."
KQ_MSG_EN[done_admin]="  Admin panel:       %s/admin/"
KQ_MSG_EN[done_kiosk]="  Kiosk (tablet):    %s/kiosk/"
KQ_MSG_EN[done_portal]="  Employee portal:   %s/portal/  (only from PORTAL_INTERNAL_CIDR)"
KQ_MSG_EN[done_next]="NEXT STEP: open the admin panel. The first time, a wizard walks you through the organisation, the site, the first administrator and the first kiosk. Until you finish it there is no account at all: the installer creates no users.
(The wizard ships from version 2.1 onwards. On 2.0.0 the first account is created from the console; the procedure is in docs/cliente/instalacion.md.)"
KQ_MSG_EN[done_docs]="DOCUMENTATION, in %s
  instalacion.md           this installation, and what to do for each exit code
  operacion.md             backups, upgrades, calendar and exit codes
  configuracion.md         every parameter and what it does
  obligaciones-legales.md  what belongs to the hotel"
KQ_MSG_EN[done_custody]="BEFORE YOU LOG OUT: keep BACKUP_ENCRYPTION_KEY outside this server (docs/cliente/operacion.md, «Custodia de secretos»)."

KQ_MSG_EN[bad_option]="unknown option: %s. Run \"install.sh --help\" to see the ones there are."
KQ_MSG_EN[missing_value]="option %s needs a value."
KQ_MSG_EN[bad_lang]="unsupported language: %s. The ones there are: \"es\" and \"en\"."

KQ_MSG_EN[exit_line]="Exit %d — %s."
KQ_MSG_EN[version_unknown]="unknown"
KQ_MSG_EN[no_previous_install]="No previous installation found."
KQ_MSG_EN[f_template_copy]="copy the template and fill in what is marked [CLIENTE]: cp %s %s"
KQ_MSG_EN[f_port_unknown]="this server has neither 'ss' nor 'netstat'. If starting the services shows 'port is already allocated', stop whatever holds port %s or change HTTP_PORT/HTTPS_PORT in the .env."
KQ_MSG_EN[w_appurl_no_resolver]="there is no 'getent' on this server, so the resolution of %s could not be checked. Check it by hand from a kiosk."
KQ_MSG_EN[c_tls_self_signed]="TLS_ALLOW_SELF_SIGNED=true with the certificate in %s"
KQ_MSG_EN[f_tls_self_signed]="set TLS_ALLOW_SELF_SIGNED=false and put the hotel certificate in place. With true, the web server generates a self-signed one: the kiosks will warn about an unsafe site every morning until somebody turns the certificate check off on the tablets. From that day on, nothing protects the channel the clock-ins travel through."
KQ_MSG_EN[w_tls_self_signed]="TLS_ALLOW_SELF_SIGNED=true is for test environments only. On a customer server set it to false and put the hotel certificate in place."
KQ_MSG_EN[c_tls_missing]="TLS certificate missing in %s"
KQ_MSG_EN[c_env_template]="%s still holds the example value from the template"
KQ_MSG_EN[f_env_template]="%s is still '%s', the example shipped in the template. Set the value of THIS installation in %s. Installing with the example value produces a system that starts and that no kiosk can reach, and no later check detects that."
KQ_MSG_EN[c_root]="Privileges to set the owner of the WAL archive"
KQ_MSG_EN[f_root]="run the installer as root: sudo ./install.sh. Setting the owner of %s requires root, and without that owner PostgreSQL cannot archive the WAL: it piles up until the disk is full and the database stops. Being in the docker group is NOT enough for this. Alternative, if you cannot use sudo: create the directory by hand with 'sudo install -d -o 70 -g 70 -m 0750 %s' and run again."
KQ_MSG_EN[c_root_not_needed]="The WAL archive %s already exists with the right owner"
KQ_MSG_EN[c_owner_fallback]="Created %s but could not set its owner to %s"
KQ_MSG_EN[f_owner_fallback]="run 'sudo chown -R %s:%s %s'. The daily backup is written by the application under that uid; if the directory is not its own, it will fail the first time it runs and you will hear about it weeks later."
KQ_MSG_EN[c_metrics_dir]="Could not create %s"
KQ_MSG_EN[f_metrics_dir]="create it with 'sudo install -d -o 1000 -g 1000 -m 0750 %s'. It does not stop the install nor the backups, but without it nobody publishes the RESULT of the backup as a metric, and a failed backup has nobody to report it."
KQ_MSG_EN[f_secret_generation]="could not generate the secret %s: %d characters were obtained and at least %d are needed. Check that openssl works ('openssl rand -base64 32') and that there is free disk space. The value was NOT written."
KQ_MSG_EN[f_unexpected]="unexpected failure on line %s of the installer. This is a product defect: save this whole output and send it to the manufacturer."
KQ_MSG_EN[undo_env]="%s restored to how it was before installing"
KQ_MSG_EN[undo_services]="services and volumes created by this installation"
KQ_MSG_EN[undo_backup_dir]="backup directory %s"
KQ_MSG_EN[undo_wal_dir]="WAL directory %s"
KQ_MSG_EN[undo_metrics_dir]="metrics directory %s"
KQ_MSG_EN[f_app_env]="set APP_ENV=production in %s. With any other value the application relaxes checks meant for development."
KQ_MSG_EN[f_app_debug]="set APP_DEBUG=false in %s. With true, any error shows the stack trace and the configuration —keys included— to whoever triggers it. The application REFUSES to start in production with APP_DEBUG=true, so installing like this would lead nowhere."

KQ_MSG_EN[c_port_busy]="Port %s IS BUSY, another process holds it"
KQ_MSG_EN[c_not_writable]="NOT writable: %s"
KQ_MSG_EN[c_appurl_dns_no]="Name %s does NOT resolve from this server"

KQ_MSG_EN[c_env_missing]="%s not filled in the template"
KQ_MSG_EN[not_found]="(not found)"
KQ_MSG_EN[unknown_value]="(unknown)"
KQ_MSG_EN[absent]="(absent)"

KQ_MSG_EN[c_template_missing]="Configuration template missing: %s"

KQ_MSG_EN[empty_value]="(empty)"

KQ_MSG_EN[prefix_warning]="WARNING"

KQ_MSG_EN[c_appurl_dns_unknown]="Could not check whether %s resolves from this server"

KQ_MSG_EN[c_tls_readable]="The edge (uid %s) can read %s"
KQ_MSG_EN[c_tls_unreadable]="The edge (uid %s) CANNOT read %s"
KQ_MSG_EN[f_tls_unreadable]="the web server runs UNPRIVILEGED inside its container, as uid %s, and cannot open a root-owned file. If you do not fix this, nginx restarts in a loop with 'Permission denied' and nothing is served. Two paths, depending on who owns the directory:

  · If %s belongs to the KronoQR package (the usual case):
      sudo chown %s:%s %s
      sudo chmod 0400 %s          # 0444 for the .crt

  · If that directory is shared with another service of the hotel (the Let's
    Encrypt one, for instance), do NOT change its owner: you would break that
    other service. Copy the certificate into a directory of your own, point
    TLS_CERT_DIR there and apply the commands above there."
KQ_MSG_EN[c_tls_key_world_readable]="%s is readable by every user of this server"
KQ_MSG_EN[f_tls_key_world_readable]="the edge can read it, so the installation will work, but a TLS private key with world read permission can be copied by anyone with a session on this machine. Leave it to the edge only: sudo chown %s:%s %s && sudo chmod 0400 %s"
KQ_MSG_EN[c_tls_unknown]="Could not check who can read %s"
KQ_MSG_EN[f_tls_unknown]="this server has no 'stat', so whether the edge can read %s is unknown. Check it by hand: the file must be readable by uid %s. If nginx restarts with 'Permission denied' when the services come up, this is it."

KQ_MSG_EN[c_port_ours]="Port %s: held by this very KronoQR installation"

#------------------------------------------------------------------------------
# Resolucion
#------------------------------------------------------------------------------

# Fija el idioma a partir de --lang, KRONOQR_LANG o la configuracion regional.
# Un valor desconocido en el ENTORNO no es un error: se cae a espanol, porque
# un servidor con LANG=fr_FR no debe quedarse sin instalar. Un valor desconocido
# en --lang si lo es, porque ahi alguien lo escribio a proposito; ese caso lo
# rechaza install.sh con codigo 1.
kq_msg_init() {
  local requested="${1:-}"

  if [ -z "${requested}" ]; then
    requested="${KRONOQR_LANG:-}"
  fi

  if [ -z "${requested}" ]; then
    requested="${LC_ALL:-${LC_MESSAGES:-${LANG:-es}}}"
    requested="${requested%%_*}"
    requested="${requested%%.*}"
  fi

  case "${requested}" in
  es | en) KQ_LANG="${requested}" ;;
  *) KQ_LANG="es" ;;
  esac
}

# Texto de una clave en el idioma activo. Si faltara en ingles cae a espanol en
# vez de imprimir un hueco: un mensaje en el idioma equivocado sigue diciendo
# que hacer; un mensaje vacio no.
kq_text() {
  local key="$1" value=""

  if [ "${KQ_LANG}" = "en" ]; then
    value="${KQ_MSG_EN[${key}]:-}"
  fi

  if [ -z "${value}" ]; then
    value="${KQ_MSG_ES[${key}]:-}"
  fi

  printf '%s' "${value}"
}

# Devuelve una clave ya formateada, SIN salto de linea. Es la que se usa cuando
# el texto va incrustado en otro mensaje: un «que hacer», una linea de lista.
#
# Estas dos funciones son el UNICO sitio del arbol donde un printf recibe un
# formato en variable. Concentrarlo aqui es lo que permite que la supresion de
# SC2059 este escrita UNA vez, con su motivo, en lugar de repetida sesenta
# veces por los scripts, donde nadie la volveria a leer y acabaria tapando un
# formato de verdad peligroso.
kq_format() {
  local key="$1"
  shift
  # El formato es una plantilla del catalogo de este fichero, no entrada del
  # usuario: es exactamente el caso para el que existe printf con formato
  # variable.
  # shellcheck disable=SC2059
  printf "$(kq_text "${key}")" "$@"
}

# Imprime una clave con formato printf y salto de linea final. Los argumentos
# van tras la clave.
kq_msg() {
  local key="$1"
  shift
  # Mismo motivo que en kq_format: plantilla del catalogo, no entrada del
  # usuario.
  # shellcheck disable=SC2059
  printf "$(kq_text "${key}")\n" "$@"
}

# Comprueba que las dos tablas tienen las mismas claves. La ejercita la prueba
# de calidad del repositorio; en el servidor del cliente no se ejecuta.
kq_msg_check_catalog() {
  local key missing=0

  for key in "${!KQ_MSG_ES[@]}"; do
    if [ -z "${KQ_MSG_EN[${key}]:-}" ]; then
      printf 'falta en ingles: %s\n' "${key}" >&2
      missing=1
    fi
  done

  for key in "${!KQ_MSG_EN[@]}"; do
    if [ -z "${KQ_MSG_ES[${key}]:-}" ]; then
      printf 'falta en espanol: %s\n' "${key}" >&2
      missing=1
    fi
  done

  return "${missing}"
}
