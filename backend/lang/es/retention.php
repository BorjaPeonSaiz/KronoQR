<?php

declare(strict_types=1);

/*
 * Textos de `compliance:apply-retention` cuando la purga falla contra la base
 * de datos (RF-PR-03, RL-02, ADR-057 §3). Se distingue por el SQLSTATE y no por
 * el mensaje del controlador, que incluye la sentencia entera.
 */

return [

    'failure' => [
        'title' => 'La purga no ha podido completarse contra la base de datos. No se ha borrado nada.',
        'cutoff' => [
            'PostgreSQL ha rechazado el corte del registro de jornada: es posterior al que permite el perfil de cumplimiento con la fecha UTC del servidor de base de datos.',
            'La función toma el plazo más largo de todos los centros de la instalación (cada uno, sin bajar del suelo legal de su jurisdicción), no solo el del centro que esperabas.',
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

];
