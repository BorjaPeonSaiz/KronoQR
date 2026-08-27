<?php

declare(strict_types=1);

/*
 * Observabilidad (doc 02 §8).
 *
 * POR QUE LAS METRICAS DE LA FASE 1 SE ESCRIBEN EN FICHEROS
 *
 * `/metrics` lo expone la aplicacion a partir de la tarea 3.1, con
 * `promphp/prometheus_client_php`. Hasta entonces hay metricas que ya existen y
 * que no pueden esperar —el resultado de la copia (tarea 1.18) y el de la
 * verificacion de la cadena de auditoria (tarea 1.14)—, y las dos las produce un
 * proceso que corre una vez al dia y termina.
 *
 * Un contador en memoria de un proceso que termina no lo lee nadie. La via que
 * ya esta montada es el colector *textfile* de `node-exporter`
 * (infra/compose.*.yaml, §8.2): cada productor escribe SU fichero `.prom` en
 * este directorio y Prometheus lo recoge. Es exactamente el mecanismo que usan
 * `infra/scripts/backup.sh` y `restore-drill.sh`, y por la misma razon: la
 * metrica tiene que seguir publicandose aunque la aplicacion no arranque.
 *
 * La ruta coincide con `BACKUP_PATH/metrics` porque es el volumen que
 * `node-exporter` monta. No es una dependencia de las copias: es el mismo punto
 * de recogida.
 */

return [

    'metrics' => [

        /*
         * Directorio del colector textfile. Cada productor escribe su propio
         * fichero: dos ficheros que declaren la misma metrica hacen que
         * node-exporter descarte los dos.
         */
        'textfile_path' => env(
            'METRICS_TEXTFILE_PATH',
            env('BACKUP_PATH', '/var/backups/fichaje').'/metrics',
        ),

        /*
         * Si se escriben. En la suite de pruebas se apaga: lo que hay que
         * comprobar es que el caso de uso llama al puerto, y eso se verifica con
         * un doble, no con un fichero en disco.
         */
        'enabled' => env('METRICS_TEXTFILE_ENABLED', true),
    ],

];
