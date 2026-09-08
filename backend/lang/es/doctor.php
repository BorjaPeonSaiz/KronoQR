<?php

declare(strict_types=1);

/*
 * Textos del informe de `php artisan product:doctor` (RF-PD-13, tarea 5.9).
 *
 * ## Para quien estan escritos
 *
 * Para la persona de informatica de un hotel que **no conoce este sistema**, que
 * tiene prisa y que probablemente ya tiene un problema. De ahi las tres reglas
 * que sigue cada frase:
 *
 *   1. `checks.*` dice **que se comprobo y que se encontro**, con la cifra.
 *   2. `fixes.*` dice **que hacer**, con el comando entero y copiable. El
 *      fabricante no tiene acceso a este servidor (ADR-016): si el texto no dice
 *      que hacer, la unica salida es una llamada de telefono.
 *   3. Ninguna frase da por sabido nada del producto. «La cadena de auditoria»
 *      se explica en la propia frase que la nombra.
 *
 * ## La clave lleva el estado, y a veces una variante
 *
 * `checks.<familia>.<comprobacion>.<estado>[_<variante>]`. La variante va con
 * guion bajo porque el traductor resuelve el punto como nivel de array: con
 * `warning` y `warning.unknown` el primero seria un array y no una frase.
 *
 * ## Las rutas de los comandos
 *
 * Los comandos `php artisan ...` se ejecutan DENTRO del contenedor de la
 * aplicacion. Los `docker compose ...`, desde el directorio de la instalacion.
 * Cada `fix` que lo necesita lo dice, porque quien lee esto puede no saber en
 * cual de los dos sitios esta.
 */

/** El texto comun de una familia de comprobaciones que revento entera. */
$probe = [
    'failure' => 'No se pudo ejecutar el grupo de comprobaciones «:family»: algo fallo de forma inesperada '
        .'dentro del propio diagnostico. El resto de comprobaciones si se ha ejecutado.',
];

$probeFix = [
    'failure' => "Es un fallo del producto, no de tu instalacion.\n"
        ."Genera el paquete de diagnostico y enviaselo a soporte:\n"
        .'  php artisan product:diagnostics',
];

return [

    /*
     * El marco del informe que imprime `product:doctor` sin `--json`.
     *
     * VIVE AQUI Y NO EN EL COMANDO por una razon concreta y medida: con el marco
     * escrito en PHP, el informe salia con el titulo y los encabezados en
     * español y las comprobaciones en ingles en cuanto `APP_LOCALE` y el idioma
     * de la instalacion no coincidian —el caso normal, porque el idioma del
     * panel se cambia desde el panel y `APP_LOCALE` se queda como lo dejo el
     * instalador—. Un informe a dos idiomas no lo lee nadie.
     */
    'report' => [
        'title' => 'Diagnostico de KronoQR :version',
        'all_ok' => 'Todo correcto: :total comprobaciones, ninguna con hallazgos.',
        'problems' => 'Hay :count comprobacion(es) con hallazgos, de :total:',
        'ok_header' => 'Comprobaciones correctas',
        'fix_label' => 'Que hacer:',
        'result' => 'Resultado: :label (codigo de salida :code)',
        'tag_ok' => 'ok',
        'tag_warning' => 'aviso',
        'tag_failure' => 'FALLO',
        'status_ok' => 'CORRECTO',
        'status_warning' => 'CON AVISOS',
        'status_failure' => 'CON FALLOS',
        'meaning_ok' => 'No hay nada que hacer.',
        'meaning_warning' => 'Nada esta roto. Se ficha y se consulta el registro con normalidad; lo de arriba '
            .'conviene mirarlo cuando puedas. La instalacion y la actualizacion NO se paran por esto.',
        'meaning_failure' => 'Hay algo que corregir. Sigue el «Que hacer» de cada fallo y vuelve a ejecutar este '
            .'comando. Si necesitas ayuda, genera el paquete de diagnostico con `php artisan product:diagnostics` '
            .'y enviaselo a soporte.',
    ],

    'checks' => [

        // --- Base de datos ---------------------------------------------------

        'database' => [
            'probe' => $probe,
            'connection' => [
                'ok' => 'La base de datos responde (:server_version) y tiene :applied_migrations migraciones aplicadas.',
                'failure' => 'No se puede conectar con la base de datos. Sin ella el sistema no puede registrar '
                    .'fichajes ni servir consultas.',
            ],
            'migrations_pending' => [
                'ok' => 'El esquema de la base de datos esta al dia.',
                'failure' => 'Hay :count migracion(es) sin aplicar. El codigo y la base de datos no coinciden, '
                    .'asi que cualquier pantalla puede fallar de forma extraña.',
            ],
            'audit_log_privileges' => [
                'ok' => 'El usuario de la aplicacion no puede modificar ni borrar el registro de auditoria, '
                    .'que es como tiene que ser.',
                'failure' => 'El usuario de base de datos «:user» PUEDE modificar o borrar el registro de auditoria. '
                    .'Ese registro es la prueba de que las horas no se han tocado; si se puede editar, deja de '
                    .'servir como prueba ante una inspeccion.',
                'warning_unknown' => 'No se han podido consultar los permisos sobre el registro de auditoria.',
            ],
            'audit_chain' => [
                'ok' => 'El registro de auditoria esta encadenado correctamente.',
                'failure' => 'El ultimo cierre anual del registro de auditoria no cuadra: faltan filas o el '
                    .'encadenado esta roto. Puede significar que alguien ha modificado el registro por fuera '
                    .'de la aplicacion, o que una restauracion quedo incompleta.',
                'warning_unknown' => 'No se ha podido comprobar el encadenado del registro de auditoria.',
            ],
        ],

        // --- Colas -----------------------------------------------------------

        'queue' => [
            'probe' => $probe,
            'redis' => [
                'ok' => 'Redis responde.',
                'failure' => 'Redis no responde. Sin el no funcionan la cola de trabajos, la cache ni las '
                    .'sesiones del panel.',
            ],
            'backlog' => [
                'ok' => 'La cola de trabajos esta al dia (:count pendientes).',
                'warning' => 'Hay :count trabajos esperando en la cola. Todavia no es grave, pero los avisos y '
                    .'los informes van con retraso.',
                'failure' => 'Hay :count trabajos atascados en la cola. Los avisos, los informes y el calculo '
                    .'nocturno no se estan ejecutando.',
                'warning_unknown' => 'No se ha podido medir el tamaño de la cola de trabajos.',
            ],
            'worker' => [
                'ok' => 'Hay alguien consumiendo la cola de trabajos.',
                'warning' => 'A las :checked_at UTC habia :count trabajos esperando y ninguno en proceso. Es probable '
                    .'que el proceso que consume la cola no este funcionando.',
                'warning_unknown' => 'No se ha podido saber si el proceso que consume la cola esta vivo.',
            ],
        ],

        // --- Correo ----------------------------------------------------------

        'mail' => [
            'probe' => $probe,
            'transport' => [
                'ok' => 'El correo esta configurado con el transporte «:mailer».',
                'warning' => 'En produccion, el correo esta puesto en «:mailer»: los mensajes no se envian a '
                    .'nadie, se escriben en el registro tecnico. Nada falla y nadie recibe nada.',
            ],
            'reachable' => [
                'ok' => 'El servidor de correo acepta conexiones en el puerto :port.',
                'warning' => 'No se ha podido conectar con el servidor de correo en el puerto :port. Los avisos '
                    .'por correo no saldran. El resto del sistema no depende de esto: en KronoQR nadie recibe '
                    .'su tarjeta ni su acceso por correo.',
                'warning_not_configured' => 'No hay servidor de correo configurado. Los avisos por correo no '
                    .'saldran. No impide fichar ni consultar el registro.',
            ],
        ],

        // --- Certificado -----------------------------------------------------

        'tls' => [
            'probe' => $probe,
            'certificate' => [
                'ok' => 'El certificado del servidor es valido y le quedan :days dias.',
                'warning' => 'El certificado del servidor caduca en :days dias. Cuando caduque, las tablets '
                    .'dejaran de sincronizar sus fichajes sin decir nada: la gente seguira fichando y la cola '
                    .'se ira acumulando en cada tablet.',
                'failure' => 'El certificado del servidor CADUCO hace :days dias. Las tablets no estan '
                    .'sincronizando: siguen aceptando fichajes y acumulandolos en local, pero no llegan aqui.',
                'warning_no_url' => 'No se ha podido interpretar la direccion del servidor (APP_URL), asi que no '
                    .'se ha comprobado el certificado.',
                'warning_not_https' => 'La direccion del servidor no usa https (:scheme), asi que no hay '
                    .'certificado que comprobar. En produccion esto no deberia ser asi.',
                'warning_unreachable' => 'No se ha podido abrir una conexion segura contra el puerto :port para '
                    .'leer el certificado. Puede ser que el servidor web no este levantado todavia.',
                'warning_unreadable' => 'Se ha conectado con el servidor web pero no se ha podido leer la fecha '
                    .'de caducidad de su certificado.',
                'warning_self_signed' => 'El certificado esta firmado por si mismo y la configuracion dice que '
                    .'no deberian aceptarse (TLS_ALLOW_SELF_SIGNED=false). Las tablets pueden rechazar la '
                    .'conexion.',
            ],
        ],

        // --- Permisos --------------------------------------------------------

        'permissions' => [
            'probe' => $probe,
            'storage' => [
                'ok' => 'Los directorios de trabajo de la aplicacion son escribibles.',
                'failure' => 'La aplicacion no puede escribir en: :paths. Sin eso no puede generar informes, '
                    .'ni guardar la cache, ni escribir su registro tecnico.',
            ],
            'backup_path' => [
                'ok' => 'El directorio de copias de seguridad es escribible.',
                'failure' => 'No se puede escribir en el directorio de copias :path. NO SE ESTAN HACIENDO '
                    .'COPIAS, y no hay ningun otro sintoma hasta el dia que hagan falta.',
                'failure_missing' => 'El directorio de copias :path no existe. NO SE ESTAN HACIENDO COPIAS.',
            ],
            'branding_root' => [
                'ok' => 'El directorio del logotipo esta accesible.',
                'warning' => 'No se puede leer el directorio del logotipo :path. Las aplicaciones enseñaran la '
                    .'marca del producto en lugar de la del hotel. No afecta a nada mas.',
            ],
            'branding_logo' => [
                'ok' => 'El logotipo configurado se lee correctamente.',
                'warning' => 'El logotipo configurado no se puede usar (:reason). Las aplicaciones enseñaran la '
                    .'marca del producto. No afecta a nada mas.',
                'warning_unknown' => 'No se ha podido comprobar el logotipo configurado.',
            ],
        ],

        // --- Disco -----------------------------------------------------------

        'disk' => [
            'probe' => $probe,
            'storage' => [
                'ok' => 'Queda espacio de sobra en el disco de la aplicacion (:free libres, :percent %).',
                'warning' => 'Queda poco espacio en el disco de la aplicacion: :free libres (:percent %).',
                'failure' => 'Queda muy poco espacio en el disco de la aplicacion: :free libres (:percent %). '
                    .'Cuando se llene, la base de datos dejara de aceptar escrituras y NO SE PODRA FICHAR.',
                'warning_missing' => 'No existe el directorio :path, asi que no se ha podido medir su disco.',
                'warning_unknown' => 'No se ha podido medir el espacio libre de :path.',
            ],
            'backup' => [
                'ok' => 'Queda espacio de sobra en el disco de las copias (:free libres, :percent %).',
                'warning' => 'Queda poco espacio en el disco de las copias: :free libres (:percent %).',
                'failure' => 'Queda muy poco espacio en el disco de las copias: :free libres (:percent %). Las '
                    .'proximas copias fallaran.',
                'warning_missing' => 'No existe el directorio :path, asi que no se ha podido medir su disco.',
                'warning_unknown' => 'No se ha podido medir el espacio libre de :path.',
            ],
        ],

        // --- Aplicacion ------------------------------------------------------

        'app' => [
            'probe' => $probe,
            'timezone_utc' => [
                'ok' => 'La aplicacion trabaja en UTC, que es lo correcto.',
                'failure' => 'La aplicacion esta trabajando en la zona horaria «:timezone» en lugar de UTC. '
                    .'Todas las horas que se guarden a partir de ahora quedaran desplazadas, Y ESO NO SE PUEDE '
                    .'DESHACER despues. La hora del hotel se calcula sola a partir de UTC: no hay que cambiar '
                    .'esto para que las pantallas enseñen la hora local.',
            ],
            'debug_in_production' => [
                'ok' => 'El modo de depuracion esta desactivado.',
                'failure' => 'El modo de depuracion (APP_DEBUG) esta activado en produccion. Cualquier error '
                    .'muestra por pantalla las contraseñas de la base de datos y las claves de firma del '
                    .'sistema a quien lo provoque.',
            ],
        ],

        // --- Configuracion ---------------------------------------------------

        'settings' => [
            'probe' => $probe,
            'invalid_keys' => [
                'ok' => 'Toda la configuracion guardada es valida.',
                'warning' => 'Hay ajustes guardados que el sistema no ha podido aplicar y ha sustituido por su '
                    .'valor de fabrica: :keys. Afectan a como se ve el sistema, no a las horas.',
                'failure' => 'Hay ajustes guardados que el sistema no ha podido aplicar y ha sustituido por su '
                    .'valor de fabrica: :keys. ALGUNO DE ELLOS AFECTA AL CALCULO DE LAS HORAS, asi que el '
                    .'sistema esta calculando con un valor distinto del que crees tener puesto.',
            ],
            'env_differs_from_db' => [
                'ok' => 'La configuracion del fichero .env y la guardada en el sistema coinciden.',
                'warning' => 'Estos ajustes valen algo distinto en el fichero .env y en el sistema: :keys. Manda '
                    .'lo guardado en el sistema, que es lo que se edita desde el panel. Es la explicacion '
                    .'habitual de «pues yo lo tengo puesto a otra cosa».',
            ],
        ],

        // --- Licencia --------------------------------------------------------

        'license' => [
            'probe' => $probe,
            'state' => [
                'ok' => 'La licencia esta vigente.',
                'warning_plan_exceeded' => 'La licencia esta vigente, pero la instalacion supera alguna cifra '
                    .'del plan contratado. No ha impedido ningun alta y no lo hara.',
                'warning_expiring_soon' => 'La licencia caduca en :days dias. Cuando caduque se seguira '
                    .'fichando y consultando el registro con normalidad; solo dejan de estar disponibles '
                    .'funciones accesorias como los informes por periodo.',
                'warning_expired' => 'La licencia caduco hace :days dias. SE SIGUE FICHANDO Y SE PUEDE EXPORTAR '
                    .'EL REGISTRO PARA LA INSPECCION con normalidad: lo unico que no esta disponible son '
                    .'funciones accesorias.',
                'warning_absent' => 'No hay ninguna licencia activada. Se ficha y se consulta el registro con '
                    .'normalidad; solo faltan las funciones accesorias.',
                'warning_not_yet_valid' => 'La licencia es correcta pero su periodo de validez empieza mas '
                    .'adelante. No hay que hacer nada.',
                'warning_unverifiable' => 'La licencia guardada no se puede verificar (:reason). Se ficha y se '
                    .'consulta el registro con normalidad.',
            ],
            'white_label_without_plan' => [
                'ok' => 'La marca configurada y el plan contratado son coherentes.',
                'warning' => 'Hay una marca propia configurada (nombre, color o logotipo) pero el plan '
                    .'contratado no la incluye, asi que las aplicaciones estan enseñando la marca del producto.',
            ],
        ],
    ],

    'fixes' => [

        'database' => [
            'probe' => $probeFix,
            'connection' => [
                'failure' => "Comprueba que el contenedor de la base de datos esta levantado:\n"
                    ."  docker compose ps\n"
                    ."  docker compose logs --tail=50 postgres\n"
                    ."Si no arranca, casi siempre es el disco lleno o unas credenciales cambiadas en .env\n"
                    .'(DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD).',
            ],
            'migrations_pending' => [
                'failure' => "Aplicalas con:\n"
                    ."  php artisan migrate --force\n"
                    ."Si esto aparece justo despues de una actualizacion, es que la actualizacion no llego a\n"
                    .'terminar: revisa el informe en el directorio de copias, en reports/update-*.log.',
            ],
            'audit_log_privileges' => [
                'failure' => "Retira esos permisos al usuario de la aplicacion, conectandote como administrador\n"
                    ."de la base de datos:\n"
                    ."  REVOKE UPDATE, DELETE ON audit_log FROM :user;\n"
                    ."Suele pasar despues de restaurar una copia con el usuario equivocado. Si no sabes como\n"
                    .'llegaron ahi, avisa a soporte antes de tocar nada: puede ser relevante.',
                'warning_unknown' => "Vuelve a ejecutar `php artisan product:doctor` cuando la base de datos\n"
                    .'responda. Si sigue sin poder comprobarse, genera el paquete de diagnostico.',
            ],
            'audit_chain' => [
                'failure' => "Verifica la cadena completa para saber donde esta la rotura:\n"
                    ."  php artisan compliance:verify-audit-chain\n"
                    ."NO borres ni edites nada. Guarda la salida de ese comando, genera el paquete de\n"
                    ."diagnostico y avisa a soporte: esto puede tener consecuencias legales y hay que\n"
                    .'documentar cuando ocurrio.',
                'warning_unknown' => 'Vuelve a ejecutarlo cuando la base de datos responda.',
            ],
        ],

        'queue' => [
            'probe' => $probeFix,
            'redis' => [
                'failure' => "Comprueba el contenedor de Redis:\n"
                    ."  docker compose ps\n"
                    ."  docker compose logs --tail=50 redis\n"
                    ."  docker compose restart redis\n"
                    .'Se puede seguir fichando mientras tanto, pero el panel puede pedir volver a entrar.',
            ],
            'backlog' => [
                'warning' => "Mira si el proceso que consume la cola esta vivo:\n"
                    ."  docker compose ps worker\n"
                    .'Si lo esta, es un pico normal y bajara solo. Vuelve a mirarlo en diez minutos.',
                'failure' => "Reinicia el proceso que consume la cola:\n"
                    ."  docker compose restart worker\n"
                    ."  docker compose logs --tail=100 worker\n"
                    ."Si los trabajos estan fallando en lugar de acumularse, la lista de fallidos se ve con:\n"
                    .'  php artisan queue:failed',
                'warning_unknown' => 'Comprueba que Redis responde y vuelve a ejecutar este comando.',
            ],
            'worker' => [
                'warning' => "Arranca o reinicia el proceso que consume la cola:\n"
                    ."  docker compose ps worker\n"
                    ."  docker compose restart worker\n"
                    .'Fichar no depende de el; los avisos y los informes si.',
                'warning_unknown' => 'Comprueba que Redis responde y vuelve a ejecutar este comando.',
            ],
        ],

        'mail' => [
            'probe' => $probeFix,
            'transport' => [
                'warning' => "Pon en el fichero .env los datos del servidor de correo del hotel:\n"
                    ."  MAIL_MAILER=smtp\n"
                    ."  MAIL_HOST=...\n"
                    ."  MAIL_PORT=587\n"
                    ."y reinicia la aplicacion:\n"
                    ."  docker compose up -d app\n"
                    .'Si no quieres avisos por correo, dejalo como esta: no afecta a nada mas.',
            ],
            'reachable' => [
                'warning' => "Comprueba MAIL_HOST y MAIL_PORT en el fichero .env y que el cortafuegos del hotel\n"
                    ."deja salir por ese puerto. Despues:\n"
                    .'  docker compose up -d app',
                'warning_not_configured' => "Si quieres avisos por correo, rellena MAIL_MAILER, MAIL_HOST y\n"
                    ."MAIL_PORT en el fichero .env y reinicia con `docker compose up -d app`.\n"
                    .'Si no los quieres, no hay nada que hacer.',
            ],
        ],

        'tls' => [
            'probe' => $probeFix,
            'certificate' => [
                'warning' => "Renueva el certificado antes de que caduque. Copia el nuevo par de ficheros al\n"
                    ."directorio de certificados (TLS_CERT_DIR del fichero .env) y recarga el servidor web:\n"
                    .'  docker compose restart nginx',
                'failure' => "Renueva el certificado YA. Copia el nuevo par de ficheros al directorio de\n"
                    ."certificados (TLS_CERT_DIR del fichero .env) y recarga el servidor web:\n"
                    ."  docker compose restart nginx\n"
                    ."Las tablets recuperaran solas su cola en cuanto vuelvan a conectar: no se pierde ningun\n"
                    .'fichaje.',
                'warning_no_url' => 'Revisa APP_URL en el fichero .env: tiene que ser una direccion completa, '
                    .'como https://fichaje.mihotel.local',
                'warning_not_https' => 'En produccion, APP_URL tiene que empezar por https://. Corrigelo en el '
                    .'fichero .env y reinicia con `docker compose up -d app`.',
                'warning_unreachable' => "Comprueba que el servidor web esta levantado:\n"
                    ."  docker compose ps nginx\n"
                    .'Si estas ejecutando esto durante una instalacion, es normal: vuelve a mirarlo al terminar.',
                'warning_unreadable' => 'Revisa los ficheros de certificado del directorio TLS_CERT_DIR: puede '
                    .'que el fichero este incompleto o no sea un certificado.',
                'warning_self_signed' => "Instala un certificado emitido por una autoridad en la que confien las\n"
                    ."tablets, o acepta el autofirmado poniendo en el fichero .env:\n"
                    ."  TLS_ALLOW_SELF_SIGNED=true\n"
                    .'En una red interna del hotel, lo segundo es una opcion razonable.',
            ],
        ],

        'permissions' => [
            'probe' => $probeFix,
            'storage' => [
                'failure' => "Devuelve esos directorios al usuario de la aplicacion. Desde el directorio de la\n"
                    ."instalacion:\n"
                    ."  docker compose exec -u root app chown -R www-data:www-data storage bootstrap/cache\n"
                    .'Suele pasar tras ejecutar un comando como root.',
            ],
            'backup_path' => [
                'failure' => "Da permiso de escritura al usuario de la aplicacion sobre :path y comprueba que el\n"
                    ."volumen no esta montado de solo lectura. Despues, lanza una copia manual para confirmar:\n"
                    .'  ./backup.sh',
                'failure_missing' => "Crea el directorio y dale permisos al usuario de la aplicacion:\n"
                    ."  sudo install -d -m 0750 :path\n"
                    ."Comprueba tambien que BACKUP_PATH del fichero .env apunta donde quieres. Despues:\n"
                    .'  ./backup.sh',
            ],
            'branding_root' => [
                'warning' => 'Comprueba que existe el directorio :path y que el usuario de la aplicacion puede '
                    .'leerlo. Si no usas logotipo propio, no hay nada que hacer.',
            ],
            'branding_logo' => [
                'warning' => 'Vuelve a subir el logotipo desde el panel, en Configuracion. El formato admitido '
                    .'es PNG o SVG.',
                'warning_unknown' => 'Vuelve a ejecutar este comando cuando la base de datos responda.',
            ],
        ],

        'disk' => [
            'probe' => $probeFix,
            'storage' => [
                'warning' => "Libera espacio antes de que sea urgente. Lo que mas ocupa suele ser el registro\n"
                    ."tecnico y las imagenes antiguas de Docker:\n"
                    ."  docker system prune -a\n"
                    .'Mira tambien si el directorio de copias esta en el mismo disco.',
                'failure' => "Libera espacio AHORA. Por orden de utilidad:\n"
                    ."  docker system prune -a\n"
                    ."  du -sh :path/*  |  sort -h  |  tail -20\n"
                    .'Si el disco se llena, la base de datos deja de aceptar escrituras y no se puede fichar.',
                'warning_missing' => 'Comprueba que la ruta :path existe y esta montada.',
                'warning_unknown' => 'Comprueba que la ruta :path esta montada y es accesible.',
            ],
            'backup' => [
                'warning' => "Revisa cuantos dias de copias estas guardando (BACKUP_RETENTION_DAYS del fichero\n"
                    .'.env) y si el disco de copias es lo bastante grande para ese plazo.',
                'failure' => "Amplia el disco de copias o reduce el plazo de conservacion\n"
                    ."(BACKUP_RETENTION_DAYS del fichero .env). NO borres copias a mano sin comprobar antes\n"
                    .'cuantas quedan: el minimo esta en BACKUP_MIN_COPIES.',
                'warning_missing' => 'Comprueba que la ruta :path existe y esta montada.',
                'warning_unknown' => 'Comprueba que la ruta :path esta montada y es accesible.',
            ],
        ],

        'app' => [
            'probe' => $probeFix,
            'timezone_utc' => [
                'failure' => "Pon en el fichero .env:\n"
                    ."  APP_TIMEZONE=UTC\n"
                    ."y reinicia la aplicacion:\n"
                    ."  docker compose up -d app\n"
                    ."Las pantallas seguiran enseñando la hora del hotel: la zona horaria del centro se\n"
                    .'configura en el panel, en Configuracion, y no aqui.',
            ],
            'debug_in_production' => [
                'failure' => "Pon en el fichero .env:\n"
                    ."  APP_DEBUG=false\n"
                    ."y reinicia la aplicacion:\n"
                    .'  docker compose up -d app',
            ],
        ],

        'settings' => [
            'probe' => $probeFix,
            'invalid_keys' => [
                'warning' => 'Entra en el panel, en Configuracion, y vuelve a guardar esos ajustes: :keys',
                'failure' => "Entra en el panel, en Configuracion, y vuelve a guardar esos ajustes: :keys\n"
                    ."Hasta que lo hagas, el sistema esta calculando las horas con el valor de fabrica.\n"
                    .'Comprueba despues que las horas de los ultimos dias son las que esperas.',
            ],
            'env_differs_from_db' => [
                'warning' => "No hay nada roto. Si el valor que quieres es el del fichero .env, cambialo en el\n"
                    ."panel, en Configuracion, que es lo que manda. Si el que quieres es el del panel, quita o\n"
                    .'corrige esas lineas del fichero .env para que no confundan a quien lo lea.',
            ],
        ],

        'license' => [
            'probe' => $probeFix,
            'state' => [
                'warning_plan_exceeded' => 'Habla con tu proveedor para ampliar el plan cuando te venga bien. '
                    .'No corre prisa y no bloquea nada.',
                'warning_expiring_soon' => "Pide la renovacion a tu proveedor. Cuando te llegue la clave nueva:\n"
                    ."  php artisan license:activate \"KQL1....\"\n"
                    .'o pegala en el panel, en Configuracion > Licencia.',
                'warning_expired' => "Pide la renovacion a tu proveedor y activa la clave nueva:\n"
                    ."  php artisan license:activate \"KQL1....\"\n"
                    .'Mientras tanto se sigue fichando y se puede exportar el registro con normalidad.',
                'warning_absent' => "Activa la clave que te entrego tu proveedor:\n"
                    ."  php artisan license:activate \"KQL1....\"\n"
                    .'Si no la encuentras, pidesela: es una cadena que empieza por KQL1.',
                'warning_not_yet_valid' => 'Nada. Las funciones accesorias se activan solas el dia que empieza '
                    .'la vigencia.',
                'warning_unverifiable' => "Ejecuta `php artisan license:show`, que explica el motivo exacto y que\n"
                    .'hacer con el. Si dice que la clave esta cortada, vuelve a copiarla entera y activala.',
            ],
            'white_label_without_plan' => [
                'warning' => 'Si quieres tu marca en las pantallas, habla con tu proveedor para incluirla en el '
                    .'plan. Si no, borra el nombre, el color y el logotipo en el panel, en Configuracion, para '
                    .'que este aviso deje de salir.',
            ],
        ],
    ],
];
