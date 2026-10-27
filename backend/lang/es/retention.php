<?php

declare(strict_types=1);

/*
 * Textos de `compliance:apply-retention` cuando la purga falla contra la base
 * de datos (RF-PR-03, RL-02, ADR-057 §3). Se distingue por el SQLSTATE y no por
 * el mensaje del controlador, que incluye la sentencia entera.
 */

return [

    'responsible_not_active' => 'La cuenta de --responsible ya no es una cuenta de gestión activa con rol admin o rrhh: no puede responder de la purga. No se ha borrado nada.',
    'confirm_signature' => '¿Ejecutar ESTA purga, firmada por esta cuenta?',

    'failure' => [
        'title' => 'La purga no ha podido completarse contra la base de datos. No se ha borrado nada.',
        'cutoff' => [
            'PostgreSQL ha rechazado el corte del registro de jornada: es posterior al que permite el perfil de cumplimiento con la fecha UTC del servidor de base de datos.',
            'La función toma el plazo del perfil de cumplimiento del centro, sin bajar del suelo legal de su jurisdicción, y lo cuenta con la fecha del servidor de base de datos, no con la de este.',
            'Comprueba la hora de los dos servidores y que el perfil no haya cambiado desde la simulación, y vuelve a simular.',
        ],
        'lock' => [
            'Otra transacción tenía bloqueadas filas del registro y la purga ha dejado de esperar.',
            'Vuelve a ejecutar la misma orden en unos minutos, preferiblemente fuera de un cambio de turno.',
        ],
        'profile' => [
            'No hay ningún perfil de cumplimiento con el que calcular el corte: la purga no puede saber cuánto hay que conservar.',
            'Revisa el perfil por defecto (php artisan product:doctor) antes de volver a intentarlo.',
        ],
        'generic' => [
            'Si es la conexión «pgsql_maintenance», comprueba que DB_MAINTENANCE_PASSWORD está puesta y que el rol tiene credencial: infra/docker/postgres/initdb/02-application-roles.sh la asigna.',
        ],
        'detail' => 'Detalle técnico: :detail',
    ],

    /*
     * Lo que la orden escribe en pantalla al simular o ejecutar la purga.
     */
    'console' => [
        'cutoff' => 'Corte del registro de jornada: anterior a :date (:years anos, perfil del centro :site).',
        'columns' => ['Almacen', 'Cantidad', 'Rango'],
        'report' => 'Informe: :path',
        'purged' => 'Purgados :count registros. Queda asiento en audit_log.',
        'nothing_due' => 'No hay nada vencido: no se purgaria nada.',
        'how_to_execute' => 'Nada se ha borrado. Para ejecutar ESTA purga, con la autorizacion del responsable:',
    ],

    /*
     * El informe que se archiva como constancia de cada pasada. Sale en el
     * idioma de la instalacion; su nombre de fichero, no (lo buscan scripts).
     */
    'report' => [
        'title' => 'KronoQR — informe de retencion (RL-02, RL-11, RF-PR-03)',
        'mode' => 'Modo:              :mode',
        'modes' => [
            'simulation' => 'SIMULACION (no se ha borrado nada)',
            'execution' => 'EJECUCION REAL',
        ],
        'generated' => 'Generado:          :at UTC',
        'site' => 'Centro:            :site',
        'terms' => 'Plazos aplicados:  registro de jornada y auditoria :years anos · log tecnico :log_days dias · '
            .'historico de errores :error_days dias',
        'cutoff' => 'Corte de jornada:  anterior a :date (ese dia NO se purga: el plazo lo incluye)',
        'scopes' => [
            'work_records' => 'Registro de jornada',
            'audit_log' => 'Cadena de auditoria',
            'technical_log' => 'Log tecnico',
            'error_history' => 'Historico de errores',
            'personnel_records' => 'Fichas de personal',
            'management_accounts' => 'Cuentas de gestion',
        ],
        'units' => [
            'work_records' => 'filas',
            'audit_log' => 'filas',
            'technical_log' => 'ficheros',
            'error_history' => 'filas',
            'personnel_records' => 'personas',
            'management_accounts' => 'cuentas',
        ],
        'term' => [
            'years' => ':years anos',
            'years_since_leaving' => ':years anos desde el cese',
            'days' => ':days dias',
        ],
        'not_installed' => 'no instalado',
        'subtotal' => 'subtotal',
        'total_to_purge' => 'TOTAL de registros a purgar: :count',
        'total_purged' => 'TOTAL de registros purgados: :count',
        'notes' => 'Observaciones',
        'observations' => [
            'empty_audit_partition' => 'Partición audit_log_:year vacía: no se sella ni se suelta.',
            'orphaned_personnel_photos' => ':count foto(s) de fichas minimizadas no se pudieron borrar del disco: '
                .'la ficha ya no las apunta. El evento retention.personnel_photo_orphaned del log dice de qué employee_uuid.',
        ],
        'nothing_deleted' => 'No se ha borrado nada. Para ejecutar ESTA purga, con la confirmacion del responsable:',
        'phrase_expires' => [
            'La frase caduca cuando cambia el corte o el perfil de cumplimiento: se ejecuta lo que',
            'se aprobo, no lo que hoy toque.',
        ],
        'executed' => 'Purga ejecutada con la confirmacion :token.',
        'executed_entries' => 'Queda asiento en audit_log; las particiones soltadas quedan selladas en audit_chain_anchors.',
        'stays_on_server' => 'Este informe se queda en el servidor del cliente (ADR-020). No contiene datos personales.',
    ],

];
