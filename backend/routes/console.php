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

/*
 * Temporales huerfanos de la exportacion legal (RF-IN-05, hallazgo MEDIO-3
 * del cierre de la Fase 1, tarea 1.17).
 *
 * SOLO storage/framework/legal-exports/, el temporal de la descarga HTTP que
 * `LegalExportController` no llega a borrar si el cliente aborta a medias.
 * NUNCA toca storage/app/legal-exports/, la copia deliberada de
 * `compliance:legal-export` que se entrega a Inspeccion: esa la custodia y
 * la borra quien la genero (docs/runbooks/requerimiento-inspeccion.md §6).
 *
 * CADA HORA, con una ventana de
 * config('compliance.legal_export_temp_retention_hours') (6 h por defecto):
 * un huerfano con datos personales de la plantilla no debe esperar a la
 * copia de madrugada para desaparecer, y la ventana es lo bastante ancha
 * para no borrar una descarga legitima todavia en curso.
 */
Schedule::command('compliance:purge-legal-export-temp')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Metricas de la presencia en vivo (doc 02 §8.2, doc 01 §9.2, tarea 2.4).
 *
 *   open_shifts_current{site,site_name,department}
 *   websocket_connections_active
 *
 * CADA MINUTO, y la cadencia sale de las dos metricas. La primera es «cuanta
 * gente hay dentro ahora mismo»: una cifra que se refresca cada hora no sirve
 * para mirar un cambio de turno, que es el unico momento en el que alguien la
 * mira. La segunda distingue «el WebSocket esta caido» de «el sistema esta
 * caido» (ADR-011), y esa diferencia hay que verla en minutos, no en horas.
 *
 * Un minuto es ademas la cadencia con la que Prometheus recoge el colector
 * textfile de node-exporter: refrescar el fichero mas a menudo no llegaria a
 * ninguna serie.
 *
 * SE RECALCULA ENTERA, NUNCA SE INCREMENTA (regla dura 7 aplicada a la
 * instrumentacion). Por eso es una tarea programada y no un efecto del listener
 * de difusion: asi la cifra recoge tambien lo que no pasa por un fichaje —una
 * anulacion, una correccion que cierra un turno olvidado, una carga inicial— y
 * un mensaje perdido no la desvia para siempre.
 *
 * `withoutOverlapping` porque son dos consultas agregadas mas una llamada HTTP a
 * Reverb: dos a la vez solo duplican el trabajo. Sin `runInBackground` a
 * proposito —dura milisegundos y encadenarla es mas barato que arrancar otro
 * proceso cada minuto—.
 */
Schedule::command('reporting:presence-metrics')
    ->everyMinute()
    ->withoutOverlapping();

/*
 * Reconciliacion de `daily_totals` con sus eventos origen (RF-PR-02, ADR-007,
 * tarea 2.7).
 *
 *   attendance:reconcile        # sin fechas: la jornada de ayer
 *
 * DIARIA, a las 03:50 UTC, y el hueco esta elegido: despues de la copia (03:15),
 * ANTES de la verificacion de la cadena de auditoria (04:05) y ANTES de la
 * deteccion de incidencias (04:30). Las dos precedencias tienen motivo:
 *
 *   · Antes de la deteccion, porque si la reconciliacion corrige un dia, la
 *     revision de esa noche trabaja ya sobre la version buena. Hoy la deteccion
 *     lee `shift_entries` y no la proyeccion, asi que el orden no la cambia; el
 *     dia que una regla mire un agregado diario, el orden sera lo unico que
 *     impida abrir una incidencia sobre un total equivocado.
 *   · Antes de la verificacion de la cadena, porque una correccion de proyeccion
 *     escribe en `audit_log`: asi los asientos de esta noche se verifican esta
 *     noche y no veinticuatro horas mas tarde (RS-07).
 *
 * AYER Y NO HOY. La jornada de ayer es la ultima que ya no va a cambiar por si
 * sola; reconciliar el dia en curso compararia una y otra vez turnos todavia
 * abiertos que siguen creciendo. Un rango mas ancho —tras una importacion, una
 * restauracion o una migracion que toque la proyeccion— se lanza a mano con
 * `--from` y `--to`, que es una decision consciente de quien la ejecuta.
 *
 * TERMINA EN ROJO SI ENCUENTRA ALGO, aunque lo haya corregido: la proyeccion se
 * recalcula entera en la transaccion que la motiva (regla dura 7), asi que una
 * divergencia no es trabajo rutinario, es un incidente de integridad
 * (`projection_divergence_total` debe permanecer siempre en cero, doc 02 §8.2) y
 * se responde con docs/runbooks/divergencia-proyeccion.md.
 *
 * `withoutOverlapping` porque una pasada sobre un rango ancho lanzada a mano
 * puede seguir corriendo a la hora de la nocturna, y dos reconciliaciones
 * simultaneas sobre la misma jornada solo duplican la lectura.
 */
Schedule::command('attendance:reconcile')
    ->dailyAt('03:50')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Deteccion automatica de incidencias (RF-PR-01, tarea 2.6).
 *
 *   attendance:detect-incidents
 *
 * DIARIA, a las 04:30 UTC. Despues de la copia (03:15) y de la verificacion de
 * la cadena de auditoria (04:05), y lejos del cambio de turno de las 06:00: lee
 * las jornadas de una semana y no debe competir con el minuto mas caro del dia.
 *
 * UNA VEZ AL DIA Y NO MAS. El unico hallazgo que gana algo con mas frecuencia es
 * el turno abierto, y ese no urge: RN-08 prohibe cerrarlo, asi que lo que la
 * deteccion produce es trabajo para una persona en horario de oficina. Correrla
 * cada hora multiplicaria por veinticuatro el aviso al responsable sin adelantar
 * ni una correccion.
 *
 * NO CIERRA NADA (RN-08, regla dura 19): abre incidencias y avisa. Repetirla es
 * seguro —la idempotencia la garantiza la restriccion `one_incident_per_finding`—,
 * asi que `withoutOverlapping` esta por no duplicar el trabajo, no por
 * correccion.
 */
Schedule::command('attendance:detect-incidents')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Metrica de incidencias abiertas (doc 02 §8.2, doc 01 §9.2, tarea 2.6).
 *
 *   incidents_open{type,severity}
 *
 * CADA CINCO MINUTOS, y APARTE de la deteccion. Si se publicara al final de
 * aquella, la cifra solo cambiaria de madrugada: una incidencia resuelta desde la
 * bandeja a las once de la manana seguiria contando —y la alerta de turnos
 * abiertos seguiria sonando— hasta la noche siguiente. Es un gauge de «cuantas
 * hay ahora», y se recalcula entero desde la base de datos (regla dura 7
 * aplicada a la instrumentacion).
 *
 * Cinco minutos y no uno: a diferencia de la presencia en vivo, que se mira
 * durante un cambio de turno, esto alimenta una alerta de severidad media cuyo
 * destinatario es RRHH. Un minuto de resolucion no cambiaria ninguna decision y
 * son doce consultas agregadas mas por hora.
 */
Schedule::command('compliance:incident-metrics')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Propuesta de purga por retencion (RL-02, RL-11, RF-PR-03, tarea 2.10).
 *
 * SOLO SIMULACION, y esto es el requisito, no una precaucion: RF-PR-03 dice que
 * el sistema **propone** y exige confirmacion del responsable. La ejecucion
 * destructiva no se programa nunca —no existe forma de que este planificador
 * borre una jornada— y ademas no podria: la purga real necesita la frase que
 * imprime cada informe y la credencial del rol de mantenimiento, que no vive en
 * el `.env` de la aplicacion (ADR-033).
 *
 * SEMANAL Y NO DIARIA. Lo que esta pasada produce es un informe para que alguien
 * decida, y esa decision se toma una o dos veces al ano. Un informe diario del
 * mismo vencimiento entrena a no leerlo; uno semanal sigue siendo puntual y
 * mantiene viva la metrica `retention_pending_rows`, que es la que descubre que
 * hay cuatro anos vencidos que nadie ha purgado.
 *
 * Lunes a las 05:10 UTC: despues de la copia (03:15), de la verificacion de la
 * cadena (04:05) y de la deteccion de incidencias (04:30), y antes de que entre
 * nadie a trabajar. Recorre tablas grandes con `count(*)`, asi que no comparte
 * ventana con el turno de las 06:00.
 */
Schedule::command('compliance:apply-retention', ['--dry-run'])
    ->weeklyOn(1, '05:10')
    ->withoutOverlapping()
    ->runInBackground();
