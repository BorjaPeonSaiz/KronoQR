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

];
