<?php

declare(strict_types=1);

/*
 * La salida de las ordenes de consola que lee el operador del hotel.
 *
 * Una seccion por orden, con el nombre de la orden en minusculas y guiones
 * bajos. Las ordenes la piden por `SpeaksInstallationLanguage`, en el idioma de
 * la instalacion (ADR-017) y no en el `APP_LOCALE` del instalador.
 *
 * Lo que NO va aqui: las lineas `clave=valor` que leen los scripts de
 * operacion (`update.sh` busca `revoked_credentials=` por patron) y los JSON de
 * `--json`. Esas no se traducen nunca: un script no habla idiomas.
 */

return [

    'license_show' => [
        'title' => 'Licencia de KronoQR',
        'unknown' => [
            'state' => 'Estado: DESCONOCIDO (la base de datos no responde; el sistema sigue funcionando)',
            'advice' => [
                'No significa que no haya licencia. No la reactives: arregla primero la base de datos',
                '(`docker compose exec app php artisan product:doctor`) y vuelve a mirar.',
            ],
        ],
        'states' => [
            'valid' => 'VIGENTE',
            'expiring_soon' => 'VIGENTE, caduca pronto',
            'expired' => 'CADUCADA (el sistema sigue funcionando)',
            'absent' => 'SIN LICENCIA (el sistema sigue funcionando)',
            'not_yet_valid' => 'TODAVIA NO VIGENTE',
            'unverifiable' => 'NO SE PUEDE VERIFICAR (el sistema sigue funcionando)',
        ],
        'summary' => [
            'state' => 'Estado: :state',
            'customer' => 'Cliente: :customer',
            'plan' => 'Plan:    :plan',
            'validity' => 'Vigencia: del :from al :until (UTC)',
            'expired_ago' => 'Caduco hace :days dia(s).',
            'remaining' => 'Le quedan :days dia(s). Aviso a partir de los :warning dias.',
            'activated' => 'Clave activada el :day, huella :fingerprint',
            'last_verified' => 'Ultima verificacion correcta: :day',
        ],
        'plan' => [
            'title' => 'Plan contratado frente a uso real',
            'row' => 'contratado: :contracted  real: :actual :exceeded',
            'exceeded' => '  ← SUPERADO en :excess',
            'not_blocking' => [
                'El exceso NO ha impedido ningun alta ni ningun emparejamiento, y no lo hara.',
                'Queda constancia en el registro de auditoria con la fecha exacta; hablalo con',
                'el proveedor para ampliar el plan.',
            ],
        ],
        'limits' => [
            'max_employees' => 'Personas en plantilla',
            'max_devices' => 'Quioscos activos',
        ],
        'degradation' => [
            'title' => 'Funcionalidades accesorias',
            'none' => 'Todo lo contratado esta disponible.',
            'pending' => [
                '(Otras :count funcionalidad(es) del catalogo todavia no existen en esta',
                ' version, asi que no se pierde nada con ellas.)',
            ],
        ],
        'features' => [
            'advanced_reports' => 'Informes por periodo y comparativa con lo contratado',
            'impact_dashboard' => 'Cuadro de impacto y adopcion',
            'payroll_export' => 'Exportacion para nomina',
            'weekly_email_summary' => 'Resumen semanal por correo',
            'realtime_presence' => 'Presencia en tiempo real (pasa a actualizarse por sondeo)',
            'white_label' => 'Marca propia (vuelve a la marca del producto)',
            'telemetry' => 'Telemetria opcional',
        ],
        'restrictions' => [
            'since' => ' (desde el :day)',
            'license_expired' => 'no disponible por licencia caducada:since',
            'license_absent' => 'no disponible: no hay licencia activada',
            'license_unverifiable' => 'no disponible: la licencia no se puede verificar',
            'license_not_yet_valid' => 'no disponible hasta que empiece la vigencia:since',
            'not_in_plan' => 'no incluida en el plan contratado',
            'other' => 'no disponible',
        ],
        'never_degraded' => [
            'title' => 'Lo que NUNCA depende de la licencia',
            'body' => [
                'Fichaje por QR y por PIN, sincronizacion de la cola del quiosco, consulta de',
                'jornadas y tramos, portal del empleado, exportacion para la Inspeccion,',
                'correcciones con su motivo, registro de auditoria, copias de seguridad y su',
                'restauracion, y las sondas de salud.',
                'Funcionan con la licencia caducada, ausente o ilegible. Es una promesa del',
                'producto, no una casualidad de esta version.',
            ],
        ],
        'what_to_do' => [
            'title' => 'Que hacer',
            'valid' => ['Nada. Vuelve a mirar cuando se acerque la fecha de caducidad.'],
            'expiring_soon' => [
                'Pide la renovacion al proveedor. Cuando te llegue la clave nueva:',
                '    php artisan license:activate "KQL1...."',
                'o pegala en el panel, en Configuracion > Licencia.',
            ],
            'expired' => [
                'Pide la renovacion al proveedor y activa la clave nueva:',
                '    php artisan license:activate "KQL1...."',
                'Mientras tanto se sigue fichando y se puede exportar el registro con normalidad.',
            ],
            'absent' => [
                'Activa la clave que te entrego el proveedor:',
                '    php artisan license:activate "KQL1...."',
                'Si no la encuentras, pidesela: es una cadena que empieza por KQL1.',
            ],
            'not_yet_valid' => [
                'La clave es correcta pero su vigencia empieza mas adelante. No hay que hacer nada:',
                'las funcionalidades accesorias se activan solas ese dia.',
            ],
            'exceeded' => [
                'Ademas, estas por encima de alguna cifra del plan. No corre prisa y no bloquea',
                'nada: contacta con el proveedor para ampliarlo cuando te venga bien.',
            ],
        ],
        'unverifiable' => [
            'malformed' => [
                'La clave guardada esta incompleta o cortada. Suele pasar al copiarla de un correo.',
                'Vuelve a copiarla entera —empieza por KQL1. y no lleva espacios— y activala otra vez.',
            ],
            'bad_signature' => [
                'La clave guardada no la emitio el fabricante de esta version, o se ha modificado.',
                'Pide una clave nueva al proveedor y activala.',
            ],
            'invalid_payload' => [
                'La clave esta firmada pero le falta informacion. Es un fallo de emision, no tuyo:',
                'avisa al proveedor con la huella que aparece arriba y pide una clave nueva.',
            ],
            'no_public_key' => [
                'Esta instalacion no lleva la clave publica del fabricante, asi que no puede verificar',
                'ninguna licencia. No es un problema de tu clave: es del despliegue.',
                'Avisa al proveedor indicando la version que devuelve GET /api/v1/health.',
            ],
            'other' => ['Avisa al proveedor: la licencia guardada no se puede verificar.'],
        ],
    ],

    'license_activate' => [
        'no_key' => [
            'title' => 'No has indicado ninguna clave y LICENSE_KEY esta vacia en el entorno.',
            'advice' => [
                'Uso:  php artisan license:activate "KQL1...."',
                'La clave te la entrega el proveedor: es una cadena que empieza por KQL1.',
            ],
        ],
        'rejected' => [
            'title' => 'La clave NO se ha activado. La licencia anterior sigue como estaba.',
            'malformed' => [
                'La clave esta incompleta o cortada. Suele pasar al copiarla de un correo.',
                'Copiala entera: empieza por KQL1. y no lleva espacios ni saltos de linea.',
            ],
            'bad_signature' => [
                'La firma no cuadra: la clave no la emitio el fabricante de esta version, o se ha',
                'modificado. Pide una clave nueva al proveedor.',
            ],
            'invalid_payload' => [
                'La clave esta firmada pero su contenido no sirve. Es un fallo de emision:',
                'avisa al proveedor y pide una clave nueva.',
            ],
            'no_public_key' => [
                'Esta instalacion no lleva la clave publica del fabricante, asi que no puede',
                'verificar ninguna licencia. No es un problema de tu clave, es del despliegue:',
                'avisa al proveedor indicando la version que devuelve GET /api/v1/health.',
            ],
            'clocking_unaffected' => 'Nada de esto afecta al fichaje ni al acceso al registro: siguen funcionando.',
        ],
        'invalid' => [
            'title' => 'La clave esta firmada pero le falta informacion. Es un fallo de emision.',
            'detail' => 'Detalle tecnico para el proveedor: :detail',
        ],
        'activated' => [
            'title' => 'Licencia activada.',
            'customer' => 'Cliente: :customer',
            'plan' => 'Plan:    :plan',
            'state' => 'Estado:  :state',
            'next' => 'Ejecuta  php artisan license:show  para ver el detalle completo.',
            'already_expired' => [
                'Ojo: esta clave YA ESTA CADUCADA. Se ha guardado igualmente y consta en el',
                'registro de auditoria. Las funcionalidades accesorias siguen degradadas.',
            ],
            'not_yet_valid' => [
                'Ojo: la vigencia de esta clave empieza mas adelante. Se ha guardado y se',
                'activara sola ese dia.',
            ],
        ],
    ],

    'product_diagnostics' => [
        'deliver' => [
            'not_deferred' => [
                'No se ha generado el paquete: la base de datos no responde y no se ha podido',
                'guardar la constancia de su generacion para escribirla en la auditoria despues.',
            ],
            'not_written' => 'No se ha podido escribir el paquete.',
        ],
        'not_audited' => [
            'personal_data_without_database' => [
                'No se ha generado el paquete CON DATOS PERSONALES: la base de datos no responde y',
                'ese paquete no se genera porque no se puede auditar (RL-19). No se ha escrito nada.',
            ],
            'personal_data_alternative' => [
                'Si necesitas el paquete ya, genera el anonimizado, que si se puede generar sin base:',
                '  php artisan product:diagnostics',
                'Cuando la base vuelva, repite este comando si sigues necesitando los datos personales.',
            ],
            'other' => [
                'No se ha generado el paquete: no se ha podido dejar constancia de su generacion en',
                'la auditoria (error :error). No se ha escrito nada.',
                'Ejecuta php artisan product:doctor y revisa las comprobaciones database.*; si todas',
                'estan en verde, avisa a soporte con esa salida.',
            ],
        ],
        'pending_recorded' => [
            'Se han escrito en la auditoria :count asiento(s) pendiente(s) de paquetes',
            'generados mientras la base de datos no respondia.',
        ],
        'period_out_of_range' => [
            'El periodo tiene que estar entre 1 y :maximum dias; has pedido :days.',
            'Vuelve a ejecutarlo con, por ejemplo: php artisan product:diagnostics --with-personal-data --period-days=7',
        ],
        'confirm_personal_data' => '¿Seguro que quieres incluir los datos personales?',
        'personal_data_warning' => [
            'title' => 'ATENCION: vas a generar un paquete CON DATOS PERSONALES.',
            'contents' => [
                'Ademas de la informacion tecnica de siempre, el fichero incluira:',
                '  - La plantilla: nombre, codigo de empleado, estado y departamento.',
                '  - Los fichajes y los tramos de los ultimos :days dias.',
                '  - Las incidencias abiertas.',
            ],
            'implications' => [
                'Que implica:',
                '  - El fichero deja de estar anonimizado y no se debe enviar sin necesidad.',
                '  - La accion queda registrada en el registro de auditoria, firmada por la cuenta',
                '    de --responsible.',
                '  - Si se lo envias al proveedor, este actua como encargado del tratamiento',
                '    para ese supuesto (RGPD art. 28). Necesitas el contrato de encargo firmado.',
            ],
            'alternative' => [
                'Si solo quieres diagnosticar un problema tecnico, no hace falta: ejecuta',
                '  php artisan product:diagnostics',
            ],
        ],
        'anonymous' => [
            'yes' => 'si',
            'no' => 'NO — lleva datos personales',
        ],
        'report' => [
            'purged' => [
                'Se han borrado :count paquete(s) anterior(es) por antiguedad. Un paquete de',
                'diagnostico es material caducado en cuanto se envia, y el que lleva datos',
                'personales no debe quedarse en el disco (RL-19).',
            ],
            'title' => 'Paquete de diagnostico generado.',
            'file' => '  Fichero:  :path',
            'size' => '  Tamaño:   :size KB',
            'digest' => '  Huella:   :digest',
            'sections' => '  Contiene: :sections',
            'anonymous' => '  Anonimo:  :answer',
            'entry_pending' => [
                'La base de datos no responde: el asiento de este paquete en la auditoria queda',
                'PENDIENTE y se escribira solo en cuanto la base vuelva (no tienes que hacer nada).',
                'El propio paquete lo dice en audit.generation_entry, dentro de su huella.',
                'Las secciones que dependen de la base salen como "unavailable".',
            ],
            'check_before_sending' => [
                'Antes de enviarlo, abrelo y comprueba que no lleva nada que no quieras enviar:',
                '  less :path',
            ],
            'verify_hint' => [
                'Quien lo reciba puede comprobar que no se ha alterado por el camino con:',
                '  php artisan product:diagnostics --verify=:path',
            ],
            // En los dos idiomas a proposito, tambien en este catalogo: quien lo
            // lee puede ser el IT del hotel o el soporte del fabricante.
            'anonymized_scope' => [
                'Anonimizado: sin nombres, documentos ni identificadores de empleado; el texto',
                'de los errores, solo con palabras técnicas. Ábrelo antes de enviarlo.',
                'Anonymised: no names, documents or employee identifiers; error text reduced',
                'to technical words. Open it before sending.',
            ],
        ],
        'verify' => [
            'unreadable' => [
                'No se ha podido leer un paquete de diagnostico en :path.',
                'Comprueba que la ruta existe, que es legible y que el fichero es el JSON completo:',
                'un fichero cortado por el correo no se puede verificar.',
            ],
            'mismatch' => [
                'title' => 'LA HUELLA NO COINCIDE: :path',
                'advice' => [
                    'El fichero no es el que se genero, o le falta parte. La causa mas frecuente es',
                    'que se corto al enviarlo por correo. Pide al cliente que lo vuelva a generar y',
                    'lo envie comprimido, o por el portal de tickets.',
                ],
            ],
            'intact' => 'Paquete integro: :path',
            'version' => '  Version:   :version',
            'generated' => '  Generado:  :at por :by',
            'anonymous' => '  Anonimo:   :answer',
            'digest' => '  Huella:    :digest',
            'without_manifest' => [
                '             huella sin manifiesto: cubre las secciones, no la cabecera. Que diga',
                '             «anonimo» no esta comprobado; mira las secciones antes de darlo por anonimo.',
            ],
            'entry' => '  Asiento:   :entry',
            'entry_pending' => 'PENDIENTE: la base de datos no respondia al generarlo; se escribe solo al volver',
            'entry_written' => 'escrito en la auditoria al generarlo',
        ],
    ],

    'product_diagnostics_record_pending' => [
        'recorded' => 'Asientos de diagnostico pendientes escritos: :count.',
        'remaining' => 'Asientos de diagnostico que siguen pendientes: :count (error :error).',
        'unknown_error' => 'desconocido',
        'retry' => [
            'Se reintentara en la siguiente pasada. Si la base de datos responde y esto se repite,',
            'ejecuta php artisan product:doctor y revisa las comprobaciones database.*.',
        ],
    ],

    'product_export_all' => [
        'unidentified' => 'sin identificar',
        'in_progress' => [
            'Ya hay una exportacion integra en curso (:uuid).',
            'Espera a que termine y vuelve a ejecutarlo, o miralas con:',
            '  php artisan tinker --execute="dump(DB::table(\'data_exports\')->latest(\'id\')->first());"',
        ],
        'generating' => 'Generando la exportacion integra. Recorre todas las tablas: puede tardar varios minutos.',
        'failed' => [
            'No se pudo generar la exportacion integra: :error.',
            'La causa mas frecuente es falta de espacio en disco o permisos del directorio de',
            'exportaciones. Compruebalos con «df -h» y «ls -ld» sobre PRODUCT_DATA_EXPORT_PATH,',
            'y ejecuta «php artisan product:doctor» para ver el resto del estado de la instalacion.',
        ],
        'without_file' => 'La exportacion termino sin fichero. Revisa el estado con «php artisan product:doctor».',
        'report' => [
            'title' => 'Exportacion integra generada.',
            'file' => '  Fichero:    :path',
            'size' => '  Tamaño:     :size',
            'digest' => '  SHA-256:    :digest',
            'uuid' => '  Identificador: :uuid',
            'expires' => '  Caduca:     :at UTC',
            'rows' => 'Filas por fichero:',
            'copy_out' => [
                'Para sacarlo del contenedor, desde el directorio de la instalacion y en el servidor:',
                '  docker compose cp app::path ./:name',
            ],
            'check' => [
                'Para comprobar que llego entero:',
                '  sha256sum ./:name',
            ],
            'personal_data' => [
                'El fichero contiene TODOS los datos personales de la plantilla. Guardalo donde guardas',
                'el resto de la informacion laboral. Aqui se borra solo al caducar.',
            ],
        ],
    ],

    'support_grant' => [
        'unknown_scope' => 'El alcance «:scope» no existe.',
        'valid_scopes' => 'Alcances validos: :scopes.',
        'not_granted' => 'No se ha concedido nada.',
        'usage' => 'Uso:  php artisan support:grant --reason="Incidencia #123" --hours=24',
        'granted' => [
            'title' => 'Acceso de soporte concedido.',
            'grant' => 'Concesion: :uuid',
            'scope' => 'Alcance:   :scope',
            'author' => 'A nombre de: :name',
            'expires' => 'Caduca:    :at (UTC)',
            'shown_once' => [
                'ESTE TOKEN SE MUESTRA UNA SOLA VEZ. No se guarda en ningun sitio y no se',
                'puede volver a pedir. Si lo pierdes, revoca esta concesion y crea otra.',
            ],
            'delivery' => [
                'Entregalo por el canal de soporte de tu contrato. Para retirarlo antes de',
                'tiempo:  php artisan support:revoke :uuid',
                'La concesion, su uso y su revocacion quedan en el registro de auditoria.',
            ],
        ],
        'author' => [
            'not_found' => 'No hay ninguna cuenta de gestion activa con el correo «:email».',
            'ambiguous' => [
                'title' => 'Hay varias cuentas de gestion (o ninguna) y hay que decir a nombre de cual se concede.',
                'why' => [
                    'Autorizar la entrada del fabricante es un acto de una persona concreta, y eso',
                    'queda escrito en el registro de auditoria.',
                ],
                'usage' => 'Uso:  php artisan support:grant --reason="..." --as=persona@hotel.example',
            ],
            'sole' => 'Se concede a nombre de la unica cuenta de gestion activa: :name.',
        ],
    ],

    'support_revoke' => [
        'missing' => [
            'title' => 'Indica la concesion que quieres revocar, o usa --all para revocarlas todas.',
            'usage' => [
                'Uso:  php artisan support:revoke 0199f6a2-4c1e-7d3b-8a90-1b2c3d4e5f60',
                '      php artisan support:revoke --all',
            ],
        ],
        'none_active' => 'No hay ningun acceso de soporte activo. Nada que revocar.',
        'all' => [
            'one' => 'Revocado: :uuid  (:scope)',
            'title' => ':count acceso(s) de soporte revocado(s). Sus tokens dejan de valer ya.',
            'kept' => 'Las concesiones se conservan con su fecha de revocacion, y consta en auditoria.',
        ],
        'one' => [
            'revoked' => 'Acceso revocado. Su token deja de valer en la peticion siguiente.',
            'kept' => 'La concesion se conserva con su fecha de revocacion (nada se borra).',
            'already_revoked' => 'Esa concesion ya estaba revocada. No habia nada que hacer.',
            'not_found' => 'No hay ninguna concesion con el identificador «:uuid».',
            'where' => 'Los identificadores de las concesiones activas los da el panel, en Soporte.',
        ],
    ],

    'identity_accounts' => [
        'temporary_password' => 'Es una contrasena TEMPORAL: caduca el :expires UTC y su titular tendra que '
            .'cambiarla al entrar. Anotala ahora: no se puede volver a consultar. Se entrega en mano, nunca por '
            .'correo ni por mensajeria.',
        'create' => [
            'ask_name' => 'Nombre',
            'ask_email' => 'Correo',
            'ask_role' => 'Rol',
            'email_taken' => 'Ya hay una cuenta de gestion con ese correo, activa o dada de baja.',
            'created' => 'Cuenta de gestion creada con el rol :role y UUID :uuid.',
        ],
        'deactivate' => [
            'not_found' => 'No existe ninguna cuenta de gestion con ese correo.',
            'already_inactive' => 'Esa cuenta ya estaba dada de baja. No se ha cambiado nada ni se ha escrito ningun asiento.',
            'own_account' => 'No se puede dar de baja la propia cuenta.',
            'last_admin' => 'Es la ultima cuenta admin activa de la instalacion. Crea otra con identity:create-user '
                .'antes de dar de baja esta. No se ha cambiado nada.',
            'done' => 'Cuenta dada de baja. Sus sesiones abiertas han dejado de valer y no podra volver a entrar.',
            'not_deleted' => 'La cuenta NO se ha borrado: su historial y sus asientos siguen ahi, y sigue contando '
                .'para que el alta publica del primer administrador continue cerrada.',
        ],
        'list' => [
            'invalid_filter' => 'Filtro no valido. --status: active o deactivated; --role: un rol de gestion.',
            'columns' => ['UUID', 'Correo', 'Rol', 'Estado', '2FA', 'Contrasena', 'Ultimo acceso (UTC)'],
            'yes' => 'si',
            'no' => 'no',
            'total' => ':count cuenta(s).',
        ],
        'reset_password' => [
            'not_found' => 'No existe ninguna cuenta de gestion activa con ese correo.',
            'done' => 'Contrasena restablecida. Las sesiones abiertas de esa cuenta han dejado de valer.',
        ],
        'reset_two_factor' => [
            'not_enrolled' => 'Esa cuenta no tenia segundo factor activo: no hay nada que retirar. No se ha escrito ningun asiento.',
            'not_found' => 'No existe ninguna cuenta de gestion activa con ese UUID.',
            'done' => 'Segundo factor retirado. La cuenta :uuid tendra que darlo de alta en su proximo acceso.',
        ],
    ],

    'identity' => [
        'origin_unlock' => [
            'invalid' => 'No es una direccion IPv4 ni IPv6.',
            'unlocked' => 'Bloqueo y cuenta de fallos de ese origen borrados. Queda constancia en el registro de auditoria.',
            'nothing' => 'Ese origen no tenia bloqueo ni fallos pendientes. Queda constancia en el registro de auditoria.',
        ],
        'dormant' => ':count cuenta(s) de gestion activa(s) sin entrar en mas de :days dias:roles.',
    ],

    'credentials' => [
        'employee_required' => 'Hace falta el UUID del empleado.',
        'employee_not_found' => 'No hay ningun empleado con ese UUID.',
        'credential_required' => 'Hace falta el UUID de la credencial.',
        'credential_not_found' => 'No hay ninguna credencial con ese UUID.',
        'issue' => [
            'use_reissue' => 'Usa --reissue --reason="motivo" para sustituirla.',
            'issued' => 'Credencial emitida: :uuid',
            'reissued' => 'Credencial reemitida: :uuid',
            'pending' => 'Estado: pendiente de imprimir. Nadie puede fichar con ella todavia.',
            'next' => 'El QR se acuña al imprimir: php artisan credentials:print :employee',
        ],
        'revoke' => [
            'reason_required' => 'Una revocacion tiene que declarar su motivo: --reason="perdida".',
            'revoked' => 'Credencial revocada: :uuid',
            'reason' => 'Motivo: :reason',
        ],
        'deliver' => [
            'delivered' => 'Entrega registrada: :uuid',
            'responsible' => 'Responsable: :email',
            'moment' => 'Momento: :at',
            'reminder' => 'Recuerda entregar tambien el PIN del portal y la hoja de instrucciones: docs/runbooks/alta-nuevo-empleado.md',
            'by_retired' => '--by se retiro en la 2.3.0: la entrega la firma la cuenta de --responsible (su uuid o su correo), que confirma quien es. No se ha registrado nada.',
            'confirm' => '¿Registrar la entrega de la tarjeta :uuid con esta firma?',
        ],
        'print' => [
            'out_required' => 'Hace falta --out=/ruta/credencial.pdf: este comando no elige donde dejar un PDF de tarjeta.',
            'no_reprint' => 'No hay reimpresion (ADR-034). Para reponer una tarjeta: revocar, reemitir e imprimir la nueva.',
            'no_active_credential' => 'Ese empleado no existe o no tiene ninguna credencial activa.',
            'issue_first' => 'Emitela primero: php artisan credentials:issue :employee',
            'not_written' => 'El PDF se genero pero no se ha podido escribir en «:path».',
            'printed_one' => 'La credencial consta IMPRESA y su token es irrecuperable.',
            'runbook' => 'Sigue docs/runbooks/tarjeta-perdida-o-rota.md: revocar con motivo «impresion fallida» y reemitir.',
            'generated' => 'Tarjeta generada: :path',
            'credential' => 'Credencial: :uuid',
            'handle_with_care' => 'Imprime, plastifica y BORRA el fichero: es un instrumento al portador.',
            'next' => 'Despues, registra la entrega: php artisan credentials:deliver :uuid --responsible=<correo>',
        ],
        'print_batch' => [
            'all_or_nothing' => 'El lote es todo o nada: no se ha impreso ninguna tarjeta. Vuelve a ejecutarlo.',
            'nothing_pending' => 'No hay ninguna credencial pendiente de imprimir.',
            'printed_many' => 'Las :count credenciales constan IMPRESAS y sus tokens son irrecuperables.',
            'generated' => ':count tarjeta(s) en una sola hoja: :path',
            'handle_with_care' => 'Imprime, corta por las guias y BORRA el fichero.',
            'next' => 'Despues, registra cada entrega: php artisan credentials:deliver <credencial> --responsible=<correo>',
            'pending_required' => 'Hace falta --pending. Es la unica seleccion posible: no existe la reimpresion (ADR-034).',
            'out_required' => 'Hace falta --out=/ruta/credenciales.pdf: este comando no elige donde dejar un PDF de tarjetas.',
        ],
        'retire_key' => [
            'key_required' => 'Hace falta el key_id de la clave que se retira.',
            'retired' => 'La clave :key ya no firma ninguna credencial activa (:count tarjetas en total a lo largo de su vida).',
            'last_step' => [
                'Ultimo paso, en el servidor:',
                '  - Vacia QR_SIGNING_KEY_PREVIOUS_ID y QR_SIGNING_KEY_PREVIOUS.',
                '  - Reinicia la aplicacion y comprueba que se ficha con normalidad.',
                '  - Destruye la copia de la clave retirada del gestor de secretos.',
            ],
        ],
        'rotate_key' => [
            'keys' => 'Clave saliente: :retiring · clave actual: :current',
            'cards' => 'Tarjetas activas firmadas con :retiring: :count',
            'dry_run' => 'En seco: se reemitirian :count credenciales.',
            'dry_run_pending' => ':count ya tienen una reemision pendiente de imprimir y no se duplicarian.',
            'reissued' => 'Reemitidas :count credenciales, pendientes de imprimir. Ninguna tarjeta vigente se ha invalidado.',
            'already_pending' => ':count ya estaban pendientes y no se han duplicado.',
            'next_steps' => [
                'Siguientes pasos (docs/runbooks/rotacion-clave-qr.md):',
                '  1. php artisan credentials:print-batch --pending --out=/tmp/credenciales.pdf',
                '  2. Entregar en mano y registrar: php artisan credentials:deliver <uuid> --responsible=<correo>',
                '  3. php artisan credentials:status --key-id=:key   # quien falta',
                '  4. php artisan credentials:retire-key :key        # cuando no falte nadie',
            ],
        ],
        'revoke_offboarded' => [
            'failed' => 'No se han podido revocar las tarjetas de las bajas: :error',
            'one_failed' => 'No se ha podido revocar una de las bajas: :error',
        ],
        'status' => [
            'no_site' => 'La instalacion todavia no tiene centro: completa antes el asistente de puesta en marcha, en el panel. Hasta entonces no hay credenciales que mostrar.',
            'print_hint' => 'Para imprimirlas: php artisan credentials:print-batch --pending --out=/tmp/credenciales.pdf',
            'key_unused' => 'Nadie ficha ya con una tarjeta firmada por la clave :key.',
            'can_retire' => 'Se puede retirar: php artisan credentials:retire-key :key',
            'all_delivered' => 'Todo el mundo tiene su tarjeta entregada.',
            'columns' => ['Empleado', 'Codigo', 'Departamento', 'Estado', 'Credencial'],
            'states' => [
                'no_credential' => 'Sin credencial',
                'pending_print' => 'Pendiente de imprimir',
                'pending_delivery' => 'Pendiente de entregar',
                'delivered' => 'Entregada',
                'revoked' => 'Revocada',
            ],
            'coverage' => ':site — sin tarjeta entregada: :without de :employees · pendientes de imprimir: :pending',
            'unknown_key' => 'AVISO: :count tarjeta(s) activa(s) firmada(s) con la clave :key, que ya no esta configurada. '
                .'Esas personas no pueden fichar con tarjeta.',
            'unknown_key_help' => [
                '  Quienes son:   php artisan credentials:status --key-id=:key',
                '  Como se arregla: docs/runbooks/rotacion-clave-qr.md §6',
            ],
            'rotation' => 'Rotacion en curso: :pending de :employees siguen fichando con la clave :key',
        ],
    ],

    'verify_audit_chain' => [
        'sealed_purge' => 'Purga sellada reconocida: particion :year (ADR-027).',
        'unknown_action' => 'Accion desconocida para esta version: :action — no es una rotura (el hash cuadra). '
            .'Escrita por una version posterior; ver docs/runbooks/rotura-cadena-auditoria.md §3.',
        'intact' => 'Cadena integra: :rows entradas verificadas.',
        'broken' => 'ROTURA DE LA CADENA DE AUDITORIA: :failures hallazgo(s) sobre :rows entradas.',
        'incident' => 'Incidente de seguridad. Procedimiento: docs/runbooks/rotura-cadena-auditoria.md',
    ],

    'ensure_audit_partitions' => [
        'procedure' => ' Procedimiento: :runbook',
        'created' => 'Particion audit_log_:year creada.',
        'current_was_missing' => 'Faltaba la particion del año en curso (:year). Se ha creado, pero hasta ahora '
            .'TODA accion auditable estaba fallando. Procedimiento: :runbook',
        'up_to_date' => 'Particiones al dia: :year presente.',
        'up_to_date_with_next' => 'Particiones al dia: :year presente y :next preparada.',
    ],

    'record_system_event' => [
        'unknown_action' => 'Accion desconocida: «:action». Solo se admiten las acciones «system.*» del catalogo.',
        'invalid_json' => 'El valor de --data no es JSON valido: :detail',
        'data_required' => '--data debe ser un objeto JSON con los datos del asiento.',
        'not_recorded' => 'No se pudo escribir el asiento: :detail',
    ],

    'incident_metrics' => [
        'published' => 'Metrica publicada: :count incidencia(s) abiertas.',
    ],

    'reconcile_work_record' => [
        'window_too_short' => 'La ventana tiene que cubrir al menos un dia (--days o compliance.work_record_reconciliation.window_days).',
        'coverage' => [
            'full' => 'registro completo',
            'recent' => 'ultimos :days dias',
        ],
        'consistent' => 'Registro y auditoria cuadran (:coverage): :count tramos conciliados.',
        'not_counted' => [
            'reviewed_purges' => 'purgas fuera de limites revisadas: :count (no cuentan)',
            'reviewed_site_timezone' => 'discrepancias de la zona del centro revisadas: :changes de la traza, :entries de asientos sin firma (no cuentan)',
            'unverifiable_site_timezone' => 'zona del centro no verificable: :sites centro(s) sin asiento site.*, :changes cambio(s) de zona anteriores a la auditoria conservada o a la linea base (no cuentan)',
        ],
        'mismatch' => 'EL REGISTRO HORARIO NO CUADRA CON SU AUDITORIA (:coverage): :count discrepancia(s) sobre :entries tramos.',
        'truncated' => '  (se listan las primeras :count; los recuentos de arriba son completos)',
        'incident' => 'Posible incidente de seguridad. Procedimiento: docs/runbooks/discrepancia-registro-auditoria.md',
    ],

    'legal_export' => [
        'example' => 'Ejemplo: php artisan compliance:legal-export --from=2026-01-01 --to=2026-01-31',
        'generated' => 'Exportacion legal generada.',
        'columns' => ['Concepto', 'Valor'],
        'rows' => [
            'period' => 'Periodo',
            'scope' => 'Alcance',
            'shift_entries' => 'Tramos',
            'corrections' => 'Correcciones',
            'employees' => 'Trabajadores',
            'file' => 'Fichero',
        ],
        'whole_staff' => 'plantilla completa',
    ],

    'detect_incidents' => [
        'invalid_days' => '--days espera un numero entero de dias mayor que cero y ha llegado «:value». '
            .'Sin valor valido no se adivina la ventana: revisar de mas abre incidencias sobre '
            .'jornadas ya entregadas, y revisar de menos las deja sin abrir.',
        'no_site' => 'Todavia no hay centro de trabajo: sin zona horaria no hay jornada que revisar (RF-PD-03).',
        'inspected' => 'Revisadas :work_days jornadas de los ultimos :days dias, mas los turnos abiertos.',
        'none' => 'Sin hallazgos.',
        'safe_to_repeat' => 'Los hallazgos ya abiertos no se duplican: repetir el comando es seguro. '
            .'Ningun tramo se ha cerrado (RN-08).',
        'failures' => ':count hallazgo(s) no se pudieron abrir como incidencia. El resto si. '
            .'Busca «attendance.incident_not_opened» en el log para ver cuales.',
    ],

    'detect_patterns' => [
        'invalid_days' => '--days espera un numero entero de dias mayor que cero y ha llegado «:value». '
            .'Sin valor valido no se adivina la ventana: un patron se afirma sobre los dias que se miran, '
            .'y mirar de menos deja de verlo.',
        'no_site' => 'Todavia no hay centro de trabajo: sin zona horaria no hay dia civil con el que agrupar (RF-PD-03).',
        'inspected' => 'Revisados :scans escaneos de quiosco de los ultimos :days dias.',
        'none' => 'Sin patrones anomalos.',
        'hints_only' => 'Son indicios para revision humana, no una conclusion: ningun fichaje se ha anulado ni marcado '
            .'como fraudulento (RF-PR-06). Repetir el comando es seguro: los hallazgos ya abiertos no se duplican.',
        'failures' => ':count hallazgo(s) no se pudieron abrir como incidencia. El resto si. '
            .'Busca «attendance.pattern_incident_not_opened» en el log para ver cuales.',
    ],

    'prune_scan_failures' => [
        'pruned' => 'scan_processing_failures: :count intentos de mas de :days dias borrados.',
    ],

    'reconcile_projections' => [
        'invalid_dates' => '--from y --to esperan una fecha en formato YYYY-MM-DD. '
            .'Sin fechas validas no se adivina el rango: la pasada por defecto es la jornada de ayer.',
        'no_site' => 'Todavia no hay centro de trabajo: sin zona horaria no hay jornada que reconciliar (RF-PD-03).',
        'inspected' => 'Contrastadas :work_days jornadas de :from a :to (:days dias).',
        'clean' => 'Sin divergencias: la proyeccion coincide con los tramos vigentes (RN-06).',
        'missing_row' => 'fila ausente',
        'divergences' => ':count divergencia(s), :corrected corregida(s). La proyeccion se habia desviado de sus eventos origen: '
            .'no es mantenimiento rutinario. Sigue docs/runbooks/divergencia-proyeccion.md.',
        'failures' => ':count jornada(s) no se pudieron reconciliar. Busca «attendance.projection_not_corrected» '
            .'y «attendance.projection_reconciliation_failed» en el log para ver cuales.',
        'self_resolved' => ':count jornada(s) se resolvieron solas: la lectura se cruzo con un fichaje y al releerlas '
            .'bajo candado ya cuadraban. No se ha escrito nada en ellas.',
    ],

    'kiosk_pairing_code' => [
        'name_required' => 'Falta --name con el nombre del quiosco, por ejemplo --name="Recepcion".',
        'no_site' => 'La instalacion todavia no tiene centro: completa antes el asistente de puesta en marcha.',
        'name_taken' => 'Ya hay un quiosco activo con ese nombre. Elige otro o desvincula el anterior.',
        'rejected' => 'El codigo no se ha podido confirmar. Pide otro codigo en la tablet e intentalo de nuevo.',
        'paired' => 'Vinculado el quiosco «:name» (:uuid).',
        'reactivated' => 'Reactivado el quiosco «:name» (:uuid).',
        'next' => 'La tablet recogera su token en unos segundos. Comprueba con: php artisan kiosk:health',
    ],

    'reporting_metrics' => [
        'absences' => [
            'no_site' => 'Todavia no hay centro de trabajo: no hay zona con la que resolver el dia de hoy (RF-PD-03).',
            'published' => 'Metricas de ausencias publicadas.',
        ],
        'adoption' => [
            'no_site' => 'Todavia no hay centro de trabajo: no hay jornada que medir (RF-PD-03).',
            'published' => 'Metricas de adopcion publicadas.',
        ],
        'compliance' => [
            'no_site' => 'Todavia no hay centro de trabajo: no hay perfil con el que evaluar (RF-PD-03).',
            'published' => 'Metricas de cumplimiento publicadas.',
        ],
        'presence' => [
            'no_site' => 'Todavia no hay centro de trabajo: no hay nada que etiquetar (RF-PD-03).',
            'published' => 'Metricas de presencia publicadas.',
        ],
    ],

    'weekly_summary' => [
        'sent' => 'Semana :week: :sent resumen(es) enviados, :skipped omitidos de :recipients responsable(s).',
        'failures' => ':count resumen(es) no se pudieron entregar; su semana sigue pendiente y se reintentara. '
            .'Busca «reporting.weekly_summary_failed» en el log para ver a que cuentas y de que clase '
            .'fue el problema.',
    ],

    'backup' => [
        'unverified' => 'Copia SIN verificar: una copia no verificada no es una copia. '
            .'Verificala en cuanto puedas con: php artisan backup:verify',
        'run_failed' => 'La copia no se ha completado (codigo :code). La copia anterior sigue siendo la buena. '
            .'Procedimiento: docs/runbooks/restaurar-backup.md',
        'verify_failed' => 'La copia NO esta verificada (codigo :code). Tratala como inexistente hasta resolverlo: '
            .'docs/runbooks/restaurar-backup.md',
    ],

];
