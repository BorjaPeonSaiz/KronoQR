<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\Exception\ScanAlreadyRecorded;
use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\Port\EmployeeDirectory;
use App\Modules\Attendance\Application\Port\EventPublisher;
use App\Modules\Attendance\Application\Port\ScanLog;
use App\Modules\Attendance\Application\Port\ScanRecord;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Application\Port\SiteCalendar;
use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Application\Support\ClockingPolicies;
use App\Modules\Attendance\Domain\Event\ScanRejected;
use App\Modules\Attendance\Domain\Exception\ClockOutBeforeClockIn;
use App\Modules\Attendance\Domain\Exception\OverlappingShiftEntry;
use App\Modules\Attendance\Domain\Exception\ShiftAlreadyOpen;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\Policy\DebouncePolicy;
use App\Modules\Attendance\Domain\Policy\ReviewPolicy;
use App\Modules\Attendance\Domain\ValueObject\ClockSkew;
use App\Modules\Attendance\Domain\ValueObject\ScanRejectionReason;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\OperationalSettingsProvider;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\EmployeeSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * **Convierte un escaneo en un tramo** (RF-AT-01..09). Es el caso de uso central
 * del producto y el sitio donde media docena de reglas duras se cumplen o se
 * rompen.
 *
 * Orquesta; no decide. Quien decide si el escaneo abre o cierra turno es el
 * agregado `WorkDay` (RF-AT-02, RF-AT-03); quien decide si cae en el periodo de
 * gracia es `DebouncePolicy` (RF-AT-06); quien decide si el tramo pide revision
 * humana es `ClockingPolicy` (RN-07, RN-08). Aqui no hay ni un `if` con una
 * regla de negocio dentro.
 *
 * ## Los ocho pasos
 *
 * ```
 * 1. Abrir transaccion
 * 2. Cargar el agregado por su repositorio
 * 3. Invocar el metodo de dominio
 * 4. Persistir
 * 5. Actualizar proyecciones EN LA MISMA TRANSACCION
 * 6. Escribir auditoria (un fichaje tiene relevancia legal, RL-01)
 * 7. Publicar eventos de dominio
 * 8. Devolver un resultado tipado
 * ```
 *
 * Los pasos 5, 6 y 7 merecen explicacion porque aqui no ocurren donde uno
 * esperaria.
 *
 * **La proyeccion (5) la hace el repositorio, dentro de esta transaccion.** Lo
 * promete el contrato de `WorkDayRepository::save()`: *«guarda la jornada y
 * recalcula su proyeccion en la misma transaccion»* (RN-06, ADR-007, regla dura
 * 7). Se **recalcula** como suma de los tramos vigentes, nunca se incrementa: un
 * acumulador seria correcto hasta la primera correccion, y a partir de ahi
 * mentiria en la direccion mas cara. Que la promesa viva en el repositorio y no
 * aqui es deliberado: la correccion de la tarea 1.15 tambien guarda jornadas, y
 * asi obtiene la proyeccion sin tener que acordarse.
 *
 * **La auditoria (6) y los eventos (7) son la misma llamada, y ocurre DENTRO de
 * la transaccion.** `Attendance` no puede importar `Compliance` —el §1.6 no
 * concede esa arista y Deptrac lo verifica—, asi que la entrada de `audit_log`
 * la escribe un listener de `Compliance/Infrastructure` al recibir
 * `EmployeeClockedIn` o `EmployeeClockedOut`. Para que la garantia de la regla
 * dura 6 se sostenga —*«si la escritura de auditoria falla, la accion auditada
 * no se confirma»* (ADR-027, contrato de `AuditTrail`)— la publicacion tiene que
 * caer dentro de esta transaccion, y por eso es lo ultimo que se hace antes de
 * confirmar. Un fichaje que ocurre sin dejar traza es peor que un fichaje que no
 * ocurre, porque el segundo se puede corregir.
 *
 * > Consecuencia para quien anada un listener: **si tiene efectos fuera de la
 * > base de datos** —difundir por Reverb al panel en vivo (tarea 1.11), enviar
 * > una notificacion— tiene que ser un listener encolado con `$afterCommit`.
 * > Sin eso veria una escritura que todavia puede revertir.
 *
 * ## Idempotencia (regla dura 8, RF-AT-07)
 *
 * **No hay ningun `SELECT` que pregunte si el `scan_id` ya existe.** Se intenta
 * escribir la fila y decide el UNIQUE de `scan_events.scan_id`. Entre una
 * consulta previa y la insercion cabe otra peticion con el mismo identificador,
 * y bajo el pico de un cambio de turno esa carrera produce tramos duplicados en
 * el registro legal de alguien. Cuando el UNIQUE dice que no, esta transaccion
 * revierte entera —el tramo que acababa de escribir incluido— y la respuesta se
 * **reconstruye** de lo que ya estaba registrado.
 *
 * ## Las carreras que si pueden pasar, y que no son errores
 *
 * `one_open_shift_per_employee` y `shift_entries_no_overlap` son la ultima linea
 * de defensa de RN-01 y RN-02 (doc 02 §3.2), y bajo concurrencia **saltan**: dos
 * escaneos distintos del mismo empleado en el mismo instante llegan los dos a un
 * agregado sin turno abierto. El repositorio traduce esas violaciones a las
 * excepciones de dominio que ya existen, y aqui se **reintenta**: en el segundo
 * intento el escaneo perdedor ya ve el tramo del ganador, y RF-AT-06 lo resuelve
 * como anti-rebote, que es exactamente lo que es. Reintentar y no fallar es lo
 * que la regla dura 19 exige: el empleado no tiene la culpa de haber pasado la
 * tarjeta a la vez que su companero.
 *
 * `ClockOutBeforeClockIn` entra en el mismo reintento por lo mismo: bajo
 * concurrencia no describe un dato imposible, sino una lectura que se quedo
 * vieja entre dos consultas de la misma transaccion. El orden de lecturas de
 * {@see processResolved()} hace que no deba ocurrir; reintentarla es la red por
 * si algun camino futuro vuelve a abrir esa ventana, y despues de
 * `MAX_ATTEMPTS` sigue saliendo a la superficie en vez de quedar enterrada.
 *
 * ## Lo que NUNCA hace
 *
 * - **No llama al reloj para el registro legal.** `occurred_at` viene del
 *   dispositivo y puede llegar de la cola offline con dias de retraso;
 *   `recorded_at` se lo pide al puerto `Clock` (regla dura 2, 9, RF-AT-09).
 * - **No rechaza por desfase de reloj** (regla dura 19, RF-AT-10). El desfase se
 *   calcula, se persiste en `scan_events.clock_skew_seconds` y, si supera el
 *   umbral de la instalacion, deja el fichaje marcado para validacion humana
 *   (RN-15, {@see ReviewPolicy}); el fichaje se acepta siempre. Nunca se pierde
 *   una jornada por un problema tecnico ajeno al empleado. La incidencia
 *   `clock_skew` que consume esa marca es de la tarea 3.5 (ADR-032).
 * - **No distingue causas de rechazo hacia fuera** (regla dura 17, RS-03). Las
 *   cuatro —prefijo, clave, firma, credencial revocada— y la quinta —empleado no
 *   activo, RN-14— recorren exactamente el mismo camino y escriben la misma
 *   forma de fila; lo unico que cambia es el valor de `scan_events.result`, que
 *   se queda del lado del servidor junto con el log y la metrica.
 */
final readonly class RegisterScanHandler
{
    /**
     * Tres intentos y no mas.
     *
     * El primero pierde la carrera contra otro escaneo del mismo empleado; el
     * segundo ya ve el tramo del ganador y lo resuelve por RF-AT-06. El tercero
     * es margen para el pico de un cambio de turno, donde diez lecturas de la
     * misma tarjeta pueden llegar en el mismo milisegundo. Un bucle sin techo,
     * ademas, es un bucle que nadie ve.
     */
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private ConnectionInterface $connection,
        private WorkDayRepository $workDays,
        private ScanLog $scans,
        private CredentialResolver $credentials,
        private EmployeeDirectory $employees,
        private SiteCalendar $calendar,
        private OperationalSettingsProvider $settings,
        private EventPublisher $events,
        private Clock $clock,
    ) {}

    /**
     * @param  CredentialResolution|null  $resolution  Quien es el portador, si ya se sabe.
     *
     * **Existe por el fichaje de respaldo por PIN** (RF-AT-11, tarea 1.12), que
     * identifica a la persona por un camino distinto —codigo de empleado y PIN
     * contra `pin_hash`— pero hace exactamente lo mismo a partir de ahi. Pasarla
     * ya resuelta es lo que permite que las dos vias compartan **este** metodo y
     * no una copia suya: la transaccion, el agregado, el anti-rebote, la
     * proyeccion de `daily_totals`, el `worked_minutes` que se fija y se guarda,
     * la auditoria, los reintentos de carrera y la idempotencia por UNIQUE son
     * los mismos, y un segundo camino seria un segundo sitio donde equivocarse
     * con cualquiera de ellos —que ya paso una vez con `worked_minutes` en el
     * reenvio (tarea 1.7)—.
     *
     * Nula en el camino normal: entonces la resuelve el `CredentialResolver`.
     */
    public function handle(RegisterScanCommand $command, ?CredentialResolution $resolution = null): RegisterScanResult
    {
        // Regla dura 9: la recepcion la fija el servidor una sola vez, antes de
        // hacer nada, para que un reintento interno no la desplace.
        $recordedAt = $this->clock->now();

        // Fuera de la transaccion a proposito: es una lectura que no participa
        // del efecto, y mantenerla fuera acorta la ventana en la que esta
        // transaccion puede chocar con otra. El adaptador de `Identity` es quien
        // garantiza que sus rechazos son de tiempo constante (RS-03).
        //
        // Quien ya trae la resolucion hecha —el fichaje por PIN— se la salta, y
        // asume la MISMA obligacion de tiempo constante en su propio camino.
        $resolution ??= $this->credentials->resolve($command->qrPayload ?? '');

        $attempt = 0;

        // `while (true)` con salida por `return` o por `throw` en las tres
        // ramas: el techo lo pone `MAX_ATTEMPTS` en el `catch` de la carrera.
        // Un `for` con condicion haria falta una linea final inalcanzable para
        // que el analisis estatico lo diera por cerrado.
        while (true) {
            $attempt++;

            try {
                return $this->connection->transaction(
                    fn (): RegisterScanResult => $this->process($command, $recordedAt, $resolution),
                );
            } catch (ScanAlreadyRecorded $replayed) {
                // RF-AT-07: la transaccion ya revirtio; se responde con lo que
                // quedo registrado la primera vez.
                $replay = $this->replay($replayed->scanId);

                if ($replay instanceof RegisterScanResult) {
                    return $replay;
                }

                // El UNIQUE dijo que la fila estaba y la lectura posterior no la
                // encuentra: la escritura que la puso acabo revirtiendo entre
                // las dos. Se vuelve a intentar, que ahora si puede ganar.
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException(
                        'Scan '.$replayed->scanId.' collided with the unique index but is not readable.',
                        previous: $replayed,
                    );
                }
            } catch (ShiftAlreadyOpen|OverlappingShiftEntry|ClockOutBeforeClockIn $race) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw $race;
                }
            }
        }
    }

    /**
     * El cuerpo de la transaccion.
     */
    private function process(
        RegisterScanCommand $command,
        DateTimeImmutable $recordedAt,
        CredentialResolution $resolution,
    ): RegisterScanResult {
        $employeeUuid = $resolution->employeeUuid();

        if ($employeeUuid === null) {
            $reason = $resolution->rejectionReason();

            return $this->reject(
                $command,
                $recordedAt,
                null,
                ScanResult::fromRejection(
                    $reason === null
                        ? ScanRejectionReason::UNKNOWN_CREDENTIAL
                        : ScanRejectionReason::fromCredentialRejection($reason),
                ),
            );
        }

        $employee = $this->employees->find($employeeUuid);

        // RN-14 y la inconsistencia de padron, por el mismo camino y con el
        // mismo resultado: desde fuera no se distingue «no existe» de «esta de
        // baja» (regla dura 17).
        if (! $employee instanceof EmployeeSnapshot || ! $employee->canClock()) {
            return $this->reject($command, $recordedAt, $employeeUuid, ScanResult::REJECTED_UNKNOWN);
        }

        $timezone = $this->calendar->timezoneOf($employee->siteId);

        if (! $timezone instanceof DateTimeZone) {
            return $this->reject($command, $recordedAt, $employeeUuid, ScanResult::REJECTED_UNKNOWN);
        }

        return $this->processResolved($command, $recordedAt, $employee, $timezone);
    }

    /**
     * Lo que ocurre cuando la credencial resolvio y el empleado puede fichar.
     *
     * Separado de {@see process()} para que ninguno de los dos pase de la
     * complejidad ciclomatica del §3.5, y porque son dos preguntas distintas:
     * «¿de quien es esta tarjeta?» y «¿que hace este escaneo con su jornada?».
     */
    private function processResolved(
        RegisterScanCommand $command,
        DateTimeImmutable $recordedAt,
        EmployeeSnapshot $employee,
        DateTimeZone $timezone,
    ): RegisterScanResult {
        $settings = $this->settings->forSite($employee->siteId);

        // RF-AT-11 y RN-15: si este fichaje pide validacion humana lo decide el
        // dominio, con el umbral ya resuelto (regla dura 14). Se calcula una sola
        // vez porque los dos desenlaces que producen fila —el tramo y el
        // anti-rebote— tienen que responder lo mismo sobre el mismo escaneo.
        //
        // **Marcar no es rechazar** (regla dura 19, RF-AT-10): el escaneo sigue
        // su camino exactamente igual, marcado o no.
        $flaggedForReview = ReviewPolicy::toleratingSkewOfMinutes($settings->maximumClockSkewMinutes)
            ->requiresReview($command->origin, ClockSkew::between($command->occurredAt, $recordedAt));

        // Paso 2: cargar el agregado. Se pregunta primero por el turno ABIERTO
        // y no por la fecha de hoy: un turno de noche que entro ayer a las 22:00
        // se cierra a las 06:00 dentro de la jornada de AYER (RN-05, ADR-006,
        // regla dura 4). Buscar por la fecha del escaneo abriria una jornada
        // nueva a las 06:00 y partiria el turno.
        $workDate = WorkDate::fromInstant($command->occurredAt, $timezone);

        $workDay = $this->workDays->findOpenWorkDayFor($employee->employeeUuid)
            ?? $this->workDays->findWorkDayFor($employee->employeeUuid, $workDate)
            ?? WorkDay::start($employee->employeeUuid, $employee->siteId, $workDate);

        // RF-AT-06. La regla la evalua el dominio; aqui solo se le sirven los
        // candidatos y el umbral ya resuelto (regla dura 14).
        //
        // **EL ORDEN DE ESTAS DOS LECTURAS NO ES INDIFERENTE**, y costo un 500
        // intermitente bajo carga. PostgreSQL trabaja en READ COMMITTED, asi que
        // cada consulta ve una instantanea nueva: si la ventana se midiera
        // ANTES de cargar la jornada, dos escaneos simultaneos del mismo
        // empleado podian intercalarse con el commit del ganador —la consulta
        // del anti-rebote sin ver su escaneo, la del agregado viendo ya su tramo
        // abierto— y el perdedor intentaba CERRAR ese tramo en el mismo
        // instante en que se abrio, que RN-03 prohibe con razon.
        //
        // Leyendo primero el agregado la anomalia no puede darse: si la jornada
        // ya trae el tramo del ganador es que su transaccion confirmo, y una
        // consulta posterior no puede dejar de ver el escaneo que confirmo con
        // el. El tiempo solo avanza en un sentido.
        $suppressor = DebouncePolicy::ofSeconds($settings->debounceSeconds)->suppressorOf(
            $command->occurredAt,
            ...$this->scans->acceptedScansAdjacentTo($employee->employeeUuid, $command->occurredAt),
        );

        if ($suppressor instanceof DateTimeImmutable) {
            return $this->debounce($command, $recordedAt, $employee, $workDay, $suppressor, $flaggedForReview);
        }

        // Paso 3: el dominio decide. `hasOpenEntry()` es RF-AT-02 y RF-AT-03.
        if ($workDay->hasOpenEntry()) {
            // La politica sale de `ClockingPolicies` y no de una constante
            // propia: es el unico sitio donde vive el umbral de RN-07, para que
            // un fichaje y una correccion (1.15) nunca clasifiquen distinto la
            // misma duracion.
            $entry = $workDay->clockOut(
                $command->occurredAt,
                $command->origin,
                ClockingPolicies::forSettings($settings),
            );
            $result = ScanResult::CLOCK_OUT;
        } else {
            // UUID v7 y no v4: ordenable temporalmente, lo que mantiene la
            // localidad de los indices que lo referencian (doc 02 §6). Lo genera
            // el caso de uso porque el dominio no pregunta la hora.
            $entry = $workDay->clockIn(Str::uuid7()->toString(), $command->occurredAt, $command->origin);
            $result = ScanResult::CLOCK_IN;
        }

        // Pasos 4 y 5: persistir y recalcular `daily_totals`, en esta misma
        // transaccion (RN-06, regla dura 7).
        $this->workDays->save($workDay);

        // Se fija AQUI y se guarda con el escaneo (regla dura 8): un reenvio
        // tiene que devolver este mismo numero, no el que tenga la jornada el
        // dia que la cola offline reintente, que para entonces puede llevar mas
        // tramos.
        $workedMinutes = $workDay->totalWorked()->minutes;

        $this->recordOrReplay(new ScanRecord(
            scanId: $command->scanId,
            deviceId: $command->deviceId,
            employeeUuid: $employee->employeeUuid,
            occurredAt: $command->occurredAt,
            recordedAt: $recordedAt,
            origin: $command->origin,
            intent: $command->intent,
            result: $result,
            shiftEntryUuid: $entry->uuid(),
            payloadFingerprint: $this->fingerprintOf($command->qrPayload),
            clockSkewSeconds: ClockSkew::between($command->occurredAt, $recordedAt)->seconds,
            // RF-AT-11 y RN-15, ya decididos por `ReviewPolicy`.
            flaggedForReview: $flaggedForReview,
            workedMinutes: $workedMinutes,
            clientMeta: $command->clientMeta,
        ));

        // Pasos 6 y 7. Ultimo acto antes de confirmar: el candado de la cadena
        // de auditoria se toma aqui y se suelta con el commit, de modo que la
        // seccion critica que serializa a todos los fichajes es lo mas corta
        // posible.
        $this->events->publish(...$workDay->releaseEvents());

        return RegisterScanResult::accepted(
            scanId: $command->scanId,
            result: $result,
            occurredAt: $command->occurredAt,
            recordedAt: $recordedAt,
            employeeUuid: $employee->employeeUuid,
            employeeDisplayName: $employee->displayName,
            workDate: $workDay->workDate()->isoDate,
            workedMinutes: $workedMinutes,
        );
    }

    /**
     * RF-AT-06: el escaneo se registra con `rejected_debounce` y no toca la
     * jornada.
     *
     * Devuelve el acumulado **sin variar** —el mismo que devolvio el escaneo
     * anterior— para que el quiosco pueda seguir mostrando el total en el aviso
     * en vez de dejar la pantalla a medias (esquema `ScanDebounced`).
     */
    private function debounce(
        RegisterScanCommand $command,
        DateTimeImmutable $recordedAt,
        EmployeeSnapshot $employee,
        WorkDay $workDay,
        DateTimeImmutable $lastAcceptedAt,
        bool $flaggedForReview,
    ): RegisterScanResult {
        // El acumulado de la jornada que ya se cargo, **sin variar**: es el
        // mismo que devolvio el escaneo anterior, y sale del mismo objeto que
        // decidio que aqui no habia nada que hacer. Se fija aqui y se guarda
        // con el escaneo por lo mismo que en el camino aceptado: un reenvio
        // tiene que devolver este numero, no el que tenga la jornada el dia
        // que la cola offline reintente.
        $workedMinutes = $workDay->totalWorked()->minutes;

        $this->recordOrReplay(new ScanRecord(
            scanId: $command->scanId,
            deviceId: $command->deviceId,
            employeeUuid: $employee->employeeUuid,
            occurredAt: $command->occurredAt,
            recordedAt: $recordedAt,
            origin: $command->origin,
            intent: $command->intent,
            result: ScanResult::REJECTED_DEBOUNCE,
            payloadFingerprint: $this->fingerprintOf($command->qrPayload),
            clockSkewSeconds: ClockSkew::between($command->occurredAt, $recordedAt)->seconds,
            // RF-AT-11 y RN-15 tambien aqui: el anti-rebote es un desenlace
            // ACEPTADO (ADR-031), asi que un PIN —o un escaneo retrodatado— que
            // llega dentro de la ventana de gracia sigue siendo un escaneo que el
            // responsable tiene que poder ver. Dejarlo sin marca escondería justo
            // el patron que la hace util: repetir el gesto anomalo dos veces
            // seguidas.
            flaggedForReview: $flaggedForReview,
            workedMinutes: $workedMinutes,
            clientMeta: $command->clientMeta,
        ));

        $this->events->publish(new ScanRejected(
            scanId: $command->scanId,
            reason: ScanRejectionReason::DEBOUNCE,
            occurredAt: $command->occurredAt,
            employeeUuid: $employee->employeeUuid,
            deviceId: $command->deviceId,
            payloadFingerprint: $this->fingerprintOf($command->qrPayload),
        ));

        return RegisterScanResult::debounced(
            scanId: $command->scanId,
            occurredAt: $command->occurredAt,
            recordedAt: $recordedAt,
            employeeUuid: $employee->employeeUuid,
            employeeDisplayName: $employee->displayName,
            workedMinutes: $workedMinutes,
            lastAcceptedAt: $lastAcceptedAt,
        );
    }

    /**
     * El escaneo no produjo tramo. La causa se escribe en `scan_events.result` y
     * viaja en el evento; **no sale por la API** (RS-03, regla dura 17).
     */
    private function reject(
        RegisterScanCommand $command,
        DateTimeImmutable $recordedAt,
        ?string $employeeUuid,
        ScanResult $result,
    ): RegisterScanResult {
        $fingerprint = $this->fingerprintOf($command->qrPayload);

        $this->recordOrReplay(new ScanRecord(
            scanId: $command->scanId,
            deviceId: $command->deviceId,
            employeeUuid: $employeeUuid,
            occurredAt: $command->occurredAt,
            recordedAt: $recordedAt,
            origin: $command->origin,
            intent: $command->intent,
            result: $result,
            payloadFingerprint: $fingerprint,
            clockSkewSeconds: ClockSkew::between($command->occurredAt, $recordedAt)->seconds,
            // Un rechazo no se marca: `flagged_for_review` alimenta una bandeja
            // de FICHAJES que validar y aqui no hay tramo que validar. El rastro
            // del intento esta en `scan_events.result` y en la metrica.
            clientMeta: $command->clientMeta,
        ));

        $this->events->publish(new ScanRejected(
            scanId: $command->scanId,
            reason: $this->rejectionReasonOf($result),
            occurredAt: $command->occurredAt,
            // Solo se conoce cuando la credencial resolvio: un empleado de baja
            // con tarjeta valida (RN-14).
            employeeUuid: $employeeUuid,
            deviceId: $command->deviceId,
            payloadFingerprint: $fingerprint,
        ));

        return RegisterScanResult::rejected(
            scanId: $command->scanId,
            result: $result,
            occurredAt: $command->occurredAt,
            recordedAt: $recordedAt,
            employeeUuid: $employeeUuid,
        );
    }

    /**
     * Escribe la fila del escaneo o aborta la transaccion si ese `scan_id` ya
     * estaba (regla dura 8).
     */
    private function recordOrReplay(ScanRecord $scan): void
    {
        if (! $this->scans->record($scan)) {
            throw new ScanAlreadyRecorded($scan->scanId);
        }
    }

    /**
     * RF-AT-07: reconstruye la respuesta de un escaneo ya registrado.
     *
     * Se reconstruye en lugar de guardarse porque lo que hay que devolver es el
     * **estado del registro**, no una copia del cuerpo que se envio: los hechos
     * de los que se compone —la accion, la jornada, las dos marcas de tiempo—
     * estan todos en la fila de `scan_events` y en el tramo al que apunta.
     */
    private function replay(string $scanId): ?RegisterScanResult
    {
        $recorded = $this->scans->find($scanId);

        if ($recorded === null) {
            return null;
        }

        $employee = $recorded->employeeUuid === null ? null : $this->employees->find($recorded->employeeUuid);
        $timezone = $employee instanceof EmployeeSnapshot ? $this->calendar->timezoneOf($employee->siteId) : null;

        if (! $employee instanceof EmployeeSnapshot || ! $timezone instanceof DateTimeZone) {
            return RegisterScanResult::rejected(
                scanId: $scanId,
                result: $recorded->result,
                occurredAt: $recorded->occurredAt,
                // La recepcion que se devuelve es la ORIGINAL, no la de este
                // reenvio: es lo que hace que la respuesta sea identica.
                recordedAt: $recorded->recordedAt,
                employeeUuid: $recorded->employeeUuid,
                isReplay: true,
            );
        }

        if ($recorded->result->isDebounce()) {
            return $this->replayDebounce($recorded->scanId, $recorded->occurredAt, $recorded->recordedAt, $employee, $recorded->workedMinutes);
        }

        if ($recorded->result->isRejection() || $recorded->workDate === null) {
            return RegisterScanResult::rejected(
                scanId: $scanId,
                result: $recorded->result,
                occurredAt: $recorded->occurredAt,
                recordedAt: $recorded->recordedAt,
                employeeUuid: $recorded->employeeUuid,
                isReplay: true,
            );
        }

        return RegisterScanResult::accepted(
            scanId: $scanId,
            result: $recorded->result,
            occurredAt: $recorded->occurredAt,
            recordedAt: $recorded->recordedAt,
            employeeUuid: $employee->employeeUuid,
            employeeDisplayName: $employee->displayName,
            workDate: $recorded->workDate,
            // El numero que se guardo con este escaneo, no el que tenga la
            // jornada hoy (regla dura 8): ver el docblock de {@see ScanRecord}.
            workedMinutes: $recorded->workedMinutes ?? 0,
            isReplay: true,
        );
    }

    private function replayDebounce(
        string $scanId,
        DateTimeImmutable $occurredAt,
        DateTimeImmutable $recordedAt,
        EmployeeSnapshot $employee,
        ?int $workedMinutes,
    ): RegisterScanResult {
        $adjacent = $this->scans->acceptedScansAdjacentTo($employee->employeeUuid, $occurredAt);

        // Sin ventana: el escaneo que suprimio a este ya esta decidido y
        // escrito. Lo que se busca es cual fue, y es el aceptado mas cercano.
        $closest = null;

        foreach ($adjacent as $candidate) {
            if ($closest === null
                || abs($candidate->getTimestamp() - $occurredAt->getTimestamp())
                 < abs($closest->getTimestamp() - $occurredAt->getTimestamp())) {
                $closest = $candidate;
            }
        }

        return RegisterScanResult::debounced(
            scanId: $scanId,
            occurredAt: $occurredAt,
            recordedAt: $recordedAt,
            employeeUuid: $employee->employeeUuid,
            employeeDisplayName: $employee->displayName,
            // El numero que se guardo con el escaneo suprimido, no el que
            // tenga la jornada hoy (regla dura 8): ver el docblock de
            // {@see ScanRecord}.
            workedMinutes: $workedMinutes ?? 0,
            // El aceptado que lo suprimio no puede faltar —lo exige la propia
            // existencia de la fila y nada se borra (regla dura 5)—, pero el
            // contrato obliga a un instante y no a un nulo, asi que el escaneo
            // se describe a si mismo antes que mentir con una fecha ajena.
            lastAcceptedAt: $closest ?? $occurredAt,
            isReplay: true,
        );
    }

    /**
     * Huella del payload, **nunca el payload** (RS-03).
     *
     * Sirve para agrupar escaneos de la misma tarjeta al investigar un problema
     * sin guardar lo que hay impreso en ella: quien lea `scan_events` no puede
     * fabricar una credencial valida.
     */
    private function fingerprintOf(?string $qrPayload): ?string
    {
        // Nulo en el fichaje por PIN: no hay tarjeta de la que tomar huella
        // (RF-AT-11). Inventar una a partir del codigo de empleado habria metido
        // dos cosas distintas en la misma columna.
        return $qrPayload === null ? null : hash('sha256', $qrPayload);
    }

    private function rejectionReasonOf(ScanResult $result): ScanRejectionReason
    {
        return match ($result) {
            ScanResult::REJECTED_REVOKED => ScanRejectionReason::REVOKED_CREDENTIAL,
            ScanResult::REJECTED_SIGNATURE => ScanRejectionReason::INVALID_SIGNATURE,
            ScanResult::REJECTED_DEBOUNCE => ScanRejectionReason::DEBOUNCE,
            default => ScanRejectionReason::UNKNOWN_CREDENTIAL,
        };
    }
}
