<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Application\Port\AdoptionFactsReader;
use App\Modules\Reporting\Application\Port\WorkDayCompletionReader;
use App\Modules\Reporting\Domain\Exception\ReportTooLargeForSynchronousDelivery;
use App\Modules\Reporting\Domain\Policy\AdoptionIndicators;
use App\Modules\Reporting\Domain\ValueObject\AdoptionFacts;
use App\Modules\Reporting\Domain\ValueObject\AdoptionPeriodFacts;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReportQuery;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * {@see AdoptionFactsReader} sobre PostgreSQL (**RF-IN-08**, **RNF-D-01**).
 *
 * ## Lo que NO hace, que es lo primero
 *
 * **Ni una division, ni un porcentaje, ni una comparacion con un objetivo.** Lo
 * que sale de aqui son enteros; la aritmetica la hace {@see AdoptionIndicators},
 * en el dominio y sin base de datos. Ver su docblock para el argumento entero: en
 * resumen, un `round(100.0 * a / b, 2)` escondido en un `SELECT` no se puede
 * verificar a mano, y el `NULL` de SQL no significa lo mismo que el «no se sabe»
 * de este cuadro.
 *
 * Las dos excepciones son la **media** y la **mediana** del tiempo de resolucion,
 * que si se calculan aqui: no son porcentajes sino agregados de una columna de
 * minutos, y `percentile_cont` resuelve la mediana en la misma pasada que la
 * media en lugar de traerse a PHP una lista de incidencias. El «no se sabe» sigue
 * decidiendose en el dominio, a partir del **recuento** de incidencias resueltas.
 *
 * **No recalcula horas.** `worked_minutes` y `contracted_minutes` no salen de
 * aqui: los pone `GeneratePeriodReport` (regla dura 7). Una segunda SQL que sumara
 * horas seria una segunda verdad sobre las mismas horas.
 *
 * **No define «jornada completa».** La define {@see WorkDayCompletionReader}, que
 * es el mismo lector que publica `workdays_complete_ratio{site}` desde la tarea
 * 3.1: con dos consultas, Grafana y el cuadro darian dos porcentajes para la misma
 * semana (decision 8 de la ficha 3.13).
 *
 * ## `AT TIME ZONE` de la zona del centro, y no `date_trunc` en UTC
 *
 * Aqui si hay conversion de zona, al contrario que en el informe por periodo: las
 * jornadas se filtran por `work_date` —que **ya es** una fecha civil con RN-05
 * aplicada—, pero los escaneos, las correcciones y las incidencias solo tienen
 * marcas de tiempo `TIMESTAMPTZ`. Decidir en que dia civil cae un fichaje de las
 * 23:40 de Madrid es exactamente el problema que las reglas duras 3 y 4 obligan a
 * resolver con la zona del **centro**: en UTC ese fichaje seria del dia siguiente,
 * y un cuadro de marzo dejaria fuera la ultima noche del mes.
 *
 * Con el cambio de hora la aritmetica no se mueve: PostgreSQL convierte cada
 * instante con las reglas de la zona, asi que el domingo de 23 horas tiene 23 y el
 * de 25 tiene 25, y los dos caen enteros en su dia civil (RN-09).
 *
 * ## Los dos periodos en las mismas consultas
 *
 * Una CTE `periods` con dos filas y un `LEFT JOIN` contra ella. Asi el periodo
 * **sin ninguna fila sigue apareciendo** —con ceros, que es lo que el dominio
 * necesita para decidir que no hay denominador— y los dos lados de la comparacion
 * se leen de la misma foto de la base de datos: dos consultas separadas pueden ver
 * dos estados distintos, y entre una y otra cabe un fichaje o la resolucion de una
 * incidencia.
 *
 * ## El techo de tiempo lo pone PostgreSQL
 *
 * El mismo `SET LOCAL statement_timeout` del informe por periodo y de la vista de
 * cumplimiento, con su misma traduccion a `422`: asi la consulta se cancela **en
 * el servidor** y libera la conexion que atiende el fichaje (RNF-P-02, regla dura
 * 19), en lugar de descubrir tarde en PHP que lleva cuarenta segundos.
 */
final readonly class DatabaseAdoptionFactsReader implements AdoptionFactsReader
{
    /** `query_canceled`: el `SQLSTATE` con el que PostgreSQL corta por `statement_timeout`. */
    private const string QUERY_CANCELED = '57014';

    /**
     * A partir de cuanto retraso entre `occurred_at` y `recorded_at` se da un
     * fichaje por **resuelto sin servidor**.
     *
     * Sesenta segundos. No es una regla de negocio ni un umbral legal (regla dura
     * 14): es el discriminante tecnico entre «el quiosco envio el fichaje al
     * momento y tardo en llegar» y «el fichaje estuvo esperando en la cola
     * offline». Un minuto deja fuera con holgura la latencia de una red de hotel y
     * el reintento inmediato, y no deja fuera ninguna sincronizacion de verdad: la
     * cola vacia cuando vuelve la conectividad, que nunca es en menos de un
     * minuto.
     *
     * **Sube el indicador si se baja, y no al reves**: con diez segundos, cualquier
     * pico de red contaria como «resuelto sin servidor» e inflaria el merito de la
     * cola. Por eso el valor prudente es el alto.
     */
    private const string OFFLINE_THRESHOLD = '60 seconds';

    /**
     * Las clases de error del quiosco que cuentan como **un intento de fichar que
     * no se pudo cursar** (RNF-D-01, decision 2(g) de la ficha 3.13).
     *
     * Seis de las veinticinco del catalogo de `ClientErrorCode`, y las seis estan
     * elegidas por lo mismo: **detras de cada una hay alguien delante de la tablet
     * que queria fichar y no pudo**. Las otras diecinueve describen fallos que no
     * impiden el fichaje —un latido que no sale, un aviso de desfase de reloj, el
     * padron que no se pudo cachear— y meterlas aqui hundiria la disponibilidad
     * con averias que nadie sufrio.
     *
     * Son cadenas y no un enumerado importado porque `Reporting` no puede importar
     * de `Shared\Domain\ValueObject\ClientErrorCode`… en realidad si podria, y aun
     * asi no se hace: lo que hay que declarar aqui no es «que codigos existen» sino
     * **cual de ellos significa un intento perdido**, que es una decision de este
     * indicador y no del catalogo. La prueba de integracion del lector es lo que
     * ata las seis cadenas a lo que la tabla contiene de verdad.
     *
     * @var list<string>
     */
    private const array FAILED_ATTEMPT_CODES = [
        'kiosk.camera.unavailable',
        'kiosk.camera.permission_denied',
        'kiosk.scanner.start_failed',
        'kiosk.scanner.decoder_load_failed',
        'kiosk.offline.storage_unavailable',
        'kiosk.scan.submit_failed',
    ];

    /** La incidencia que mide el §1.3 con su «tiempo medio hasta resolver un turno sin cerrar». */
    private const string OPEN_SHIFT_INCIDENT = 'open_shift_expired';

    public function __construct(
        private ConnectionInterface $connection,
        private WorkDayCompletionReader $workDays,
        /** Segundos de `statement_timeout` de estas consultas (`config/reporting.php`). */
        private int $timeoutSeconds,
    ) {}

    public function factsFor(AdoptionReportQuery $query, string $timeZone): AdoptionFacts
    {
        return $this->withStatementTimeout(function () use ($query, $timeZone): AdoptionFacts {
            $scans = $this->scansByPeriod($query, $timeZone);
            $failed = $this->failedAttemptsByPeriod($query, $timeZone);
            $corrections = $this->correctionsByPeriod($query, $timeZone);
            $incidents = $this->resolvedIncidentsByPeriod($query, $timeZone);
            $snapshot = $this->snapshot();

            return new AdoptionFacts(
                current: $this->periodFacts('current', $query->range, $scans, $failed, $corrections, $incidents),
                previous: $this->periodFacts('previous', $query->previousRange, $scans, $failed, $corrections, $incidents),
                openIncidents: $snapshot['open_incidents'],
                employeesWithoutDeliveredCredential: $snapshot['employees_without_credential'],
            );
        });
    }

    /**
     * Los hechos de un periodo, cosidos desde las cuatro consultas.
     *
     * **Lo que no aparece en una consulta vale cero**, y es correcto: un periodo
     * sin ningun fichaje no tiene fila en `scan_events`, y lo que el dominio
     * necesita saber es exactamente eso —cero atendidos— para decidir que no hay
     * denominador. Un `null` aqui obligaria a cada indicador a distinguir «no hubo»
     * de «no vino», que son lo mismo.
     *
     * @param  array<string, array{attended: int, offline: int, byOrigin: array<string, int>}>  $scans
     * @param  array<string, int>  $failed
     * @param  array<string, int>  $corrections
     * @param  array<string, array{resolved: int, mean: ?int, median: ?int}>  $incidents
     */
    private function periodFacts(
        string $period,
        DateRange $range,
        array $scans,
        array $failed,
        array $corrections,
        array $incidents,
    ): AdoptionPeriodFacts {
        $completion = $this->completionOver($range);
        $scan = $scans[$period] ?? ['attended' => 0, 'offline' => 0, 'byOrigin' => []];
        $incident = $incidents[$period] ?? ['resolved' => 0, 'mean' => null, 'median' => null];

        return new AdoptionPeriodFacts(
            workDaysWithActivity: $completion['total'],
            workDaysComplete: $completion['complete'],
            acceptedScansByOrigin: $scan['byOrigin'],
            attendedScans: $scan['attended'],
            offlineResolvedScans: $scan['offline'],
            failedAttempts: $failed[$period] ?? 0,
            corrections: $corrections[$period] ?? 0,
            resolvedOpenShiftIncidents: $incident['resolved'],
            resolutionMeanMinutes: $incident['mean'],
            resolutionMedianMinutes: $incident['median'],
        );
    }

    /**
     * Jornadas con actividad y jornadas completas del rango, **sumando los centros**.
     *
     * ADR-040 fija un centro por instalacion, asi que la suma tiene exactamente un
     * sumando. Se suma igualmente en lugar de tomar el primero: el dia que hubiera
     * dos centros, el cuadro seguiria siendo de la instalacion entera —que es lo
     * que es— en vez de enseñar el de uno de los dos en silencio.
     *
     * @return array{complete: int, total: int}
     */
    private function completionOver(DateRange $range): array
    {
        $complete = 0;
        $total = 0;

        foreach ($this->workDays->completionBetween($range->isoFrom(), $range->isoTo()) as $site) {
            $complete += $site['complete'];
            $total += $site['total'];
        }

        return ['complete' => $complete, 'total' => $total];
    }

    /**
     * Atendidos, resueltos sin servidor y aceptados por origen, **en una sola pasada
     * por `scan_events`**.
     *
     * ## Una pasada, y por que esto no es una micro-optimizacion
     *
     * La primera version preguntaba con subconsultas escalares correlacionadas con la
     * CTE de periodos: tres por los recuentos y una por cada origen, todas
     * multiplicadas por los dos periodos. **Catorce recorridos de `scan_events` por
     * peticion.** Con 39 000 filas el `EXPLAIN ANALYZE` daba `loops=8` y 12,6 ms por
     * pasada —imperceptible—, pero el filtro por fecha civil **no es sargable** (ver
     * el docblock de la clase) y por tanto cada pasada es un recorrido completo: con
     * los cuatro años de retencion de RL-02 y unas 2,9 M de filas, catorce pasadas
     * son del orden de trece segundos y el `statement_timeout` de diez corta la
     * consulta. El cuadro habria dejado de funcionar **justo en la instalacion mas
     * antigua**, que es la que tiene que renovar la licencia.
     *
     * Con `LEFT JOIN` sobre los periodos y `count(*) FILTER (WHERE …)` se recorre la
     * tabla **una vez** y cada fila se clasifica al pasar. La aritmetica no cambia:
     * los `FILTER` cuentan exactamente lo que contaban las subconsultas.
     *
     * ## El `LEFT JOIN` conserva la fila del periodo vacio
     *
     * Y eso es un requisito, no un detalle: un periodo sin ningun fichaje sigue
     * teniendo su fila con ceros, que es lo que el dominio necesita para decir «sin
     * denominador» en lugar de «no vino nada». Con un `JOIN` normal, el periodo
     * anterior de una instalacion recien puesta en marcha desapareceria del resultado
     * y el cuadro tendria que adivinar si eso era un cero o una ausencia.
     *
     * ## Los cuatro origenes son columnas fijas, con alias por posicion
     *
     * Se componen de {@see AdoptionIndicators::ORIGINS}, la misma lista que recorre
     * el dominio, y los alias son `origin_0`…`origin_3` **por posicion**: ningun
     * identificador SQL se deriva de un dato, ni siquiera de una constante nuestra.
     * El valor del origen viaja como parametro enlazado. Un identificador no admite
     * `?`, asi que la unica defensa posible es que no haya ninguno que dependa de
     * nada que no sea este fichero.
     *
     * ## Que cuenta cada columna
     *
     * `attended` son **todos** los `scan_events`: un rechazo por regla de negocio
     * —tarjeta revocada, rebote, fuera de orden— es un intento **atendido**, porque
     * el sistema estaba ahi y le dijo algo a quien paso la tarjeta. Los aceptados son
     * el subconjunto que produjo fichaje, y su suma por origen es el denominador del
     * reparto y del ratio de correcciones.
     *
     * @return array<string, array{attended: int, offline: int, byOrigin: array<string, int>}>
     */
    private function scansByPeriod(AdoptionReportQuery $query, string $timeZone): array
    {
        $originColumns = '';
        $originBindings = [];

        foreach (AdoptionIndicators::ORIGINS as $index => $origin) {
            $originColumns .= ",\n                   count(s.id) FILTER ("
                ."WHERE NOT starts_with(s.result, 'rejected_') AND s.origin = ?) AS origin_{$index}";
            $originBindings[] = $origin;
        }

        $rows = $this->select(<<<SQL
            WITH periods (period, from_date, to_date) AS (
                VALUES ('current'::text, ?::date, ?::date), ('previous'::text, ?::date, ?::date)
            )
            SELECT p.period,
                   count(s.id)                                                            AS attended,
                   count(s.id) FILTER (WHERE s.recorded_at - s.occurred_at > ?::interval)  AS offline_resolved{$originColumns}
              FROM periods p
              LEFT JOIN scan_events s
                     ON (s.occurred_at AT TIME ZONE ?)::date BETWEEN p.from_date AND p.to_date
             GROUP BY p.period
            SQL, [
            $query->range->isoFrom(), $query->range->isoTo(),
            $query->previousRange->isoFrom(), $query->previousRange->isoTo(),
            self::OFFLINE_THRESHOLD,
            ...$originBindings,
            $timeZone,
        ]);

        $scans = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);
            $byOrigin = [];

            foreach (AdoptionIndicators::ORIGINS as $index => $origin) {
                $byOrigin[$origin] = $reader->int('origin_'.$index);
            }

            $scans[$reader->string('period')] = [
                'attended' => $reader->int('attended'),
                'offline' => $reader->int('offline_resolved'),
                'byOrigin' => $byOrigin,
            ];
        }

        return $scans;
    }

    /**
     * Intentos de fichar que el quiosco **no llego a cursar**, por periodo y en una
     * sola pasada por `error_events`.
     *
     * ## Consulta aparte y no una columna mas de la de arriba
     *
     * Porque son dos tablas distintas y unirlas en el mismo `LEFT JOIN` multiplicaria
     * las filas: cada escaneo del periodo se emparejaria con cada grupo de errores y
     * los recuentos saldrian multiplicados entre si. Dos consultas de una pasada cada
     * una son dos pasadas; una consulta con dos `LEFT JOIN` es un producto cartesiano
     * con aspecto de optimizacion.
     *
     * ## Se cuenta por `occurrences`, no por filas
     *
     * `error_events` agrupa por huella (RF-PD-15): mil camaras caidas son **una fila**
     * con `occurrences = 1000`. Contar filas diria que hubo un intento fallido.
     *
     * ## La atribucion al periodo es aproximada, y lo dice el criterio
     *
     * La tabla guarda `first_seen_at` y `last_seen_at` de cada huella, no una fila por
     * aparicion, asi que un grupo se atribuye entero al periodo de su **ultima**
     * aparicion. Es la unica atribucion posible sin cambiar el modelo de
     * `error_events`, que existe precisamente para no guardar una fila por error, y el
     * efecto es acotado: las seis clases son fallos de sesion de quiosco, no ruido de
     * fondo continuo. Ademas `product:errors:prune` acota el historico hacia atras,
     * asi que en un periodo antiguo el denominador puede estar incompleto **a favor de
     * la disponibilidad**. Las dos cosas van escritas en
     * `adoption.criteria.availability`, que es donde quien lee el cuadro las ve.
     *
     * @return array<string, int>
     */
    private function failedAttemptsByPeriod(AdoptionReportQuery $query, string $timeZone): array
    {
        $codes = implode(', ', array_fill(0, \count(self::FAILED_ATTEMPT_CODES), '?'));

        $rows = $this->select(<<<SQL
            WITH periods (period, from_date, to_date) AS (
                VALUES ('current'::text, ?::date, ?::date), ('previous'::text, ?::date, ?::date)
            )
            SELECT p.period,
                   coalesce(sum(e.occurrences), 0) AS failed_attempts
              FROM periods p
              LEFT JOIN error_events e
                     ON e.source = 'kiosk'
                    AND e.code IN ({$codes})
                    AND (e.last_seen_at AT TIME ZONE ?)::date BETWEEN p.from_date AND p.to_date
             GROUP BY p.period
            SQL, [
            $query->range->isoFrom(), $query->range->isoTo(),
            $query->previousRange->isoFrom(), $query->previousRange->isoTo(),
            ...self::FAILED_ATTEMPT_CODES,
            $timeZone,
        ]);

        $failed = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);
            $failed[$reader->string('period')] = $reader->int('failed_attempts');
        }

        return $failed;
    }

    /**
     * Correcciones creadas en cada periodo, en una sola pasada.
     *
     * **Por `created_at` y no por la jornada corregida**, que es la fraccion que
     * pinta Grafana (`manual_corrections_total / scans_total`) y la que responde a
     * la pregunta del §1.3: «¿cuanto trabajo manual esta costando el registro este
     * mes?». Atribuirlas a la jornada corregida mediria otra cosa —la calidad del
     * registro de marzo— y cambiaria hacia atras cada vez que alguien rectificara
     * un dia antiguo, con lo que el cuadro del mes pasado dejaria de ser
     * reproducible.
     *
     * **Todas las correcciones, no solo las de un tipo.** Un alta manual, una
     * rectificacion y una anulacion son las tres trabajo manual sobre el registro.
     *
     * @return array<string, int>
     */
    private function correctionsByPeriod(AdoptionReportQuery $query, string $timeZone): array
    {
        $rows = $this->select(<<<'SQL'
            WITH periods (period, from_date, to_date) AS (
                VALUES ('current'::text, ?::date, ?::date), ('previous'::text, ?::date, ?::date)
            )
            SELECT p.period,
                   count(c.id) AS corrections
              FROM periods p
              LEFT JOIN shift_corrections c
                     ON (c.created_at AT TIME ZONE ?)::date BETWEEN p.from_date AND p.to_date
             GROUP BY p.period
            SQL, [
            $query->range->isoFrom(), $query->range->isoTo(),
            $query->previousRange->isoFrom(), $query->previousRange->isoTo(),
            $timeZone,
        ]);

        $corrections = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);
            $corrections[$reader->string('period')] = $reader->int('corrections');
        }

        return $corrections;
    }

    /**
     * Turnos sin cerrar **resueltos** en cada periodo, con su media y su mediana.
     *
     * ## Se atribuyen al periodo en el que se RESOLVIERON
     *
     * Y no a aquel en el que se detectaron. Lo que mide el §1.3 es «cuanto se tarda
     * en resolver», que es un hecho que ocurre al resolver: contando por
     * `detected_at`, una incidencia de marzo todavia abierta el 31 no tendria
     * tiempo de resolucion y el cuadro de marzo saldria optimista, y volveria a
     * cambiar en abril cuando alguien la cerrara. El cuadro de un mes cerrado no
     * puede cambiar despues.
     *
     * ## Media y mediana en la misma pasada
     *
     * Las dos son agregados de la misma columna de minutos y no porcentajes, asi
     * que salen de PostgreSQL: traerse a PHP la lista de incidencias resueltas para
     * ordenarlas seria una lista sin techo por un solo numero. **El «no se sabe»
     * sigue decidiendose en el dominio**, a partir de `resolved`: si es cero, la
     * media y la mediana vienen nulas y el indicador sale vacio en lugar de
     * «0 minutos», que seria el mejor resultado posible y significa lo contrario.
     *
     * Se redondean a minutos enteros aqui porque la unidad del indicador es el
     * minuto: un `avg` con quince decimales no aporta nada a un cuadro que enseña
     * `08:32`.
     *
     * @return array<string, array{resolved: int, mean: ?int, median: ?int}>
     */
    private function resolvedIncidentsByPeriod(AdoptionReportQuery $query, string $timeZone): array
    {
        $rows = $this->select(<<<'SQL'
            WITH periods (period, from_date, to_date) AS (
                VALUES ('current'::text, ?::date, ?::date), ('previous'::text, ?::date, ?::date)
            ), resolutions AS (
                SELECT p.period,
                       extract(epoch FROM (i.resolved_at - i.detected_at)) / 60.0 AS minutes
                  FROM periods p
                  JOIN incidents i
                    ON i.type = ?
                   AND i.resolved_at IS NOT NULL
                   AND (i.resolved_at AT TIME ZONE ?)::date BETWEEN p.from_date AND p.to_date
            )
            SELECT p.period,
                   count(r.minutes)                                                     AS resolved,
                   round(avg(r.minutes))                                                AS mean_minutes,
                   round(percentile_cont(0.5) WITHIN GROUP (ORDER BY r.minutes)::numeric) AS median_minutes
              FROM periods p
              LEFT JOIN resolutions r ON r.period = p.period
             GROUP BY p.period
            SQL, [
            $query->range->isoFrom(), $query->range->isoTo(),
            $query->previousRange->isoFrom(), $query->previousRange->isoTo(),
            self::OPEN_SHIFT_INCIDENT,
            $timeZone,
        ]);

        $incidents = [];

        foreach ($rows as $row) {
            $reader = Row::of($row);

            $incidents[$reader->string('period')] = [
                'resolved' => $reader->int('resolved'),
                'mean' => $reader->nullableInt('mean_minutes'),
                'median' => $reader->nullableInt('median_minutes'),
            ];
        }

        return $incidents;
    }

    /**
     * Las dos fotos de **hoy**: incidencias abiertas y personas sin tarjeta
     * entregada.
     *
     * ## No pertenecen a ningun periodo, y por eso no llevan rango
     *
     * Son colas pendientes, no flujo: «tres incidencias abiertas» describe el
     * estado de la bandeja en el momento de mirar. La version «del periodo
     * anterior» exigiria saber cuantas habia abiertas el ultimo dia de aquel, que
     * es un dato que este producto no guarda — y darlo por bueno con la cifra de
     * hoy seria enseñar una variacion inventada.
     *
     * ## «Sin tarjeta entregada» significa lo mismo que en el panel de credenciales
     *
     * Persona de alta **sin ninguna credencial vigente que este entregada**, que es
     * el estado `delivered` de `CredentialLifecycleStatus` y el numerador exacto de
     * `employees_without_delivered_credential{site}` (doc 02 §8.2): quien no puede
     * fichar con tarjeta hoy. La tarjeta impresa y no entregada **cuenta**, porque
     * sigue en el cajon de RRHH y no en el bolsillo de nadie; la revocada tambien.
     *
     * ## Ni fecha ni zona, y no es un olvido
     *
     * Las dos preguntas son sobre el estado **actual** de dos tablas, no sobre un
     * dia: no hay ninguna marca de tiempo que comparar y por tanto ninguna zona que
     * aplicar. Un `$today` aqui pareceria un filtro que no existe.
     *
     * @return array{open_incidents: int, employees_without_credential: int}
     */
    private function snapshot(): array
    {
        $rows = $this->select(<<<'SQL'
            SELECT (SELECT count(*)
                      FROM incidents i
                     WHERE i.status = 'open'
                   ) AS open_incidents,
                   (SELECT count(*)
                      FROM employees e
                     WHERE e.status = 'active'
                       AND NOT EXISTS (
                             SELECT 1
                               FROM credentials c
                              WHERE c.employee_id = e.id
                                AND c.revoked_at IS NULL
                                AND c.delivered_at IS NOT NULL
                           )
                   ) AS employees_without_credential
            SQL, []);

        $reader = Row::of($rows[0] ?? (object) ['open_incidents' => 0, 'employees_without_credential' => 0]);

        return [
            'open_incidents' => $reader->int('open_incidents'),
            'employees_without_credential' => $reader->int('employees_without_credential'),
        ];
    }

    /**
     * @param  list<string>  $bindings
     * @return list<object>
     */
    private function select(string $sql, array $bindings): array
    {
        /** @var list<object> $rows */
        $rows = $this->connection->select($sql, $bindings);

        return $rows;
    }

    /**
     * Ejecuta las consultas con el techo de tiempo de PostgreSQL y traduce la
     * cancelacion a `422`.
     *
     * `SET LOCAL` dentro de una transaccion: el ajuste se deshace solo al terminar,
     * asi que no puede quedarse pegado a la conexion del pool y afectar al fichaje
     * que la use despues. Es el mismo andamiaje que la vista de cumplimiento; vive
     * duplicado en los dos adaptadores y no en una clase comun porque son cinco
     * lineas y lo que comparten —el `SQLSTATE`— es de PostgreSQL, no del producto.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     *
     * @throws ReportTooLargeForSynchronousDelivery cuando PostgreSQL cancela la consulta
     */
    private function withStatementTimeout(callable $read): mixed
    {
        try {
            return $this->connection->transaction(function () use ($read): mixed {
                // `SET LOCAL` acota el techo a ESTA transaccion: un
                // `statement_timeout` global cortaria migraciones y
                // reconciliaciones que legitimamente tardan mas.
                $this->connection->statement("SET LOCAL statement_timeout = '".$this->timeoutSeconds."s'");

                return $read();
            });
        } catch (QueryException $exception) {
            if ($this->wasCancelled($exception)) {
                throw ReportTooLargeForSynchronousDelivery::adoptionTimedOut($this->timeoutSeconds);
            }

            throw $exception;
        }
    }

    /**
     * El `SQLSTATE` sale de `errorInfo[0]`, NO de `getCode()`.
     *
     * Es la forma que usan las otras cinco traducciones de `SQLSTATE` del
     * repositorio —empezando por las dos hermanas de este mismo directorio—, y no
     * es cuestion de gusto: `QueryException::getCode()` hereda el codigo de la
     * `PDOException` que envuelve, que unas veces es la cadena del `SQLSTATE` y
     * otras un entero del driver. Dependiendo de ella, una cancelacion por
     * `statement_timeout` podia salir como `500` en vez de como el `422` que esta
     * clase promete.
     */
    private function wasCancelled(Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && ($exception->errorInfo[0] ?? null) === self::QUERY_CANCELED;
    }
}
