<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * Comandos de consola con cierre. Los comandos del producto —reconciliacion
 * nocturna, deteccion de incidencias, retencion, copias— se declaran como
 * clases con firma explicita (doc 02 §3.5) en el modulo que les corresponde,
 * no aqui.
 *
 * Lo que si vive aqui es CUANDO se ejecutan.
 */

/*
 * Copia de seguridad (RF-PR-04, RNF-D-02). Dos cadencias y un motivo para cada
 * una:
 *
 *   · Diaria, volcado logico. Es la que restaura la base en cualquier sitio y
 *     la que verifica el simulacro trimestral.
 *   · Semanal, copia fisica. Sin ella el WAL archivado no reconstruye nada, y
 *     el RPO de 15 minutos seria una promesa sin respaldo.
 *
 * `withoutOverlapping` porque un volcado que se solapa con el anterior duplica
 * la carga de disco justo cuando ya va lento, y `onOneServer` no aplica: cada
 * cliente tiene una sola instalacion (ADR-016).
 *
 * Las horas salen de config/backup.php y por defecto son de madrugada: nunca
 * cerca del cambio de turno de las 06:00.
 */
Schedule::command('backup:run', ['--mode' => config()->string('backup.daily_mode')])
    ->dailyAt(config()->string('backup.daily_at'))
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('backup:run', ['--mode' => config()->string('backup.weekly_mode')])
    ->weeklyOn(config()->integer('backup.weekly_on'), config()->string('backup.weekly_at'))
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Cadena de hash de la auditoria (RS-07, ADR-010, tarea 1.14).
 *
 * DIARIA, y la cadencia es el requisito, no una preferencia: RS-07 exige que
 * cualquier rotura se detecte «en menos de 24 h». Cualquier hallazgo dispara la
 * alerta critica de seguridad de infra/observability/prometheus/rules/audit.yml
 * y se responde con docs/runbooks/rotura-cadena-auditoria.md.
 *
 * A las 04:05 UTC: despues de la copia diaria (03:15) y lejisimos del cambio de
 * turno de las 06:00. Es una lectura completa de la tabla y no debe competir con
 * el minuto mas caro del dia.
 *
 * `withoutOverlapping` porque, con historico de cuatro años, una verificacion
 * puede durar mas de lo previsto y dos a la vez solo duplican la lectura.
 */
Schedule::command('compliance:verify-audit-chain')
    ->dailyAt('04:05')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Particiones anuales de `audit_log` (ADR-027).
 *
 * DIARIA aunque solo actue en noviembre y diciembre, y por dos motivos. El
 * primero: comprobar que existe la particion del año en curso cuesta una
 * consulta al catalogo, y si faltara, cada fichaje estaria fallando ahora mismo
 * —no en enero—. El segundo: una tarea que solo se ejecuta una vez al año es una
 * tarea que nadie ha visto ejecutarse nunca.
 *
 * A las 02:45 UTC, antes de la copia: si crea una particion, que entre en la
 * copia de esa misma noche.
 */
Schedule::command('compliance:ensure-audit-partitions')
    ->dailyAt('02:45')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Metricas de credenciales (RF-QR-08, doc 02 §8.2, tarea 1.10).
 *
 *   employees_without_delivered_credential{site}
 *   credentials_pending_print{site}
 *
 * CADA HORA, y la cadencia es el requisito. El §8.2 dice de la primera: «debe
 * llegar a cero antes del primer dia de cada incorporacion». Una metrica que se
 * refresca una vez al dia deja a quien da un alta a las 10:00 sin verla hasta la
 * madrugada siguiente, que es justo cuando ya no se puede hacer nada.
 *
 * `--quiet-table` porque el planificador no tiene a nadie leyendo una tabla de
 * trescientas filas: lo unico que hace falta es que se reescriba el fichero
 * `.prom` del colector textfile. Sin `--site`: el fichero es global, y publicar
 * un solo centro haria desaparecer las series de todos los demas.
 *
 * `withoutOverlapping` porque son dos consultas sobre la plantilla completa y
 * dos a la vez solo duplican la lectura.
 */
Schedule::command('credentials:status', ['--quiet-table'])
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
