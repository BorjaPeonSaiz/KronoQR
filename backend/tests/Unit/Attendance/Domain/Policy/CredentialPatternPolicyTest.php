<?php

declare(strict_types=1);

use App\Modules\Attendance\Domain\Exception\InstantIsNotUtc;
use App\Modules\Attendance\Domain\Policy\CredentialPatternPolicy;
use App\Modules\Attendance\Domain\ValueObject\AnomalyType;
use App\Modules\Attendance\Domain\ValueObject\CredentialPatternThresholds;
use App\Modules\Attendance\Domain\ValueObject\CredentialScan;
use App\Modules\Attendance\Domain\ValueObject\DetectedAnomaly;
use App\Modules\Attendance\Domain\ValueObject\PatternReviewState;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use Tests\Support\Time\Instants;

/*
 * Patrones anomalos de uso de credencial (RF-PR-06, RN-16, tarea 3.11),
 * enunciados como regla de dominio y probados sin base de datos ni framework.
 *
 * ESTA REGLA ES LA CONTRAPARTIDA DE HABER DESCARTADO LA BIOMETRIA (ADR-009,
 * regla dura 20). El prestamo fisico de la tarjeta es el unico fraude que la
 * firma HMAC no impide, y lo que hace defendible aquella decision es que este
 * fichero exista con limites escritos a mano.
 *
 * LOS CUATRO UMBRALES LLEGAN RESUELTOS (regla dura 14) y el estado de la bandeja
 * tambien: ninguna prueba de aqui da por sabido un numero. El que va a por un
 * limite lo escribe.
 *
 * EL INSTANTE DE DETECCION SE INYECTA (regla dura 2).
 *
 * NINGUNA PRUEBA DE AQUI MIRA UN TRAMO, y no por comodidad: la politica no
 * recibe ninguno. Es la forma estructural de garantizar que la deteccion no
 * anula ni marca ningun fichaje (reglas duras 5 y 19).
 */

/** El centro de las pruebas: Madrid, como el resto del modulo. */
function patternSiteId(): int
{
    return 1;
}

/** Los cuatro umbrales, con los valores de serie del Anexo B salvo que se diga otra cosa. */
function patternThresholds(int $window = 10, int $repeats = 3, int $transit = 120, int $skewMinutes = 15): CredentialPatternThresholds
{
    return new CredentialPatternThresholds($window, $repeats, $transit, $skewMinutes);
}

function patternPolicy(int $window = 10, int $repeats = 3, int $transit = 120, int $skewMinutes = 15): CredentialPatternPolicy
{
    return new CredentialPatternPolicy(patternThresholds($window, $repeats, $transit, $skewMinutes));
}

/**
 * Un uso de credencial. La hora se escribe en reloj de Madrid, que es como
 * estan enunciados los escenarios del doc 01 §11.
 */
function kioskScan(
    string $employeeUuid,
    string $madridWallClock,
    int $deviceId = 7,
    string $deviceName = 'Recepcion',
    ?int $clockSkewSeconds = null,
    ?string $shiftEntryUuid = null,
): CredentialScan {
    $occurredAt = Instants::inMadrid($madridWallClock);

    return CredentialScan::of(
        employeeUuid: $employeeUuid,
        deviceId: $deviceId,
        deviceName: $deviceName,
        occurredAt: $occurredAt,
        workDate: WorkDate::fromInstant($occurredAt, Instants::madrid()),
        scanId: 'scan-'.$employeeUuid.'-'.$madridWallClock.'-'.$deviceId,
        shiftEntryUuid: $shiftEntryUuid,
        clockSkewSeconds: $clockSkewSeconds,
    );
}

const ANA = '0199a0c0-0000-7000-8000-00000000000a';
const BRUNO = '0199a0c0-0000-7000-8000-00000000000b';
const CARLA = '0199a0c0-0000-7000-8000-00000000000c';

/** El momento en que corre la deteccion: la madrugada siguiente. */
function patternDetectedAt(): DateTimeImmutable
{
    return Instants::utc('2026-03-20 04:35');
}

/**
 * Las coincidencias de dos personas en el mismo quiosco durante `$days` dias
 * seguidos, separadas por `$gapSeconds`.
 *
 * @return list<CredentialScan>
 */
function coincidingDays(int $days, int $gapSeconds, int $deviceId = 7, int $from = 1): array
{
    $scans = [];

    for ($day = $from; $day < $from + $days; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $scans[] = kioskScan(ANA, $date.' 06:00:00', $deviceId);
        $scans[] = kioskScan(BRUNO, $date.' 06:00:'.sprintf('%02d', $gapSeconds), $deviceId);
    }

    return $scans;
}

/**
 * @param  list<DetectedAnomaly>  $anomalies
 * @return list<DetectedAnomaly>
 */
function ofPattern(array $anomalies, string $pattern): array
{
    return array_values(array_filter(
        $anomalies,
        static fn (DetectedAnomaly $anomaly): bool => ($anomaly->context['pattern'] ?? null) === $pattern,
    ));
}

/**
 * Las coincidencias que encuentra la politica, ya filtradas.
 *
 * @param  list<CredentialScan>  $scans
 * @param  array<string, PatternReviewState>  $reviewed
 * @return list<DetectedAnomaly>
 */
function coincidencesIn(CredentialPatternPolicy $policy, array $scans, array $reviewed = []): array
{
    return ofPattern(
        $policy->inspect($scans, patternSiteId(), patternDetectedAt(), $reviewed),
        CredentialPatternPolicy::KIOSK_COINCIDENCE,
    );
}

// -----------------------------------------------------------------------------
// `kiosk_coincidence`: la ventana, al segundo.
// -----------------------------------------------------------------------------

it('cuenta la coincidencia por debajo de la ventana y no en el limite ni por encima', function (int $gap, bool $expected): void {
    // EL LIMITE EXACTO de la decision 2 de la ficha: con 10 s de ventana, 9 s
    // cuenta, 10 s no y 11 s tampoco. Es la misma semantica estricta de
    // `ReviewPolicy`, `DebouncePolicy` y la restriccion de exclusion de RN-02: un
    // limite que pertenece a los dos lados se comporta distinto segun quien lo
    // evalue.
    $anomalies = coincidencesIn(patternPolicy(window: 10, repeats: 3), coincidingDays(3, $gap));

    expect($anomalies !== [])->toBe($expected);
})->with([
    'nueve segundos coinciden' => [9, true],
    'diez segundos no: el limite no pertenece a la ventana' => [10, false],
    'once segundos no' => [11, false],
])->group('RF-PR-06');

it('exige los dias completos del umbral: dos no abren incidencia y tres si', function (int $days, bool $expected): void {
    // El otro limite de la decision 2. Con `MIN_REPEATS = 3`, dos dias con
    // coincidencia no son «sistematico».
    $anomalies = coincidencesIn(patternPolicy(window: 10, repeats: 3), coincidingDays($days, 4));

    expect($anomalies !== [])->toBe($expected);
})->with([
    'dos dias no' => [2, false],
    'tres dias si' => [3, true],
    'cinco dias tambien' => [5, true],
])->group('RF-PR-06');

it('cuenta una sola vez el dia por muchas coincidencias que haya esa manana', function (): void {
    // LA DECISION QUE EVITA LA BANDEJA INUTIL: la cola del quiosco al cambio de
    // turno produce pares de segundos todos los dias entre companeros que llegan
    // juntos. Tres coincidencias el mismo dia no son «sistematico», que es
    // «varios dias».
    $sameMorning = [
        kioskScan(ANA, '2026-03-01 06:00:00'),
        kioskScan(BRUNO, '2026-03-01 06:00:04'),
        kioskScan(ANA, '2026-03-01 14:00:00'),
        kioskScan(BRUNO, '2026-03-01 14:00:04'),
        kioskScan(ANA, '2026-03-01 22:00:00'),
        kioskScan(BRUNO, '2026-03-01 22:00:04'),
    ];

    expect(coincidencesIn(patternPolicy(window: 10, repeats: 3), $sameMorning))->toBe([]);
})->group('RF-PR-06');

it('abre una incidencia por cada persona del par, cada una con la otra como contraparte', function (): void {
    // Las dos pueden ser de departamentos distintos y el runbook exige que quien
    // revisa vea a su gente. El nombre de la otra persona NO viaja (regla dura
    // 21): viaja su UUID, y lo resuelve el panel con su directorio.
    $anomalies = coincidencesIn(patternPolicy(window: 10, repeats: 3), coincidingDays(3, 4));

    expect($anomalies)->toHaveCount(2)
        ->and($anomalies[0]->employeeUuid)->toBe(ANA)
        ->and($anomalies[0]->context['counterpart_employee_uuid'])->toBe(BRUNO)
        ->and($anomalies[1]->employeeUuid)->toBe(BRUNO)
        ->and($anomalies[1]->context['counterpart_employee_uuid'])->toBe(ANA);
})->group('RF-PR-06');

it('emite UNA incidencia por persona aunque coincida con varias, y dice con cuantas', function (): void {
    // DECISION 13a, bloqueante B2 de la revision. Tres companeros que entran
    // juntos son tres parejas y producian SEIS hallazgos; `one_incident_per_finding`
    // los colapsaba en tres **en silencio** —sin fila, sin asiento y sin log— y
    // la mitad de los indicios se perdia. Ahora son tres, uno por persona, y
    // `counterpart_count` dice que hay mas gente implicada.
    $scans = [];

    for ($day = 1; $day <= 3; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $scans[] = kioskScan(ANA, $date.' 06:00:00');
        $scans[] = kioskScan(BRUNO, $date.' 06:00:04');
        $scans[] = kioskScan(CARLA, $date.' 06:00:08');
    }

    $anomalies = coincidencesIn(patternPolicy(window: 10, repeats: 3), $scans);

    $senaladas = array_map(static fn (DetectedAnomaly $a): string => $a->employeeUuid, $anomalies);
    sort($senaladas);

    expect($anomalies)->toHaveCount(3)
        ->and($senaladas)->toBe([ANA, BRUNO, CARLA]);

    foreach ($anomalies as $anomaly) {
        expect($anomaly->context['counterpart_count'])->toBe(2)
            ->and($anomaly->context['counterpart_employee_uuid'])->not->toBe($anomaly->employeeUuid);
    }

    // Y el desempate, que con tres personas a la vez es lo unico que hace
    // determinista el contexto: los tres pares tienen los mismos dias y la misma
    // primera coincidencia -el escaneo de Ana abre la cola-, asi que gana la
    // primera. Sin desempate, dos pasadas identicas senalarian a personas
    // distintas y el runbook mandaria hablar con quien tocara esa noche.
    $porPersona = [];

    foreach ($anomalies as $anomaly) {
        $porPersona[$anomaly->employeeUuid] = $anomaly->context['counterpart_employee_uuid'];
    }

    expect($porPersona[ANA])->toBe(BRUNO)
        ->and($porPersona[BRUNO])->toBe(ANA)
        ->and($porPersona[CARLA])->toBe(ANA);
})->group('RF-PR-06');

it('elige como contraparte principal a la de mas dias con coincidencia', function (): void {
    // Con una sola contraparte en el contexto, cual sea no da igual: es la
    // persona con la que quien revisa va a contrastar el cuadrante. La de mas
    // dias es la que mejor describe el indicio, **aunque aparezca la segunda**.
    //
    // Los dos grupos ademas se solapan en el tiempo al reves —Carla coincide
    // ANTES y Bruno DESPUES—, asi que los momentos del contexto solo salen bien
    // si las coincidencias de las dos se ordenan juntas.
    $scans = [];

    for ($day = 3; $day <= 7; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $scans[] = kioskScan(ANA, $date.' 06:00:00');
        $scans[] = kioskScan(BRUNO, $date.' 06:00:04');
    }

    for ($day = 1; $day <= 3; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $scans[] = kioskScan(ANA, $date.' 14:00:00');
        $scans[] = kioskScan(CARLA, $date.' 14:00:04');
    }

    $deAna = array_values(array_filter(
        coincidencesIn(patternPolicy(window: 10, repeats: 3), $scans),
        static fn (DetectedAnomaly $a): bool => $a->employeeUuid === ANA,
    ));

    expect($deAna[0]->context['counterpart_employee_uuid'])->toBe(BRUNO)
        ->and($deAna[0]->context['coincidence_days'])->toBe(5)
        ->and($deAna[0]->context['counterpart_count'])->toBe(2)
        // Los momentos y la jornada se miden sobre TODAS sus coincidencias, no
        // sobre las de la contraparte principal: la primera es con Carla el dia
        // 1 y la ultima con Bruno el dia 7.
        ->and($deAna[0]->context['first_coincidence_at'])->toBe('2026-03-01T13:00:00.000000Z')
        ->and($deAna[0]->context['last_coincidence_at'])->toBe('2026-03-07T05:00:00.000000Z')
        ->and($deAna[0]->workDate->isoDate)->toBe('2026-03-07');
})->group('RF-PR-06');

it('desempata a favor de la contraparte de la primera coincidencia', function (): void {
    // Dos contrapartes con los MISMOS dias: sin desempate, cual sale en el
    // contexto dependeria del orden en que el mapa recorra los grupos, y dos
    // pasadas identicas podrian senalar a personas distintas. La primera
    // coincidencia es un criterio estable y explicable.
    $scans = [];

    for ($day = 1; $day <= 3; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $scans[] = kioskScan(ANA, $date.' 06:00:00');
        $scans[] = kioskScan(BRUNO, $date.' 06:00:04');
    }

    for ($day = 3; $day <= 5; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $scans[] = kioskScan(ANA, $date.' 14:00:00');
        $scans[] = kioskScan(CARLA, $date.' 14:00:04');
    }

    $deAna = array_values(array_filter(
        coincidencesIn(patternPolicy(window: 10, repeats: 3), $scans),
        static fn (DetectedAnomaly $a): bool => $a->employeeUuid === ANA,
    ));

    expect($deAna[0]->context['coincidence_days'])->toBe(3)
        ->and($deAna[0]->context['counterpart_count'])->toBe(2)
        ->and($deAna[0]->context['counterpart_employee_uuid'])->toBe(BRUNO);
})->group('RF-PR-06');

it('ancla el hallazgo en el ultimo dia con coincidencia y no en el borde de la ventana', function (): void {
    // DECISION 13b, bloqueante B1. Estuvo anclado al `minRepeats`-esimo dia: con
    // la ventana de 30 dias saturada ese dia avanza una noche tras otra, y las
    // mismas dos personas estrenaban dos incidencias `high` cada madrugada. La
    // prueba simula dos noches consecutivas —la segunda ve un dia menos por el
    // borde— y exige que la jornada del hallazgo sea la MISMA: eso es lo que hace
    // que `one_incident_per_finding` absorba la repeticion y la bandeja no crezca.
    $policy = patternPolicy(window: 10, repeats: 3);

    $anoche = coincidencesIn($policy, coincidingDays(5, 4, from: 1));
    $estaNoche = coincidencesIn($policy, coincidingDays(4, 4, from: 2));

    expect($anoche[0]->workDate->isoDate)->toBe('2026-03-05')
        ->and($estaNoche[0]->workDate->isoDate)->toBe('2026-03-05')
        ->and($anoche[0]->context['coincidence_days'])->toBe(5)
        ->and($estaNoche[0]->context['coincidence_days'])->toBe(4);
})->group('RF-PR-06');

it('no empareja a alguien consigo mismo ni a dos personas en quioscos distintos', function (): void {
    // Dos escaneos seguidos de la MISMA persona no son una coincidencia: eso es
    // el anti-rebote (RF-AT-06), no un indicio de prestamo. Y dos personas en
    // quioscos distintos no comparten cola: lo que RF-PR-06 describe es el mismo
    // quiosco.
    $scans = [];

    for ($day = 1; $day <= 5; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $scans[] = kioskScan(ANA, $date.' 06:00:00', deviceId: 7);
        $scans[] = kioskScan(ANA, $date.' 06:00:04', deviceId: 7);
        $scans[] = kioskScan(BRUNO, $date.' 06:00:06', deviceId: 8);
    }

    // El transito apagado, para que este caso no dispare ademas RN-16 al pasar
    // Ana por un solo quiosco y Bruno por otro.
    $anomalies = patternPolicy(window: 10, repeats: 3, transit: 0)
        ->inspect($scans, patternSiteId(), patternDetectedAt());

    expect($anomalies)->toBe([]);
})->group('RF-PR-06');

it('reconoce la misma pareja aunque cambie quien escanea primero', function (): void {
    // Quien pasa la tarjeta antes no describe nada: la pareja es la misma los
    // tres dias. Si el par dependiera del orden, alternar la entrada partiria las
    // coincidencias en dos grupos y ninguno llegaria al umbral — el indicio se
    // perderia justo cuando es mas claro.
    $scans = [];

    for ($day = 1; $day <= 3; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $primero = $day % 2 === 0 ? BRUNO : ANA;
        $segundo = $day % 2 === 0 ? ANA : BRUNO;

        $scans[] = kioskScan($primero, $date.' 06:00:00');
        $scans[] = kioskScan($segundo, $date.' 06:00:04');
    }

    $anomalies = coincidencesIn(patternPolicy(window: 10, repeats: 3), $scans);

    expect($anomalies)->toHaveCount(2)
        ->and($anomalies[0]->context['coincidence_days'])->toBe(3);
})->group('RF-PR-06');

it('da el mismo resultado llegue como llegue la lista de escaneos', function (): void {
    // La politica ordena lo que recibe. Sin eso, el hallazgo dependeria del
    // `ORDER BY` de una consulta —o de que la cola offline drenara en otro
    // orden—, y dos pasadas sobre los mismos datos podrian fechar la incidencia
    // en dias distintos, que es justo lo que `one_incident_per_finding` no puede
    // absorber.
    $scans = coincidingDays(4, 4);
    $policy = patternPolicy(window: 10, repeats: 3);

    expect(coincidencesIn($policy, array_reverse($scans)))->toEqual(coincidencesIn($policy, $scans));
})->group('RF-PR-06');

it('mide el hueco mas estrecho sobre TODAS las coincidencias, no sobre la primera de cada dia', function (): void {
    // HALLAZGO R1 de la revision. El hueco mas estrecho es el dato que hace
    // dificil de explicar el indicio —«pasaron las dos tarjetas con un segundo de
    // diferencia»— y era justo el que se tiraba: antes solo se guardaba la
    // primera coincidencia de cada dia, asi que el segundo de las dos de la tarde
    // no llegaba nunca al contexto.
    $scans = [
        kioskScan(ANA, '2026-03-01 06:00:00'), kioskScan(BRUNO, '2026-03-01 06:00:08'),
        kioskScan(ANA, '2026-03-01 14:00:00'), kioskScan(BRUNO, '2026-03-01 14:00:01'),
        kioskScan(ANA, '2026-03-02 06:00:00'), kioskScan(BRUNO, '2026-03-02 06:00:03'),
        kioskScan(ANA, '2026-03-03 06:00:00'), kioskScan(BRUNO, '2026-03-03 06:00:06'),
    ];

    $anomalies = coincidencesIn(patternPolicy(window: 10, repeats: 3), $scans);

    expect($anomalies[0]->context['min_gap_seconds'])->toBe(1)
        ->and($anomalies[0]->context['last_gap_seconds'])->toBe(6)
        ->and($anomalies[0]->context['coincidence_days'])->toBe(3)
        ->and($anomalies[0]->context['first_coincidence_at'])->toBe('2026-03-01T05:00:00.000000Z')
        ->and($anomalies[0]->context['last_coincidence_at'])->toBe('2026-03-03T05:00:00.000000Z');
})->group('RF-PR-06');

it('lleva en el contexto el quiosco, los umbrales y los momentos, y ni un nombre de persona', function (): void {
    $anomalies = coincidencesIn(patternPolicy(window: 10, repeats: 3), coincidingDays(4, 4));

    expect($anomalies[0]->type)->toBe(AnomalyType::ANOMALOUS_PATTERN)
        ->and($anomalies[0]->shiftEntryUuid)->toBeNull()
        ->and($anomalies[0]->workDate->isoDate)->toBe('2026-03-04')
        ->and($anomalies[0]->context)->toBe([
            'pattern' => 'kiosk_coincidence',
            'device_id' => 7,
            'device_name' => 'Recepcion',
            'counterpart_employee_uuid' => BRUNO,
            'counterpart_count' => 1,
            'coincidence_days' => 4,
            'window_seconds' => 10,
            'min_repeats' => 3,
            'first_coincidence_at' => '2026-03-01T05:00:00.000000Z',
            'last_coincidence_at' => '2026-03-04T05:00:00.000000Z',
            'last_gap_seconds' => 4,
            'min_gap_seconds' => 4,
        ]);
})->group('RF-PR-06', 'RF-PD-15');

it('recorta el rotulo del quiosco a lo que admite el contrato', function (): void {
    // `devices.name` admite 120 caracteres y el esquema `IncidentContext` del
    // contrato acota las cadenas a 64. Sin el recorte, una incidencia con un
    // rotulo largo se rechazaria en la prueba de contrato y no antes.
    $largo = str_repeat('Recepcion del hall principal ', 6);

    $scans = [];

    for ($day = 1; $day <= 3; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        $scans[] = kioskScan(ANA, $date.' 06:00:00', deviceName: $largo);
        $scans[] = kioskScan(BRUNO, $date.' 06:00:04', deviceName: $largo);
    }

    $anomalies = coincidencesIn(patternPolicy(window: 10, repeats: 3), $scans);

    expect($anomalies[0]->context['device_name'])->toBe(mb_substr($largo, 0, 64))
        ->and(mb_strlen((string) $anomalies[0]->context['device_name']))->toBe(64);
})->group('RF-PR-06');

// -----------------------------------------------------------------------------
// La bandeja manda: lo abierto silencia y lo resuelto reinicia la cuenta.
// -----------------------------------------------------------------------------

it('no vuelve a emitir mientras el indicio siga abierto en la bandeja', function (): void {
    // DECISION 13c, hallazgo R-1 de seguridad. Un patron describe un HABITO que
    // sigue ocurriendo: sin esto, las mismas dos personas coincidiendo cada
    // manana abrian una incidencia `high` nueva cada madrugada y la bandeja que
    // el runbook manda vaciar crecia sola. El indicio ya esta sobre la mesa de
    // alguien; repetirlo no aporta un dato, aporta una fila.
    $abierta = new PatternReviewState(hasOpen: true);

    $anomalies = coincidencesIn(
        patternPolicy(window: 10, repeats: 3),
        coincidingDays(5, 4),
        [ANA => $abierta, BRUNO => $abierta],
    );

    expect($anomalies)->toBe([]);
})->group('RF-PR-06');

it('silencia solo a quien tiene el indicio abierto, no a su contraparte', function (): void {
    // La incidencia es de cada persona: que la de Ana este abierta no significa
    // que el responsable de Bruno ya lo sepa.
    $anomalies = coincidencesIn(
        patternPolicy(window: 10, repeats: 3),
        coincidingDays(5, 4),
        [ANA => new PatternReviewState(hasOpen: true)],
    );

    expect($anomalies)->toHaveCount(1)
        ->and($anomalies[0]->employeeUuid)->toBe(BRUNO);
})->group('RF-PR-06');

it('tras resolver, solo cuenta los dias posteriores al cierre', function (): void {
    // Quien descarto «llegan juntos en coche» no puede volver a ver la misma
    // incidencia a la noche siguiente. Con el cierre puesto el dia 3, solo
    // quedan dos dias nuevos y no hay hallazgo; hacen falta `minRepeats` dias
    // NUEVOS para reabrir, y entonces se vera con datos nuevos.
    $resuelta = new PatternReviewState(lastResolvedAt: Instants::utc('2026-03-03 12:00'));

    $anomalies = coincidencesIn(
        patternPolicy(window: 10, repeats: 3),
        coincidingDays(5, 4),
        [ANA => $resuelta, BRUNO => $resuelta],
    );

    expect($anomalies)->toBe([]);
})->group('RF-PR-06');

it('vuelve a emitir cuando el patron persiste los dias nuevos que exige el umbral', function (): void {
    // La otra mitad: descartar no apaga la regla para siempre. Con el cierre el
    // dia 2 quedan tres dias nuevos —3, 4 y 5— y el indicio vuelve, fechado en el
    // ultimo y contando solo lo que no se habia visto.
    $resuelta = new PatternReviewState(lastResolvedAt: Instants::utc('2026-03-02 12:00'));

    $anomalies = coincidencesIn(
        patternPolicy(window: 10, repeats: 3),
        coincidingDays(5, 4),
        [ANA => $resuelta, BRUNO => $resuelta],
    );

    expect($anomalies)->toHaveCount(2)
        ->and($anomalies[0]->context['coincidence_days'])->toBe(3)
        ->and($anomalies[0]->workDate->isoDate)->toBe('2026-03-05')
        ->and($anomalies[0]->context['first_coincidence_at'])->toBe('2026-03-03T05:00:00.000000Z');
})->group('RF-PR-06');

it('cuenta todo cuando la persona no tiene ningun indicio previo', function (): void {
    $sinTocar = PatternReviewState::untouched();

    expect($sinTocar->hasOpen)->toBeFalse()
        ->and($sinTocar->lastResolvedAt)->toBeNull()
        ->and($sinTocar->counts(Instants::utc('2020-01-01 00:00')))->toBeTrue();
})->group('RF-PR-06');

it('no cuenta una coincidencia del instante exacto del cierre', function (): void {
    // Estricto: lo que ocurrio en el instante del cierre ya estaba delante de
    // quien lo cerro.
    $estado = new PatternReviewState(lastResolvedAt: Instants::utc('2026-03-03 12:00'));

    expect($estado->counts(Instants::utc('2026-03-03 12:00')))->toBeFalse()
        ->and($estado->counts(Instants::utc('2026-03-03 12:00:01')))->toBeTrue();
})->group('RF-PR-06');

// -----------------------------------------------------------------------------
// `impossible_sequence`: RN-16.
// -----------------------------------------------------------------------------

it('afirma la secuencia imposible por debajo del transito minimo y no en el limite', function (string $second, bool $expected): void {
    // RN-16, y el mismo limite estricto: con 120 s, 119 s es imposible y 120 s es
    // justo lo que el centro considera creible. Las horas se escriben enteras y
    // no se calculan: la aritmetica es justo lo que la prueba viene a desconfiar.
    $scans = [
        kioskScan(ANA, '2026-03-01 06:00:00', deviceId: 7, deviceName: 'Recepcion'),
        kioskScan(ANA, '2026-03-01 '.$second, deviceId: 8, deviceName: 'Cocina'),
    ];

    $anomalies = patternPolicy(transit: 120)->inspect($scans, patternSiteId(), patternDetectedAt());

    expect(ofPattern($anomalies, CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE) !== [])->toBe($expected);
})->with([
    'ciento diecinueve segundos es imposible' => ['06:01:59', true],
    'ciento veinte no: el limite es creible' => ['06:02:00', false],
    'ciento veintiuno tampoco' => ['06:02:01', false],
])->group('RN-16', 'RF-PR-06');

it('no afirma nada cuando los dos escaneos son del mismo quiosco', function (): void {
    // RN-16 habla de DISPOSITIVOS DISTINTOS. Dos escaneos seguidos en la misma
    // tablet son el anti-rebote (RF-AT-06), no una imposibilidad fisica.
    $scans = [
        kioskScan(ANA, '2026-03-01 06:00:00', deviceId: 7),
        kioskScan(ANA, '2026-03-01 06:00:30', deviceId: 7),
    ];

    $anomalies = patternPolicy(transit: 120)->inspect($scans, patternSiteId(), patternDetectedAt());

    expect(ofPattern($anomalies, CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE))->toBe([]);
})->group('RN-16');

it('no evalua RN-16 sobre una hora en duda por el reloj', function (?int $first, ?int $second, bool $expected): void {
    // DECISION 16, bloqueante B4. RN-16 no se evalua sobre una hora en duda POR
    // EL RELOJ, y el criterio es el mismo que uso el quiosco (`ReviewPolicy`):
    // con 15 min de tolerancia, 900 s no la pone en duda y 901 s si. Antes se
    // excluia por `flagged_for_review`, que es verdadera para TODO fichaje por
    // PIN (RF-AT-11) y dejaba sin RN-16 justo al camino que mas facil es prestar.
    $scans = [
        kioskScan(ANA, '2026-03-01 06:00:00', deviceId: 7, clockSkewSeconds: $first),
        kioskScan(ANA, '2026-03-01 06:00:30', deviceId: 8, clockSkewSeconds: $second),
    ];

    $anomalies = patternPolicy(transit: 120, skewMinutes: 15)->inspect($scans, patternSiteId(), patternDetectedAt());

    expect(ofPattern($anomalies, CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE) !== [])->toBe($expected);
})->with([
    'sin desfase medido' => [null, null, true],
    'el fichaje por PIN con la hora en punto SI se evalua' => [0, 0, true],
    'en la tolerancia exacta se evalua' => [900, 0, true],
    'un segundo por encima pone la hora en duda' => [901, 0, false],
    'tambien si el desviado es el segundo' => [0, 901, false],
    'y con el reloj atrasado, que falsea igual' => [-901, 0, false],
])->group('RN-16', 'RN-15');

it('quita el escaneo en duda de la lista en vez de anular a sus vecinos limpios', function (): void {
    // HALLAZGO R4. Con el descarte por PAR, un escaneo dudoso en medio de dos
    // limpios anulaba a los dos vecinos, que es exactamente al reves de lo que
    // RN-16 quiere: la imposibilidad entre los dos limpios sigue siendo cierta.
    $scans = [
        kioskScan(ANA, '2026-03-01 06:00:00', deviceId: 7, deviceName: 'Recepcion'),
        kioskScan(ANA, '2026-03-01 06:00:10', deviceId: 8, deviceName: 'Almacen', clockSkewSeconds: 2400),
        kioskScan(ANA, '2026-03-01 06:00:20', deviceId: 9, deviceName: 'Cocina'),
    ];

    $anomalies = ofPattern(
        patternPolicy(transit: 120, skewMinutes: 15)->inspect($scans, patternSiteId(), patternDetectedAt()),
        CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE,
    );

    expect($anomalies)->toHaveCount(1)
        ->and($anomalies[0]->context['from_device_id'])->toBe(7)
        ->and($anomalies[0]->context['to_device_id'])->toBe(9)
        ->and($anomalies[0]->context['gap_seconds'])->toBe(20);
})->group('RN-16', 'RN-15');

it('emite un solo hallazgo por persona y jornada aunque el dia tenga varias imposibilidades', function (): void {
    // Como `out_of_order_scan` y por lo mismo: varias imposibilidades el mismo
    // dia dicen «el uso de esta credencial ese dia no cuadra», que es una sola
    // cosa que revisar. Se queda la mas antigua.
    $scans = [
        kioskScan(ANA, '2026-03-01 06:00:00', deviceId: 7),
        kioskScan(ANA, '2026-03-01 06:00:30', deviceId: 8),
        kioskScan(ANA, '2026-03-01 14:00:00', deviceId: 7),
        kioskScan(ANA, '2026-03-01 14:00:20', deviceId: 8),
    ];

    $anomalies = ofPattern(
        patternPolicy(transit: 120)->inspect($scans, patternSiteId(), patternDetectedAt()),
        CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE,
    );

    expect($anomalies)->toHaveCount(1)
        ->and($anomalies[0]->context['first_occurred_at'])->toBe('2026-03-01T05:00:00.000000Z');
})->group('RN-16');

it('senala el tramo del segundo escaneo y, si no lo tiene, el del primero', function (?string $first, ?string $second, ?string $expected): void {
    // DECISION 14. El segundo escaneo puede ser un `rejected_debounce` (decision
    // 15), que no produce tramo: quedarse en nulo dejaria la incidencia sin
    // ningun tramo que senalar Y compartiendo cuadrupla con una coincidencia del
    // mismo dia, que es la colision que el libro de incidencias tiene que contar.
    $scans = [
        kioskScan(ANA, '2026-03-01 06:00:00', deviceId: 7, shiftEntryUuid: $first),
        kioskScan(ANA, '2026-03-01 06:00:30', deviceId: 8, shiftEntryUuid: $second),
    ];

    $anomalies = ofPattern(
        patternPolicy(transit: 120)->inspect($scans, patternSiteId(), patternDetectedAt()),
        CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE,
    );

    expect($anomalies[0]->shiftEntryUuid)->toBe($expected);
})->with([
    'el del segundo manda' => ['tramo-1', 'tramo-2', 'tramo-2'],
    'sin tramo el segundo, el del primero' => ['tramo-1', null, 'tramo-1'],
    'nulo solo si ninguno tiene' => [null, null, null],
])->group('RN-16');

it('lleva en el contexto los dos quioscos, los dos momentos y los dos escaneos', function (): void {
    $scans = [
        kioskScan(ANA, '2026-03-01 06:00:00', deviceId: 7, deviceName: 'Recepcion'),
        kioskScan(ANA, '2026-03-01 06:00:30', deviceId: 8, deviceName: 'Cocina', shiftEntryUuid: 'tramo-1'),
    ];

    $anomalies = ofPattern(
        patternPolicy(transit: 120)->inspect($scans, patternSiteId(), patternDetectedAt()),
        CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE,
    );

    expect($anomalies[0]->type)->toBe(AnomalyType::ANOMALOUS_PATTERN)
        ->and($anomalies[0]->employeeUuid)->toBe(ANA)
        ->and($anomalies[0]->workDate->isoDate)->toBe('2026-03-01')
        ->and($anomalies[0]->shiftEntryUuid)->toBe('tramo-1')
        ->and($anomalies[0]->context)->toBe([
            'pattern' => 'impossible_sequence',
            'from_device_id' => 7,
            'from_device_name' => 'Recepcion',
            'to_device_id' => 8,
            'to_device_name' => 'Cocina',
            'first_occurred_at' => '2026-03-01T05:00:00.000000Z',
            'second_occurred_at' => '2026-03-01T05:00:30.000000Z',
            'gap_seconds' => 30,
            'transit_seconds' => 120,
            'first_scan_id' => 'scan-'.ANA.'-2026-03-01 06:00:00-7',
            'second_scan_id' => 'scan-'.ANA.'-2026-03-01 06:00:30-8',
        ]);
})->group('RN-16', 'RF-PR-06');

// -----------------------------------------------------------------------------
// Los umbrales son configuracion, y el cero apaga.
// -----------------------------------------------------------------------------

it('cambia el resultado al cambiar los umbrales, sin tocar una linea de codigo', function (): void {
    // RF-PD-01 y regla dura 14. Los MISMOS escaneos con dos configuraciones
    // distintas: cuatro dias con coincidencia son sistematicos con
    // `MIN_REPEATS = 3` y no lo son con `MIN_REPEATS = 5`.
    $scans = coincidingDays(4, 4);

    expect(coincidencesIn(patternPolicy(window: 10, repeats: 3), $scans))->toHaveCount(2)
        ->and(coincidencesIn(patternPolicy(window: 10, repeats: 5), $scans))->toBe([]);
})->group('RF-PD-01', 'RF-PR-06');

it('apaga la coincidencia con la ventana a cero y deja viva la secuencia imposible', function (): void {
    // Cero es legitimo y significa «esta comprobacion no aplica en mi centro».
    // Los dos hallazgos se apagan por separado: apagar uno no apaga el otro.
    $scans = [
        ...coincidingDays(5, 4),
        kioskScan(CARLA, '2026-03-01 06:00:00', deviceId: 7),
        kioskScan(CARLA, '2026-03-01 06:00:30', deviceId: 9),
    ];

    $anomalies = patternPolicy(window: 0, repeats: 3, transit: 120)
        ->inspect($scans, patternSiteId(), patternDetectedAt());

    expect(ofPattern($anomalies, CredentialPatternPolicy::KIOSK_COINCIDENCE))->toBe([])
        ->and(ofPattern($anomalies, CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE))->toHaveCount(1);
})->group('RF-PD-01', 'RF-PR-06');

it('apaga la secuencia imposible con el transito a cero y deja viva la coincidencia', function (): void {
    // El caso del centro con dos tablets contiguas en la misma puerta, ya
    // documentado asi en `configuracion.md` §2.1.
    $scans = [
        ...coincidingDays(5, 4),
        kioskScan(CARLA, '2026-03-01 06:00:00', deviceId: 7),
        kioskScan(CARLA, '2026-03-01 06:00:30', deviceId: 9),
    ];

    $anomalies = patternPolicy(window: 10, repeats: 3, transit: 0)
        ->inspect($scans, patternSiteId(), patternDetectedAt());

    expect(ofPattern($anomalies, CredentialPatternPolicy::IMPOSSIBLE_SEQUENCE))->toBe([])
        ->and(ofPattern($anomalies, CredentialPatternPolicy::KIOSK_COINCIDENCE))->toHaveCount(2);
})->group('RF-PD-01', 'RN-16');

it('no encuentra nada cuando no hay nada que encontrar', function (): void {
    expect(patternPolicy()->inspect([], patternSiteId(), patternDetectedAt()))->toBe([]);
})->group('RF-PR-06');

// -----------------------------------------------------------------------------
// Los umbrales, como objeto de valor.
// -----------------------------------------------------------------------------

it('rechaza unos umbrales que no significan nada', function (int $window, int $repeats, int $transit, int $skew): void {
    expect(fn (): CredentialPatternThresholds => new CredentialPatternThresholds($window, $repeats, $transit, $skew))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'ventana negativa' => [-1, 3, 120, 15],
    'transito negativo' => [10, 3, -1, 15],
    'cero dias con coincidencia' => [10, 0, 120, 15],
    'dias con coincidencia negativos' => [10, -1, 120, 15],
    'tolerancia de desfase negativa' => [10, 3, 120, -1],
])->group('RF-PD-01');

it('acepta los ceros que apagan y el minimo de un dia', function (): void {
    $thresholds = new CredentialPatternThresholds(0, 1, 0, 15);

    expect($thresholds->coincidenceIsDisabled())->toBeTrue()
        ->and($thresholds->impossibleSequenceIsDisabled())->toBeTrue()
        ->and($thresholds->minRepeats)->toBe(1);
})->group('RF-PD-01');

it('rechaza un escaneo sin empleado o con un instante que no esta en UTC', function (): void {
    // Regla dura 3: hacia el dominio solo entran instantes en UTC. Y un escaneo
    // sin persona no describe ningun uso de credencial.
    $utc = Instants::utc('2026-03-01 06:00:00');
    $workDate = WorkDate::fromInstant($utc, Instants::madrid());

    expect(fn (): CredentialScan => CredentialScan::of(' ', 7, 'Recepcion', $utc, $workDate, 'scan-1'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): CredentialScan => CredentialScan::of(
            ANA, 7, 'Recepcion', new DateTimeImmutable('2026-03-01 06:00:00', Instants::madrid()), $workDate, 'scan-1',
        ))->toThrow(InstantIsNotUtc::class);
})->group('RF-PR-06');
