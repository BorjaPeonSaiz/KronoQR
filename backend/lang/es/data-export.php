<?php

declare(strict_types=1);

/*
 * El `README.md` que viaja DENTRO del ZIP de la exportacion integra (RF-PD-14,
 * RL-20, tarea 5.10).
 *
 * ## Para quien esta escrito
 *
 * Para alguien del hotel que abre este ZIP **dentro de dos años, sin KronoQR
 * delante y probablemente sin la persona que lo instalo**. Ese es el escenario
 * literal de RL-20: el cliente tiene que poder seguir cumpliendo su obligacion
 * de conservacion aunque la relacion comercial haya terminado.
 *
 * De ahi las cuatro reglas que sigue cada frase:
 *
 *   1. **Cada fichero y cada columna se explican.** Una carpeta con diecisiete
 *      CSV cuyas columnas nadie sabe interpretar no cumple RL-20. `status =
 *      superseded` o `clock_in_source = pin_fallback` no significan nada para
 *      nadie sin esto.
 *   2. **Nada se da por sabido del producto.** «Tramo», «jornada» y «cadena de
 *      auditoria» se explican en la frase que los nombra.
 *   3. **Los comandos son completos y copiables.** `sha256sum` con su fichero
 *      delante, no «calcula la huella».
 *   4. **Ninguna frase promete lo que el producto no hace.** Si una columna
 *      puede estar vacia, se dice.
 *
 * ## Como se compone
 *
 * `TranslatedDataExportGuide` recorre el catalogo `DataExportCatalog` y, para
 * cada conjunto, busca `files.<conjunto>.summary` y
 * `files.<conjunto>.columns.<columna>`. **Una columna sin traduccion sale con su
 * nombre tecnico y una nota**: preferible a un README que miente por omision, y
 * lo caza la prueba unitaria del catalogo.
 */

return [

    'title' => 'Exportacion integra de los datos de KronoQR',

    'intro' => <<<'TEXT'
        Este fichero contiene **todos** los datos de tu instalacion de KronoQR en formato
        abierto: un CSV por tabla, JSON para lo que es configuracion, y este documento.

        Es tuyo y no depende de nada. Puedes abrirlo con cualquier hoja de calculo,
        cargarlo en cualquier base de datos y conservarlo el tiempo que la ley te obligue,
        aunque ya no uses el producto. No necesitas KronoQR para leerlo, ni licencia, ni
        conexion con nadie.

        **Contiene datos personales de toda tu plantilla.** Guardalo donde guardas el resto
        de la informacion laboral y borralo cuando deje de hacerte falta: tu eres el
        responsable del tratamiento.
        TEXT,

    'generated_heading' => 'Esta exportacion',

    'generated' => <<<'TEXT'
        - **Generada:** :generated_at (UTC)
        - **Version del producto:** :product_version
        - **Version del formato de exportacion:** :schema_version
        - **Zona horaria del centro:** :timezone
        - **Pedida desde:** :requested_via
        - **Pedida por:** :requested_by
        - **Filas de datos en total:** :total_rows
        TEXT,

    'requested_via_panel' => 'el panel de gestion',
    'requested_via_console' => 'la consola del servidor (`php artisan product:export-all`)',
    'requested_by_nobody' => 'nadie con sesion abierta: se pidio desde la consola del servidor',

    'timezone_heading' => 'Las horas van en UTC',

    'timezone_body' => <<<'TEXT'
        **Todas** las columnas de fecha y hora de este ZIP estan en UTC, en formato
        ISO-8601 con microsegundos: `2026-09-08T10:15:00.123456Z`. La `Z` final significa
        «hora universal coordinada», no la hora del hotel.

        Tu centro esta en la zona **:timezone**. Para leer una hora en horario local hay que
        convertirla, y la conversion depende de la fecha: en España, en horario de verano se
        suman dos horas y en invierno una.

        Se guarda asi a proposito. Es la unica forma de que un turno de noche que empieza el
        sabado y termina el domingo, o la madrugada en la que el reloj se atrasa una hora y
        las 02:30 ocurren dos veces, tengan un valor sin ambiguedad. Una hora local no lo
        tiene.

        Ejemplos de conversion, con el fichero ya abierto:

        - **En una hoja de calculo:** si `2026-09-08T10:15:00.123456Z` esta en `A2`, la hora
          local en horario de verano español es
          `=FECHANUMERO(EXTRAE(A2;1;10))+HORANUMERO(EXTRAE(A2;12;8))+2/24`.
        - **En PostgreSQL:** `SELECT '2026-09-08T10:15:00Z'::timestamptz AT TIME ZONE ':timezone';`
        - **En la linea de comandos:** `TZ=':timezone' date -d '2026-09-08T10:15:00Z'`

        Las columnas que solo llevan una fecha (`work_date`, `hired_at`, `valid_from`) no
        son instantes y no se convierten: son el dia al que se atribuye la jornada o el
        contrato, ya en el calendario del centro.
        TEXT,

    'integrity_heading' => 'Comprobar que la copia esta completa',

    'integrity_body' => <<<'TEXT'
        `manifest.json` lleva, por cada fichero, cuantas filas de datos contiene y su huella
        SHA-256. Con eso se comprueba que nada se ha perdido ni alterado, sin abrir los
        ficheros y sin KronoQR.

        Desde el directorio donde has descomprimido el ZIP:

        ```
        sha256sum employees.csv shift_entries.csv audit_log.csv
        ```

        y se comparan las huellas con las de `manifest.json`. Si coinciden, el fichero es
        byte a byte el que salio del servidor.

        La huella del ZIP entero se ve al descargarlo (cabecera `X-Kronoqr-Export-Sha256`) y
        consta en el panel y en el registro de auditoria. Para comprobarla:

        ```
        sha256sum kronoqr-export-*.zip
        ```

        **`manifest.json` no se incluye a si mismo** en la lista de ficheros: no puede, su
        huella cambiaria al escribirla dentro.

        Los recuentos de `manifest.json` son filas de **datos**: no cuentan la primera linea
        de cada CSV, que lleva los nombres de las columnas.
        TEXT,

    'format_heading' => 'Formato de los ficheros',

    'format_body' => <<<'TEXT'
        - **CSV** — codificacion UTF-8 con marca de orden de bytes (para que Excel muestre
          bien las tildes), separador `:delimiter`, entrecomillado con `"` segun RFC 4180 y
          fin de linea `CRLF`. La primera linea son los nombres tecnicos de las columnas: no
          se traducen a proposito, para que un `COPY` o un `LOAD DATA` a otra base de datos
          siga funcionando aunque cambies el idioma del panel. Lo que explica cada columna es
          este documento.
        - **JSON** — una lista de objetos, siempre, aunque solo haya un elemento. Los valores
          escalares se entregan como texto y los documentos anidados (`settings`,
          `holiday_calendar`, `features`, `value`) como JSON de verdad.
        - **Celdas vacias** — significan «sin valor» (`NULL` en la base de datos), no cero ni
          cadena vacia.
        - **Una celda que empieza por `=`, `+`, `-` o `@`** lleva delante una comilla simple.
          Es la marca de texto de Excel y evita que una hoja de calculo interprete como
          formula el texto que escribio una persona.
        TEXT,

    'chain_heading' => 'Verificar la cadena de auditoria por tu cuenta',

    'chain_body' => <<<'TEXT'
        `audit_log.csv` es el registro de todo lo que ha ocurrido con relevancia legal en tu
        instalacion: quien corrigio que fichaje, cuando, con que motivo, quien entro, quien
        cambio un umbral. Solo se añaden filas: nunca se modifican ni se borran.

        Cada fila lleva su propia huella (`hash`) y la de la anterior (`prev_hash`), de modo
        que forman una cadena. Si alguien alterase una fila, su huella dejaria de cuadrar y
        todas las siguientes tambien. **Esto se puede comprobar fuera de KronoQR**, y aqui
        esta la formula exacta para hacerlo:

        ```
        hash = SHA256( prev_hash + RS + occurred_at + RS + actor + RS
                       + action + RS + subject + RS + payload_canonico + RS )
        ```

        donde:

        - `RS` es el byte separador de registro, `0x1E`. Va detras de cada componente.
        - `occurred_at` se escribe como `2026-09-08T10:15:00.123456+00:00` (con desfase
          `+00:00`, no con `Z`). En el CSV esa misma columna sale con `Z`: hay que
          sustituirla por `+00:00` antes de calcular.
        - `actor` es `actor_type + "#" + actor_id`, con `actor_id` vacio si no lo hay.
        - `subject` es `subject_type + "#" + subject_id`, con las mismas reglas.
        - `payload_canonico` es el JSON de `payload` con **las claves de cada objeto
          ordenadas alfabeticamente byte a byte**, sin espacios, sin escapar las barras ni
          los caracteres no ASCII. Un payload vacio es `{}`.
        - La primera fila de la instalacion usa como `prev_hash` el SHA-256 del texto
          `FICHAJE-HOTEL-GENESIS`.

        `audit_chain_anchors.csv` lleva, por cada año, la primera y la ultima huella y
        cuantas filas habia: sirve para comprobar la cadena de un año cerrado sin recorrerla
        entera.

        **`audit_log.csv` es el unico fichero de este ZIP que lleva identificadores internos**
        (`id`, `actor_id`, `subject_id`), y es a proposito: son los numeros que entraron en el
        calculo de la huella, asi que sustituirlos habria entregado un fichero imposible de
        verificar. Para que se pueda leer sin adivinar, va ademas la columna `actor_uuid`, que
        es el mismo actor identificado como en el resto de ficheros.
        TEXT,

    'files_heading' => 'Que hay en cada fichero',

    'file_heading' => ':file — :rows filas',

    'not_installed_heading' => 'Lo que esta version no registra',

    'not_installed_body' => <<<'TEXT'
        Los siguientes conjuntos **no existen** en la version :product_version del producto, y
        por eso no hay ningun fichero para ellos. No es que esten vacios: es que esta version
        no guarda esa informacion.

        :list
        TEXT,

    'not_installed_names' => [
        'absences' => 'Ausencias y permisos.',
        'error_events' => 'Historico de errores tecnicos de las aplicaciones.',
    ],

    'unknown_column' => '(sin descripcion en esta version; el nombre tecnico es «:column»)',

    'files' => [

        'site' => [
            'summary' => 'El centro de trabajo. Hay uno por instalacion.',
            'columns' => [
                'name' => 'Nombre del centro, tal como aparece en el panel y en los informes.',
                'timezone' => 'Zona horaria con la que se interpretan las horas del centro. Es el dato con el que se decide a que jornada pertenece cada fichaje.',
                'compliance_profile' => 'Perfil de cumplimiento en vigor: el conjunto de umbrales legales (descanso minimo, jornada maxima, retencion) con el que se detectan las incidencias.',
                'settings' => 'Ajustes historicos del centro. En las instalaciones actuales esta vacio: la configuracion vive en `installation_settings.json`.',
                'created_at' => 'Cuando se dio de alta el centro.',
            ],
        ],

        'departments' => [
            'summary' => 'Los departamentos del centro. Un empleado pertenece como mucho a uno.',
            'columns' => [
                'name' => 'Nombre del departamento. Es la referencia que usa la columna `department_name` de `employees.csv`.',
                'site_name' => 'Centro al que pertenece.',
                'manager_user_uuid' => 'Cuenta de gestion responsable del departamento, si la hay. Se corresponde con `user_uuid` de `users.csv`.',
            ],
        ],

        'employees' => [
            'summary' => 'La plantilla: todas las personas dadas de alta, incluidas las que ya causaron baja. Nadie se borra.',
            'columns' => [
                'employee_uuid' => 'Identificador de la persona. Es la referencia que usan los ficheros de fichajes, incidencias y credenciales.',
                'employee_code' => 'Codigo de empleado que la persona usa para entrar en su portal.',
                'first_name' => 'Nombre.',
                'last_name' => 'Apellidos.',
                'email' => 'Correo electronico. Es **opcional** en este producto: la mayoria de las filas puede estar vacia, y eso es normal.',
                'department_name' => 'Departamento al que pertenece. Vacio si no tiene ninguno.',
                'status' => 'Situacion: `active` (en alta), `suspended` (suspendida temporalmente) o `terminated` (baja).',
                'hired_at' => 'Fecha de alta.',
                'terminated_at' => 'Fecha de baja. Vacio si sigue en alta.',
                'locale' => 'Idioma en el que la persona ve el quiosco y su portal.',
                'pin_issued_at' => 'Cuando se emitio su PIN de respaldo. Vacio si nunca se le emitio.',
                'pin_delivered_at' => 'Cuando se le entrego ese PIN en mano.',
                'pin_delivered_by_user_uuid' => 'Quien se lo entrego.',
                'created_at' => 'Cuando se creo la ficha.',
                'updated_at' => 'Ultima modificacion de la ficha.',
            ],
        ],

        'employment_contracts' => [
            'summary' => 'Las condiciones de jornada contratadas, con su historia: cuando cambia una jornada se abre una fila nueva y la anterior se cierra. Es la cifra contra la que se miden las horas trabajadas.',
            'columns' => [
                'employee_uuid' => 'Persona a la que corresponde.',
                'weekly_hours' => 'Horas semanales contratadas.',
                'annual_hours' => 'Horas anuales, si el contrato las fija.',
                'schedule_type' => 'Tipo de jornada declarado.',
                'valid_from' => 'Desde cuando rige.',
                'valid_to' => 'Hasta cuando rigio. Vacio en el contrato vigente.',
                'created_at' => 'Cuando se registro.',
                'created_by_user_uuid' => 'Quien lo registro.',
            ],
        ],

        'credentials' => [
            'summary' => 'Las tarjetas QR emitidas, incluidas las revocadas. **No lleva el secreto de ninguna tarjeta**: sin el, ninguna de estas filas sirve para fichar.',
            'columns' => [
                'credential_uuid' => 'Identificador de la tarjeta.',
                'employee_uuid' => 'Persona a la que se emitio.',
                'key_id' => 'Identificador de la clave de firma con la que se emitio. Sirve para saber que tarjetas se vieron afectadas por una rotacion de clave.',
                'issued_at' => 'Cuando se emitio.',
                'printed_at' => 'Cuando se imprimio.',
                'delivered_at' => 'Cuando se entrego en mano a la persona.',
                'delivered_by_user_uuid' => 'Quien se la entrego.',
                'revoked_at' => 'Cuando se revoco. Vacio si sigue valida.',
                'revoked_reason' => 'Por que se revoco (perdida, baja, rotacion de clave).',
            ],
        ],

        'devices' => [
            'summary' => 'Las tablets-quiosco vinculadas. **No lleva el token de ninguna**: ninguna de estas filas sirve para autenticarse.',
            'columns' => [
                'device_uuid' => 'Identificador del quiosco. Es la referencia que usa `scan_events.csv`.',
                'name' => 'Nombre que le puso quien lo vinculo («Recepcion», «Cocina»).',
                'site_name' => 'Centro en el que esta.',
                'app_version' => 'Version de la aplicacion del quiosco en su ultima conexion.',
                'status' => 'Situacion del quiosco.',
                'pending_queue_size' => 'Fichajes que tenia sin enviar en su ultima conexion. Un numero alto y estable indica un quiosco sin red.',
                'paired_at' => 'Cuando se vinculo.',
                'last_seen_at' => 'Ultima señal de vida recibida.',
                'created_at' => 'Cuando se creo el registro.',
                'updated_at' => 'Ultima modificacion.',
            ],
        ],

        'shift_entries' => [
            'summary' => 'El registro horario: cada tramo trabajado, con su entrada y su salida. **Lleva todas las versiones**, incluidas las corregidas y las anuladas; un turno de noche es un unico tramo y se atribuye al dia en el que empezo.',
            'columns' => [
                'shift_entry_uuid' => 'Identificador del tramo.',
                'employee_uuid' => 'Persona que lo trabajo.',
                'site_name' => 'Centro.',
                'work_date' => 'Jornada a la que se atribuye. Un turno que empieza el sabado a las 22:00 y termina el domingo a las 06:00 pertenece **entero** al sabado.',
                'clocked_in_at' => 'Entrada (UTC).',
                'clocked_out_at' => 'Salida (UTC). Vacio si el tramo quedo abierto.',
                'duration_minutes' => 'Minutos trabajados. Vacio mientras el tramo este abierto.',
                'status' => 'Estado: `open` (sin salida), `closed` (cerrado y vigente), `superseded` (sustituido por una correccion posterior) o `voided` (anulado, no suma horas).',
                'clock_in_source' => 'Como se registro la entrada: `qr_kiosk` (tarjeta en el quiosco), `pin_fallback` (PIN, cuando la tarjeta no funciona), `manual` (correccion hecha desde el panel).',
                'clock_out_source' => 'Lo mismo para la salida.',
                'version' => 'Numero de version del tramo. La 1 es la original; cada correccion crea la siguiente.',
                'superseded_by_uuid' => 'Tramo que sustituyo a este. Vacio en la version vigente. Siguiendo esta columna se reconstruye la historia completa de una correccion.',
                'created_at' => 'Cuando se creo esta version.',
                'updated_at' => 'Ultima modificacion de esta version.',
            ],
        ],

        'shift_corrections' => [
            'summary' => 'Las correcciones del registro horario, con autor y motivo. Nada se sobrescribe: cada correccion deja aqui lo que habia antes y lo que quedo despues.',
            'columns' => [
                'shift_entry_uuid' => 'Tramo corregido.',
                'action' => 'Que se hizo: crear, modificar o anular.',
                'performed_by_user_uuid' => 'Cuenta que hizo la correccion.',
                'performed_by_name' => 'Nombre de esa persona, para poder leer el fichero sin cruzarlo con `users.csv`.',
                'reason_code' => 'Motivo, en codigo (olvido de fichar, error del quiosco, etc.).',
                'reason_text' => 'Motivo escrito a mano, si se escribio.',
                'before' => 'Como estaba el tramo antes, en JSON.',
                'after' => 'Como quedo despues, en JSON. Vacio si la correccion fue una anulacion.',
                'created_at' => 'Cuando se hizo.',
            ],
        ],

        'daily_totals' => [
            'summary' => 'Los totales por persona y jornada. **Es un calculo, no un dato original**: se obtiene entero de `shift_entries.csv` y el producto lo rehace desde cero cada vez que algo cambia. Esta aqui por comodidad; si alguna vez no cuadrara, manda el tramo.',
            'columns' => [
                'employee_uuid' => 'Persona.',
                'work_date' => 'Jornada.',
                'total_minutes' => 'Minutos trabajados ese dia, sin contar los tramos anulados.',
                'shift_count' => 'Cuantos tramos tuvo la jornada.',
                'first_in_at' => 'Primera entrada del dia (UTC).',
                'last_out_at' => 'Ultima salida del dia (UTC).',
                'has_open_shift' => 'Si quedo algun tramo sin cerrar.',
                'has_incident' => 'Si la jornada tiene alguna incidencia abierta.',
                'recalculated_at' => 'Cuando se recalculo por ultima vez.',
            ],
        ],

        'incidents' => [
            'summary' => 'Las incidencias detectadas sobre el registro horario: descanso insuficiente, jornada excesiva, turno sin cerrar, desfase de reloj. El sistema las abre y avisa; **nunca modifica un fichaje por su cuenta**.',
            'columns' => [
                'employee_uuid' => 'Persona afectada.',
                'work_date' => 'Jornada a la que se refiere.',
                'shift_entry_uuid' => 'Tramo concreto, si la incidencia recae sobre uno.',
                'type' => 'Tipo de incidencia.',
                'severity' => 'Gravedad.',
                'status' => 'Situacion: abierta o resuelta.',
                'detected_at' => 'Cuando se detecto.',
                'context' => 'Datos con los que se detecto, en JSON: los valores concretos que dispararon la deteccion.',
                'assigned_to_user_uuid' => 'A quien se asigno.',
                'notified_at' => 'Cuando se aviso.',
                'resolved_at' => 'Cuando se resolvio.',
                'resolved_by_user_uuid' => 'Quien la resolvio.',
                'resolution_note' => 'Que se hizo con ella.',
                'created_at' => 'Cuando se creo el registro.',
                'updated_at' => 'Ultima modificacion.',
            ],
        ],

        'scan_events' => [
            'summary' => 'Cada vez que alguien paso una tarjeta o tecleo un PIN en un quiosco, **incluidos los intentos rechazados**. Es la evidencia bruta del fichaje, anterior a cualquier calculo.',
            'columns' => [
                'scan_id' => 'Identificador del escaneo, generado por la tablet. Es lo que hace que reenviar un fichaje desde la cola no lo duplique.',
                'device_uuid' => 'Quiosco en el que ocurrio.',
                'employee_uuid' => 'Persona reconocida. **Vacio en los intentos rechazados**: si la tarjeta no valia, no hay persona a la que atribuirlos.',
                'occurred_at' => 'Cuando ocurrio de verdad, segun el quiosco (UTC). Es el momento con valor legal.',
                'recorded_at' => 'Cuando llego al servidor (UTC). Puede ser horas despues si el quiosco estuvo sin red.',
                'origin' => 'Por donde entro: tarjeta en el quiosco, PIN de respaldo o sincronizacion de la cola.',
                'intent' => 'Lo que se pidio: entrada, salida o automatico (que el sistema decida por el estado de la persona).',
                'result' => 'Que paso: entrada registrada, salida registrada, rechazado, duplicado.',
                'shift_entry_uuid' => 'Tramo que se creo o se cerro con este escaneo, si lo hubo.',
                'worked_minutes' => 'Minutos que cerro este escaneo, cuando fue una salida.',
                'clock_skew_seconds' => 'Desfase entre el reloj de la tablet y el del servidor. Un desfase grande genera incidencia, pero **nunca rechaza el fichaje**.',
                'flagged_for_review' => 'Si quedo marcado para revision humana.',
                'client_meta' => 'Lo que la tablet informo de si misma en ese momento, en JSON.',
            ],
        ],

        'audit_log' => [
            'summary' => 'El registro de auditoria completo, con su cadena de huellas. Ver el apartado «Verificar la cadena de auditoria por tu cuenta» de este documento.',
            'columns' => [
                'id' => 'Numero de orden del asiento. Entra en el calculo de la huella.',
                'occurred_at' => 'Cuando ocurrio el hecho (UTC).',
                'actor_type' => 'Que tipo de actor lo hizo: `user` (cuenta de gestion), `device` (quiosco), `support_grant` (acceso temporal concedido al fabricante), `system` (una tarea programada) o `maintenance`.',
                'actor_id' => 'Identificador interno del actor. Entra en el calculo de la huella; para leerlo, usa `actor_uuid`.',
                'actor_uuid' => 'El mismo actor identificado como en el resto de ficheros: cruza con `users.csv`, `devices.csv` o `support_grants.csv`. Vacio para `system` y `maintenance`.',
                'action' => 'Que se hizo, en `sujeto.hecho` (`shift_entry.corrected`, `license.activated`, `data_export.downloaded`).',
                'subject_type' => 'Sobre que recayo.',
                'subject_id' => 'Identificador interno de lo que recayo. Entra en el calculo de la huella.',
                'payload' => 'Detalle del hecho, en JSON. **Nunca lleva nombres ni correos**: el registro de auditoria se guarda al minimo a proposito.',
                'prev_hash' => 'Huella del asiento anterior.',
                'hash' => 'Huella de este asiento.',
                'ip' => 'Direccion desde la que se hizo, si fue por la red.',
                'user_agent' => 'Navegador o aplicacion desde la que se hizo.',
            ],
        ],

        'audit_chain_anchors' => [
            'summary' => 'Los sellos anuales de la cadena de auditoria: permiten comprobar un año cerrado sin recorrerlo entero.',
            'columns' => [
                'partition_year' => 'Año sellado.',
                'first_hash' => 'Huella del primer asiento del año.',
                'last_hash' => 'Huella del ultimo.',
                'row_count' => 'Cuantos asientos habia.',
                'sealed_at' => 'Cuando se sello.',
                'sealed_by' => 'Que proceso lo sello.',
            ],
        ],

        'users' => [
            'summary' => 'Las cuentas de gestion: quien puede entrar al panel y con que permisos. **No lleva ninguna contraseña ni ningun secreto de segundo factor.**',
            'columns' => [
                'user_uuid' => 'Identificador de la cuenta. Es la referencia que usan el resto de ficheros.',
                'name' => 'Nombre de la persona.',
                'email' => 'Correo con el que entra al panel.',
                'roles' => 'Roles que tenia, separados por espacio. Es lo que decidia que podia hacer.',
                'locale' => 'Idioma en el que veia el panel.',
                'is_active' => 'Si la cuenta seguia activa. Las cuentas no se borran: se desactivan.',
                'two_factor_enabled' => 'Si tenia el segundo factor configurado. El secreto **no** viaja aqui.',
                'last_login_at' => 'Ultimo acceso.',
                'created_at' => 'Cuando se creo.',
                'updated_at' => 'Ultima modificacion.',
            ],
        ],

        'support_grants' => [
            'summary' => 'Los accesos temporales que concediste al fabricante para resolver una incidencia. **No lleva ningun token.** Si esta vacio, el fabricante nunca ha entrado en tu instalacion.',
            'columns' => [
                'support_grant_uuid' => 'Identificador de la concesion.',
                'granted_by_user_uuid' => 'Quien la autorizo.',
                'reason' => 'Para que incidencia se concedio.',
                'scope' => 'Hasta donde llegaba: `diagnostics` (solo generar el paquete de diagnostico), `read_only` (leer) o `configuration` (leer y cambiar ajustes).',
                'granted_at' => 'Cuando se concedio.',
                'expires_at' => 'Cuando dejaba de valer, sin que nadie hiciera nada.',
                'revoked_at' => 'Cuando se revoco antes de tiempo, si se revoco.',
                'revoked_by_user_uuid' => 'Quien la revoco. Vacio si la revoco la consola del servidor.',
                'accessed_at' => 'Ultima vez que se uso. Vacio si se concedio y nunca hizo falta.',
            ],
        ],

        'installation_settings' => [
            'summary' => 'La configuracion de la instalacion tal como la dejaste desde el panel: idiomas, marca, umbrales operativos.',
            'columns' => [
                'key' => 'Nombre del ajuste.',
                'value' => 'Su valor, como documento JSON.',
                'updated_at' => 'Cuando se cambio por ultima vez.',
                'updated_by_user_uuid' => 'Quien lo cambio.',
            ],
        ],

        'compliance_profiles' => [
            'summary' => 'Los umbrales legales con los que se detectan las incidencias y se decide cuanto tiempo se conservan los datos.',
            'columns' => [
                'name' => 'Nombre del perfil.',
                'jurisdiction' => 'Ambito al que corresponde.',
                'retention_years' => 'Años que se conserva el registro de jornada.',
                'min_rest_hours' => 'Descanso minimo entre jornadas, en horas.',
                'max_daily_hours' => 'Jornada maxima diaria, en horas.',
                'max_weekly_hours' => 'Jornada maxima semanal, en horas.',
                'break_required_after_hours' => 'A partir de cuantas horas seguidas hace falta una pausa.',
                'week_starts_on' => 'Dia en el que empieza la semana a efectos de computo.',
                'holiday_calendar' => 'Calendario de festivos, en JSON.',
                'is_default' => 'Si es el perfil en vigor.',
                'updated_at' => 'Cuando se cambio por ultima vez.',
                'updated_by_user_uuid' => 'Quien lo cambio.',
            ],
        ],

        'license' => [
            'summary' => 'La licencia activada. **No lleva la clave firmada**, que es del fabricante y ya la tienes en tu correo de activacion; si lleva todo lo que la clave dice.',
            'columns' => [
                'license_id' => 'Identificador de la licencia.',
                'customer_name' => 'Razon social a la que se emitio.',
                'plan' => 'Plan contratado.',
                'max_employees' => 'Maximo de empleados que cubre.',
                'max_devices' => 'Maximo de quioscos que cubre.',
                'features' => 'Funcionalidades accesorias incluidas, en JSON.',
                'valid_from' => 'Desde cuando vale.',
                'valid_until' => 'Hasta cuando vale. **Que esta fecha haya pasado no impide fichar ni consultar el registro**: solo se desactivan funcionalidades accesorias.',
                'issued_at' => 'Cuando se emitio.',
                'activated_at' => 'Cuando se activo en esta instalacion.',
                'activated_by_user_uuid' => 'Quien la activo.',
                'last_verified_at' => 'Ultima comprobacion de la firma. Se hace en el servidor, sin salir a internet.',
            ],
        ],
    ],
];
