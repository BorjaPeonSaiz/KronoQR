<?php

declare(strict_types=1);

/*
 * Textos de `php artisan product:errors` y `product:errors:prune` (RF-PD-15,
 * tarea 5.12).
 *
 * ## Para quien estan escritos
 *
 * Para la misma persona que lee `product:doctor`: la de informatica del hotel,
 * que no conoce este sistema y que probablemente ya tiene un problema encima. De
 * ahi las tres reglas que sigue cada frase:
 *
 *   1. **Se dice que se esta mirando y desde cuando.** «Errores del ultimo dia»
 *      y no «errores».
 *   2. **Se dice que hacer.** El fabricante no tiene acceso a este servidor
 *      (ADR-016): un informe que solo enumera problemas deja la llamada de
 *      telefono como unica salida.
 *   3. **Nada se da por sabido.** «Grupo», «huella» y «traza» se explican donde
 *      aparecen, o directamente no se usan.
 *
 * ## Ni una frase habla de la licencia
 *
 * Este comando funciona igual con la licencia caducada o ausente (regla dura
 * 15), y el texto no lo menciona porque no viene a cuento: el IT que lo ejecuta
 * esta mirando un problema tecnico.
 */

return [

    'report' => [
        'title' => 'Errores de KronoQR desde :since',
        'none' => 'No hay ningun error abierto en este periodo.',
        'found' => 'Se muestran :shown grupo(s) de :total. Un grupo son todas las veces que ha pasado '
            .'el mismo error.',
        'tag_error' => 'error',
        'tag_critical' => 'CRITICO',
        'seen' => 'Ha pasado :count vez/veces. Primera: :first. Ultima: :last.',
        'trace' => 'Traza: :trace_id (buscala en el log tecnico si conservas el stack de observabilidad)',
        'open_totals' => 'En total, la instalacion tiene :errors error(es) y :critical critico(s) sin resolver.',
        'what_to_do' => 'Que hacer: los CRITICOS primero — son fallos que nadie ve (una tarea nocturna, un trabajo '
            .'en cola) o que impiden fichar (camara, escaner, almacen de la tablet). Si no sabes por donde empezar, '
            .'ejecuta `php artisan product:doctor` y, si el problema persiste, genera el paquete de diagnostico con '
            .'`php artisan product:diagnostics` y enviaselo a soporte: lleva estos mismos errores dentro y no '
            .'contiene datos de tu plantilla.',
        'bad_since' => 'No entiendo el periodo «:value». Escribelo como una cifra y una unidad: 30m, 24h, 7d o 2w.',
        'bad_level' => 'Nivel desconocido. Los que hay son: :values.',
        'bad_source' => 'Origen desconocido. Los que hay son: :values.',
    ],

    'prune' => [
        'dry_run' => 'Se borrarian :rows grupo(s) de errores con mas de :days dias sin volver a ocurrir. '
            .'No se ha borrado nada. Quita --dry-run para hacerlo.',
        'done' => 'Borrados :rows grupo(s) de errores con mas de :days dias sin volver a ocurrir. '
            .'Esto no afecta a los fichajes ni al registro de auditoria.',
        'failed' => 'No se ha podido purgar el historico de errores (:failure). Comprueba que la base de datos '
            .'responde con `php artisan product:doctor`; los errores antiguos siguen ahi y no estorban a nada.',
    ],

];
