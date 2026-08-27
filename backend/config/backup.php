<?php

declare(strict_types=1);

/*
 * Copias de seguridad (RF-PR-04, RNF-D-02, RL-12).
 *
 * Aqui no hay logica: hay RUTAS y PLAZOS, que son configuracion de cada
 * instalacion (regla dura 13). Quien hace la copia es infra/scripts/backup.sh,
 * y los comandos `backup:run` y `backup:verify` solo lo invocan y devuelven su
 * codigo de salida.
 *
 * Por que la copia la hace un script de shell y no PHP. La restauracion tiene
 * que poder ejecutarse cuando la aplicacion NO arranca —base corrupta, version
 * a medio actualizar, disco lleno—, y un procedimiento de recuperacion que
 * depende de que funcione lo que hay que recuperar no es un procedimiento de
 * recuperacion. Los mismos scripts se entregan al cliente (§11.6.1) y se
 * ejecutan desde cron sin Laravel por delante.
 *
 * LA CLAVE DE CIFRADO NO APARECE EN ESTE FICHERO NI EN NINGUN OTRO DEL
 * REPOSITORIO. Viaja en el entorno del contenedor, la genera install.sh en el
 * servidor del cliente y el script la recibe por el entorno, nunca por argv.
 */

return [

    /*
     * Directorio de los scripts de operacion. En produccion son los que la
     * imagen lleva dentro, que son los de la version desplegada; en
     * desarrollo, infra/compose.dev.yaml apunta al repositorio montado para no
     * tener que reconstruir la imagen al tocar un script.
     */
    'script_path' => env('BACKUP_SCRIPT_PATH', '/opt/kronoqr/scripts'),

    /*
     * Destino de las copias. Se monta en la MISMA ruta dentro del contenedor
     * (infra/compose.prod.yaml), asi que este valor sirve a los dos lados.
     */
    'path' => env('BACKUP_PATH', '/var/backups/fichaje'),

    /*
     * Modo de la copia diaria. `dump` es el volcado logico; `full` añade la
     * copia fisica que, con el WAL archivado, sostiene el RPO de 15 min
     * (RNF-D-02). La copia fisica pesa como la base entera: se programa aparte,
     * semanalmente, en lugar de encarecer la diaria.
     */
    'daily_mode' => env('BACKUP_DAILY_MODE', 'dump'),
    'weekly_mode' => env('BACKUP_WEEKLY_MODE', 'base'),

    /*
     * Cuando se lanza cada una, en la zona horaria del scheduler (UTC, regla
     * dura 3). Por defecto de madrugada y NUNCA cerca del cambio de turno de
     * las 06:00, que es el minuto mas caro del dia en un hotel.
     */
    'daily_at' => env('BACKUP_DAILY_AT', '03:15'),
    'weekly_at' => env('BACKUP_WEEKLY_AT', '02:15'),
    'weekly_on' => (int) env('BACKUP_WEEKLY_ON', 0),

    /*
     * Tiempo maximo que se le concede al script antes de darlo por colgado. Un
     * volcado de una instalacion grande puede tardar minutos; una hora es
     * holgura suficiente y evita que un proceso zombi se quede con el disco.
     */
    'timeout' => (int) env('BACKUP_TIMEOUT_SECONDS', 3600),

];
