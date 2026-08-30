<?php

declare(strict_types=1);

/*
 * Incidencias del registro horario (RF-PR-01, doc 01 §5.5).
 *
 * Los textos de usuario van en `i18n` y el codigo en ingles (doc 02 §3.5): las
 * claves son los valores respaldados de `incidents.type`, `severity` y `status`,
 * de modo que anadir un tipo obliga a traducirlo o la clave se ve tal cual.
 *
 * El correo lo lee el **responsable del departamento**, no el empleado: se
 * escribe en el idioma de su cuenta (`users.locale`).
 */

return [

    'types' => [
        'open_shift_expired' => 'Turno abierto demasiado tiempo',
        'short_shift' => 'Tramo por debajo del mínimo computable',
        'long_shift' => 'Jornada por encima de la ordinaria',
        'missing_break' => 'Tramo continuado sin pausa',
        'insufficient_rest' => 'Descanso insuficiente entre jornadas',
        'clock_skew' => 'Fichaje con el reloj desviado',
        'missing_clock_out' => 'Falta el fichaje de salida',
        'anomalous_pattern' => 'Patrón anómalo de uso de credencial',
    ],

    'severities' => [
        'low' => 'Baja',
        'medium' => 'Media',
        'high' => 'Alta',
    ],

    'statuses' => [
        'open' => 'Abierta',
        'resolved' => 'Resuelta',
        'dismissed' => 'Descartada',
    ],

    'mail' => [
        'subject' => 'KronoQR · incidencias del registro horario pendientes de revisar',
        'greeting' => 'Hola:',
        'intro' => 'La revisión automática del registro horario ha encontrado :count situación(es) en tu departamento que necesitan que las mire una persona.',
        'line' => ':date · :employee · :type (prioridad :severity)',
        'more' => 'Y :count más, que puedes ver en la bandeja de incidencias del panel.',
        'no_auto_close' => 'El sistema no ha cerrado ningún turno ni ha cambiado ninguna hora. Las correcciones las hace una persona y quedan registradas con su motivo.',
        'action' => 'Abrir la bandeja de incidencias',
        'footer' => 'Recibes este aviso una vez por cada revisión. Las incidencias que ya has trabajado no vuelven a aparecer.',
    ],

];
