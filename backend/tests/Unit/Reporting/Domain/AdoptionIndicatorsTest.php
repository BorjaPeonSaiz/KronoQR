<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Policy\AdoptionIndicators;
use App\Modules\Reporting\Domain\ValueObject\AdoptionFacts;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicator;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicatorKey;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicatorUnit;
use App\Modules\Reporting\Domain\ValueObject\AdoptionOriginShare;
use App\Modules\Reporting\Domain\ValueObject\AdoptionPeriodFacts;
use App\Modules\Reporting\Domain\ValueObject\AdoptionTargetComparison;

/*
 * **Toda la aritmetica del cuadro de impacto, verificada a mano** (RF-IN-08,
 * RNF-D-01, tarea 3.13).
 *
 * POR QUE UNITARIA Y NO DE INTEGRACION. Porque ningun porcentaje se calcula en
 * SQL, y esa decision existe precisamente para que estas pruebas puedan ser asi:
 * un conjunto conocido escrito en el propio `it`, un resultado calculado a mano en
 * el comentario y una comparacion. Con las divisiones dentro de un `SELECT`,
 * comprobar que «doce de trece jornadas son 92,31 %» exigiria levantar PostgreSQL,
 * sembrar trece jornadas con sus tramos y, cuando el numero saliera mal, no se
 * sabria si la culpa es de la division o del `WHERE`.
 *
 * LO QUE ESTAS PRUEBAS PROTEGEN. Un error aqui no rompe nada visible: publica un
 * numero creible y equivocado en el cuadro con el que se discute si el sistema se
 * renueva. Por eso la mutacion se acota a `AdoptionIndicators` y a sus objetos de
 * valor con MSI >= 80 %.
 */

/**
 * Hechos de un periodo con lo justo para la prueba que los usa.
 *
 * Existe para que cada `it` escriba **solo los numeros que verifica** y el lector
 * no tenga que separar los datos del ruido: un constructor de doce argumentos
 * repetido en diez pruebas esconde justamente la cifra que cada una comprueba.
 *
 * @param  array<string, int>  $byOrigin
 */
function periodoDeAdopcion(
    int $jornadas = 0,
    int $completas = 0,
    array $byOrigin = [],
    int $atendidos = 0,
    int $offline = 0,
    int $fallidos = 0,
    int $correcciones = 0,
    int $resueltas = 0,
    ?int $media = null,
    ?int $mediana = null,
    int $trabajados = 0,
    int $contratados = 0,
): AdoptionPeriodFacts {
    return new AdoptionPeriodFacts(
        workDaysWithActivity: $jornadas,
        workDaysComplete: $completas,
        acceptedScansByOrigin: $byOrigin,
        attendedScans: $atendidos,
        offlineResolvedScans: $offline,
        failedAttempts: $fallidos,
        corrections: $correcciones,
        resolvedOpenShiftIncidents: $resueltas,
        resolutionMeanMinutes: $media,
        resolutionMedianMinutes: $mediana,
        workedMinutes: $trabajados,
        contractedMinutes: $contratados,
    );
}

function hechosDeAdopcion(
    AdoptionPeriodFacts $actual,
    ?AdoptionPeriodFacts $anterior = null,
    int $incidenciasAbiertas = 0,
    int $sinCredencial = 0,
    ?int $lineaBaseMinutos = null,
): AdoptionFacts {
    return new AdoptionFacts(
        current: $actual,
        previous: $anterior ?? AdoptionPeriodFacts::empty(),
        openIncidents: $incidenciasAbiertas,
        employeesWithoutDeliveredCredential: $sinCredencial,
        baselineManualMinutesPerMonth: $lineaBaseMinutos,
    );
}

function indicadorDeAdopcion(AdoptionFacts $facts, AdoptionIndicatorKey $key): AdoptionIndicator
{
    $found = array_values(array_filter(
        (new AdoptionIndicators)->evaluate($facts),
        static fn (AdoptionIndicator $indicator): bool => $indicator->key === $key,
    ));

    expect($found)->toHaveCount(1, 'El cuadro tiene que traer el indicador «'.$key->value.'» exactamente una vez.');

    return $found[0];
}

// --- El conjunto completo ----------------------------------------------------

it('entrega los doce indicadores en el orden de lectura del cuadro', function (): void {
    /*
     * ESTA ES LA PRUEBA QUE SUSTITUYE AL `match` EXHAUSTIVO.
     *
     * La politica compone la lista en cuatro grupos —las cuatro preguntas que el
     * cuadro contesta— porque un `match` de doce ramas se sale del techo de
     * complejidad de doc 02 §3.5. Lo que aquel daba gratis —que un indicador nuevo
     * no pueda quedarse sin calcular— lo garantiza esto, y ademas comprueba **el
     * orden**, que el `match` no comprobaba: el CSV y el PDF recorren la lista tal
     * cual, asi que reordenarla cambiaria el papel que un hotel ya tenga archivado.
     */
    $indicators = (new AdoptionIndicators)->evaluate(hechosDeAdopcion(periodoDeAdopcion()));

    $keys = array_map(static fn (AdoptionIndicator $indicator): string => $indicator->key->value, $indicators);
    $expected = array_map(
        static fn (AdoptionIndicatorKey $key): string => $key->value,
        AdoptionIndicatorKey::inReadingOrder(),
    );

    expect($keys)->toBe($expected)
        ->and($indicators)->toHaveCount(12);
})->group('RF-IN-08');

it('declara unidad para los doce indicadores', function (AdoptionIndicatorKey $key): void {
    // La unidad sale de una tabla y no de un `match`, asi que un indicador nuevo sin
    // fila no revienta al compilar: revienta aqui, que es donde tiene que hacerlo.
    expect($key->unit())->toBeInstanceOf(AdoptionIndicatorUnit::class);
})->with(array_map(
    static fn (AdoptionIndicatorKey $key): array => [$key],
    AdoptionIndicatorKey::inReadingOrder(),
))->group('RF-IN-08');

// --- (a) Jornadas con registro completo --------------------------------------

it('calcula el porcentaje de jornadas con registro completo sobre un conjunto conocido', function (): void {
    /*
     * Doce de trece jornadas completas: 12 / 13 = 0,923076… -> 92,31 %.
     *
     * El denominador son las jornadas CON ALGUN TRAMO. Quien no ficho no ha
     * registrado bien ni mal, y meterlo abajo haria que un dia de cierre del hotel
     * bajara el indicador — que es como un cuadro de adopcion empieza a medir el
     * calendario en lugar del sistema.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(jornadas: 13, completas: 12));

    $indicator = indicadorDeAdopcion($facts, AdoptionIndicatorKey::WorkDaysCompleteRatio);

    expect($indicator->current)->toBe(92.31)
        ->and($indicator->unit)->toBe(AdoptionIndicatorUnit::Percent)
        ->and($indicator->target?->comparison)->toBe(AdoptionTargetComparison::AtLeast)
        ->and($indicator->target?->value)->toBe(99.0)
        // 92,31 esta por debajo del objetivo del §1.3, y el cuadro lo dice.
        ->and($indicator->meetsTarget())->toBeFalse();
})->group('RF-IN-08');

it('no inventa un cero cuando el periodo no tuvo ninguna jornada', function (): void {
    // Un hotel cerrado en febrero no tuvo «0 % de jornadas completas»: no tuvo
    // jornadas. Es la invariante de toda la politica y el error que este cuadro
    // existe para no cometer.
    $facts = hechosDeAdopcion(periodoDeAdopcion(jornadas: 0, completas: 0));

    $indicator = indicadorDeAdopcion($facts, AdoptionIndicatorKey::WorkDaysCompleteRatio);

    expect($indicator->current)->toBeNull()
        ->and($indicator->meetsTarget())->toBeNull();
})->group('RF-IN-08');

it('da por cumplido el objetivo exactamente en el umbral', function (): void {
    // 99 de 100 son el 99 % clavado, y el §1.3 dice «>= 99 %»: cumple. El borde se
    // prueba porque es donde un `>` en lugar de un `>=` pasa desapercibido para
    // siempre.
    $facts = hechosDeAdopcion(periodoDeAdopcion(jornadas: 100, completas: 99));

    expect(indicadorDeAdopcion($facts, AdoptionIndicatorKey::WorkDaysCompleteRatio)->meetsTarget())->toBeTrue();
})->group('RF-IN-08');

// --- (b) Reparto por origen --------------------------------------------------

it('reparte los fichajes aceptados por origen y suma exactamente 100', function (): void {
    /*
     * 13.847 + 155 + 42 + 0 = 14.044 aceptados.
     *
     *   qr_kiosk     13847 / 14044 = 98,5973… -> 98,60
     *   pin_kiosk      155 / 14044 =  1,1036… ->  1,10
     *   manual_admin    42 / 14044 =  0,2990… ->  0,30
     *   import           0 / 14044 =  0        ->  0,00
     *
     * Suman 100,00 sin necesitar ajuste. El reparto siempre trae los CUATRO
     * origenes, tambien `import` a cero: un rosco con tres porciones un mes y
     * cuatro el siguiente cambia de leyenda sin que haya pasado nada.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(byOrigin: [
        'qr_kiosk' => 13847,
        'pin_kiosk' => 155,
        'manual_admin' => 42,
    ]));

    $breakdown = (new AdoptionIndicators)->originBreakdown($facts);
    $shares = array_map(static fn (AdoptionOriginShare $share): ?float => $share->share, $breakdown);

    expect($breakdown)->toHaveCount(4)
        ->and(array_map(static fn (AdoptionOriginShare $share): string => $share->origin, $breakdown))
        ->toBe(['qr_kiosk', 'pin_kiosk', 'manual_admin', 'import'])
        ->and($shares)->toBe([98.6, 1.1, 0.3, 0.0])
        // La suma se compara REDONDEADA a dos decimales, que es como se lee: en
        // binario, 98,6 + 1,1 + 0,3 da 99,99999999999999, y esa ultima cifra no
        // existe en ningun sitio donde alguien la vea.
        ->and(round(array_sum($shares), 2))->toBe(100.0);
})->group('RF-IN-08');

it('absorbe el residuo del redondeo en el origen mayor para que el reparto siga sumando 100', function (): void {
    /*
     * Un tercio cada uno en tres origenes: 33,333… -> 33,33, y tres veces 33,33 son
     * 99,99. Un rosco cuyas porciones suman 99,99 se lee como un dato perdido.
     *
     * El centesimo que falta va al origen MAYOR —aqui el primero, porque empatan y
     * el desempate es determinista—, que es donde menos pesa en terminos relativos.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(byOrigin: [
        'qr_kiosk' => 1,
        'pin_kiosk' => 1,
        'manual_admin' => 1,
    ]));

    $shares = array_map(
        static fn (AdoptionOriginShare $share): ?float => $share->share,
        (new AdoptionIndicators)->originBreakdown($facts),
    );

    expect(round(array_sum($shares), 2))->toBe(100.0)
        ->and($shares[0])->toBe(33.34)
        ->and($shares[1])->toBe(33.33)
        ->and($shares[2])->toBe(33.33);
})->group('RF-IN-08');

it('deja el reparto vacio, no a cero, cuando no hubo ningun fichaje aceptado', function (): void {
    // «Nadie ficho» y «nadie ficho por tarjeta» son afirmaciones distintas, y la
    // segunda sobre un periodo sin actividad seria falsa: el «0 % por tarjeta» de un
    // hotel cerrado parece un quiosco averiado.
    $breakdown = (new AdoptionIndicators)->originBreakdown(hechosDeAdopcion(periodoDeAdopcion()));

    foreach ($breakdown as $share) {
        expect($share->scans)->toBe(0)
            ->and($share->share)->toBeNull();
    }

    expect($breakdown)->toHaveCount(4);
})->group('RF-IN-08');

it('mide el porcentaje de fichajes por QR sobre los aceptados y no sobre los intentos', function (): void {
    /*
     * 980 por tarjeta de 1.000 aceptados -> 98,00 %, que es el objetivo clavado.
     *
     * Los 300 escaneos rechazados del periodo NO entran en el denominador: un
     * escaneo rechazado no es un fichaje, y contarlo abajo castigaria al indicador
     * de adopcion por las tarjetas revocadas que el sistema rechaza bien.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(
        byOrigin: ['qr_kiosk' => 980, 'pin_kiosk' => 20],
        atendidos: 1300,
    ));

    $indicator = indicadorDeAdopcion($facts, AdoptionIndicatorKey::QrScansRatio);

    expect($indicator->current)->toBe(98.0)
        ->and($indicator->meetsTarget())->toBeTrue();
})->group('RF-IN-08');

// --- (c) Ratio de correcciones ----------------------------------------------

it('calcula el ratio de correcciones sobre los fichajes aceptados del periodo', function (): void {
    /*
     * 17 correcciones sobre 1.415 fichajes aceptados: 17 / 1415 = 0,012014… ->
     * 1,20 %, por debajo del «< 2 %» del §1.3.
     *
     * Es la misma fraccion que pinta el cuadro de mando con
     * `manual_corrections_total / scans_total`, para que el panel y Grafana no
     * puedan decir cosas distintas sobre la misma semana.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(
        byOrigin: ['qr_kiosk' => 1400, 'manual_admin' => 15],
        correcciones: 17,
    ));

    $indicator = indicadorDeAdopcion($facts, AdoptionIndicatorKey::ManualCorrectionsRatio);

    expect($indicator->current)->toBe(1.2)
        ->and($indicator->target?->comparison)->toBe(AdoptionTargetComparison::AtMost)
        ->and($indicator->meetsTarget())->toBeTrue();
})->group('RF-IN-08');

// --- (d) Tiempo hasta resolver un turno sin cerrar ---------------------------

it('entrega el tiempo medio y el mediano hasta resolver un turno sin cerrar', function (): void {
    /*
     * La media y la mediana llegan ya agregadas —son minutos, no porcentajes— y lo
     * que la politica decide es si SIGNIFICAN algo: aqui hay siete incidencias
     * resueltas, asi que si.
     *
     * 512 minutos de media son 8 h 32 min, por debajo de las 24 h del §1.3; la
     * mediana en 305 delata que la media la estira alguna incidencia olvidada, que
     * es exactamente para lo que esta al lado.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(resueltas: 7, media: 512, mediana: 305));

    $mean = indicadorDeAdopcion($facts, AdoptionIndicatorKey::IncidentResolutionMeanMinutes);
    $median = indicadorDeAdopcion($facts, AdoptionIndicatorKey::IncidentResolutionMedianMinutes);

    expect($mean->current)->toBe(512.0)
        ->and($mean->unit)->toBe(AdoptionIndicatorUnit::Minutes)
        ->and($mean->target?->value)->toBe(1440.0)
        ->and($mean->meetsTarget())->toBeTrue()
        ->and($median->current)->toBe(305.0)
        ->and($median->target)->toBeNull();
})->group('RF-IN-08');

it('deja fuera del objetivo las veinticuatro horas exactas de resolucion', function (): void {
    /*
     * EL CASO CLAVADO DE UN OBJETIVO ESTRICTO (decision 18 de la ficha 3.13).
     *
     * El §1.3 escribe «< 24 h», no «<= 24 h», y el requisito manda sobre la
     * implementacion: **1.440 minutos exactos NO cumplen**. La diferencia solo
     * aparece aqui, y aqui es donde importa —un dia entero desde que se detecta un
     * turno sin cerrar hasta que alguien lo cierra no es «deteccion temprana»—.
     *
     * Y va en el lado contrario que su hermana de `at_least`: alli 99 de 100 son el
     * 99 % clavado y SI cumplen, porque el §1.3 dice «>= 99 %». Los dos bordes se
     * prueban porque son los dos sitios donde un `<` de mas o de menos pasa
     * desapercibido para siempre.
     */
    $clavado = hechosDeAdopcion(periodoDeAdopcion(resueltas: 3, media: 1440, mediana: 1440));
    $unMinutoMenos = hechosDeAdopcion(periodoDeAdopcion(resueltas: 3, media: 1439, mediana: 1439));

    expect(indicadorDeAdopcion($clavado, AdoptionIndicatorKey::IncidentResolutionMeanMinutes)->meetsTarget())
        ->toBeFalse()
        ->and(indicadorDeAdopcion($unMinutoMenos, AdoptionIndicatorKey::IncidentResolutionMeanMinutes)->meetsTarget())
        ->toBeTrue();
})->group('RF-IN-08');

it('deja fuera del objetivo un ratio de correcciones del dos por ciento justo', function (): void {
    // El otro `at_most` del §1.3: «< 2 %». Dos correcciones sobre cien fichajes son
    // el 2,00 % clavado y NO cumplen; una sobre cien si.
    $clavado = hechosDeAdopcion(periodoDeAdopcion(byOrigin: ['qr_kiosk' => 100], correcciones: 2));
    $unaMenos = hechosDeAdopcion(periodoDeAdopcion(byOrigin: ['qr_kiosk' => 100], correcciones: 1));

    expect(indicadorDeAdopcion($clavado, AdoptionIndicatorKey::ManualCorrectionsRatio)->current)->toBe(2.0)
        ->and(indicadorDeAdopcion($clavado, AdoptionIndicatorKey::ManualCorrectionsRatio)->meetsTarget())
        ->toBeFalse()
        ->and(indicadorDeAdopcion($unaMenos, AdoptionIndicatorKey::ManualCorrectionsRatio)->meetsTarget())
        ->toBeTrue();
})->group('RF-IN-08');

it('redondea la variacion a dos decimales, sin ruido de coma flotante', function (): void {
    /*
     * 99,94 − 99,81 = **0,13**, y no `0.12999999999999545`.
     *
     * No es cosmetica: con `serialize_precision = -1` esa resta sale por el JSON con
     * quince decimales, y el contrato ejemplifica `0.13`. El par elegido es el del
     * borde de RNF-D-01 a proposito —es donde la disponibilidad se mira con lupa—.
     *
     * Los dos extremos vienen ya redondeados a dos decimales, asi que la unica cifra
     * significativa de su resta cabe en dos: redondear no pierde nada.
     */
    $facts = hechosDeAdopcion(
        // 15900 / 15910 -> 99,94 %.
        actual: periodoDeAdopcion(atendidos: 15900, fallidos: 10),
        // 15800 / 15830 -> 99,81 %.
        anterior: periodoDeAdopcion(atendidos: 15800, fallidos: 30),
    );

    $indicator = indicadorDeAdopcion($facts, AdoptionIndicatorKey::ClockingAvailabilityRatio);

    expect($indicator->current)->toBe(99.94)
        ->and($indicator->previous)->toBe(99.81)
        ->and($indicator->delta)->toBe(0.13);
})->group('RF-IN-08', 'RNF-D-01');

it('deja vacio el tiempo de resolucion cuando no se resolvio ninguna incidencia', function (): void {
    // «Cero minutos de media» seria el mejor resultado posible y significa lo
    // contrario: que nadie resolvio nada. La ausencia la decide el RECUENTO de
    // resueltas y no que los minutos vengan nulos, para que una columna vacia de la
    // consulta no pueda disfrazarse de dato.
    $facts = hechosDeAdopcion(periodoDeAdopcion(resueltas: 0, media: null, mediana: null));

    expect(indicadorDeAdopcion($facts, AdoptionIndicatorKey::IncidentResolutionMeanMinutes)->current)->toBeNull()
        ->and(indicadorDeAdopcion($facts, AdoptionIndicatorKey::IncidentResolutionMeanMinutes)->meetsTarget())
        ->toBeNull();
})->group('RF-IN-08');

// --- (e) y las fotos de hoy --------------------------------------------------

it('entrega las dos fotos de hoy sin comparacion contra el periodo anterior', function (): void {
    /*
     * Incidencias abiertas y personas sin tarjeta entregada son COLA PENDIENTE, no
     * flujo del periodo. Comparar «tres abiertas hoy» con «tres abiertas hoy el mes
     * pasado» exigiria saber cuantas habia abiertas el 28 de febrero a las 23:59,
     * que es un dato que este producto no guarda — y darlo por bueno con la cifra de
     * hoy seria enseñar una variacion inventada.
     *
     * El periodo anterior de esta prueba tiene datos a proposito: asi se ve que el
     * `null` es por definicion y no por falta de informacion.
     */
    $facts = hechosDeAdopcion(
        actual: periodoDeAdopcion(jornadas: 10, completas: 10),
        anterior: periodoDeAdopcion(jornadas: 10, completas: 8),
        incidenciasAbiertas: 3,
        sinCredencial: 2,
    );

    $incidents = indicadorDeAdopcion($facts, AdoptionIndicatorKey::OpenIncidents);
    $credentials = indicadorDeAdopcion($facts, AdoptionIndicatorKey::EmployeesWithoutCredential);

    expect($incidents->current)->toBe(3.0)
        ->and($incidents->unit)->toBe(AdoptionIndicatorUnit::Count)
        ->and($incidents->previous)->toBeNull()
        ->and($incidents->delta)->toBeNull()
        ->and($credentials->current)->toBe(2.0)
        ->and($credentials->previous)->toBeNull()
        ->and($credentials->delta)->toBeNull();
})->group('RF-IN-08');

// --- (g) Disponibilidad del acto de fichar (RNF-D-01) ------------------------

it('mide la disponibilidad como atendidos sobre atendidos mas intentos fallidos', function (): void {
    /*
     * **LA UNICA PRUEBA DEL PROYECTO QUE ETIQUETA RNF-D-01.** Sin ella el requisito
     * esta cubierto en sustancia pero no sale en la matriz de trazabilidad, que es
     * la evidencia ante una auditoria.
     *
     * 15.900 fichajes atendidos y 10 intentos que el quiosco no pudo cursar:
     *
     *   15900 / (15900 + 10) = 15900 / 15910 = 0,999371… -> 99,94 %
     *
     * Por encima del 99,9 % del §1.3. Y aqui esta lo que el requisito dice de
     * verdad, entre parentesis: «incluye modo offline».
     *
     *   - ARRIBA van TODOS los `scan_events`, tambien los 400 que el sistema
     *     rechazo por una regla de negocio —tarjeta revocada, rebote— porque el
     *     sistema estaba ahi y contesto, y tambien los 430 que llegaron por la cola
     *     con el servidor caido, porque **la persona pudo fichar** (ADR-008).
     *   - ABAJO, ademas, solo los intentos que no llegaron a producir ningun
     *     escaneo: camara caida, escaner que no arranca, envio que no sale.
     *
     * NO ES EL TIEMPO DE SERVICIO DE LA API. Medido como *uptime* de `/health`,
     * esos 430 fichajes de la cola contarian como caida — y son precisamente el
     * escenario que el diseño resuelve.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(
        byOrigin: ['qr_kiosk' => 15500],
        atendidos: 15900,
        offline: 430,
        fallidos: 10,
    ));

    $availability = indicadorDeAdopcion($facts, AdoptionIndicatorKey::ClockingAvailabilityRatio);
    $offline = indicadorDeAdopcion($facts, AdoptionIndicatorKey::OfflineResolvedRatio);

    expect($availability->current)->toBe(99.94)
        ->and($availability->target?->value)->toBe(99.9)
        ->and($availability->meetsTarget())->toBeTrue()
        // 430 / 15900 = 2,7044… -> 2,70 %. De los fichajes atendidos, los que se
        // resolvieron sin servidor: es lo que hace creible al de arriba.
        ->and($offline->current)->toBe(2.7)
        ->and($offline->target)->toBeNull()
        ->and($offline->previous)->toBeNull();
})->group('RF-IN-08', 'RNF-D-01');

it('cuenta como disponible el fichaje que solo pudo resolver la cola offline', function (): void {
    /*
     * El caso extremo del parrafo anterior, aislado: un dia entero con el servidor
     * caido en el que las cien personas del turno ficharon en la tablet y sus cien
     * fichajes llegaron despues por la cola. Cero errores de cliente, porque el
     * quiosco hizo su trabajo.
     *
     * La disponibilidad es **100 %**, y tiene que serlo: nadie dejo de fichar. Un
     * indicador que midiera el servidor diria 0 % justo el dia que el diseño
     * funciono como estaba previsto (ADR-008, regla dura 19).
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(
        byOrigin: ['qr_kiosk' => 100],
        atendidos: 100,
        offline: 100,
        fallidos: 0,
    ));

    expect(indicadorDeAdopcion($facts, AdoptionIndicatorKey::ClockingAvailabilityRatio)->current)->toBe(100.0)
        ->and(indicadorDeAdopcion($facts, AdoptionIndicatorKey::OfflineResolvedRatio)->current)->toBe(100.0);
})->group('RF-IN-08', 'RNF-D-01');

it('deja vacia la disponibilidad cuando no hubo ni un intento de fichar', function (): void {
    // Ni atendidos ni fallidos: el hotel estaba cerrado. Un 0 % de disponibilidad
    // ahi seria una alarma sobre un dia en el que no paso nada, y un 100 % seria
    // presumir de un servicio que nadie pidio.
    $facts = hechosDeAdopcion(periodoDeAdopcion(atendidos: 0, fallidos: 0));

    expect(indicadorDeAdopcion($facts, AdoptionIndicatorKey::ClockingAvailabilityRatio)->current)->toBeNull()
        ->and(indicadorDeAdopcion($facts, AdoptionIndicatorKey::OfflineResolvedRatio)->current)->toBeNull();
})->group('RF-IN-08', 'RNF-D-01');

// --- La comparacion contra el periodo anterior -------------------------------

it('compara contra el periodo anterior en la unidad del indicador y con signo', function (): void {
    /*
     * Actual: 99 de 100 -> 99,00 %. Anterior: 97 de 100 -> 97,00 %.
     * Variacion: +2,00 PUNTOS PORCENTUALES, no «+2,06 %».
     *
     * El delta es una resta y no una variacion relativa a proposito: «ha subido dos
     * puntos» se comprueba sumando, y «ha mejorado un 2,06 %» sobre un porcentaje es
     * la forma habitual de que dos personas entiendan dos cosas distintas.
     */
    $facts = hechosDeAdopcion(
        actual: periodoDeAdopcion(jornadas: 100, completas: 99),
        anterior: periodoDeAdopcion(jornadas: 100, completas: 97),
    );

    $indicator = indicadorDeAdopcion($facts, AdoptionIndicatorKey::WorkDaysCompleteRatio);

    expect($indicator->current)->toBe(99.0)
        ->and($indicator->previous)->toBe(97.0)
        ->and($indicator->delta)->toBe(2.0);
})->group('RF-IN-08');

it('deja la comparacion vacia, y no a cero, cuando el periodo anterior no tiene denominador', function (): void {
    /*
     * El caso que mas duele y el que la ficha señala por escrito: una instalacion
     * puesta en marcha a mitad de febrero no tuvo «0 % de jornadas completas» en
     * enero. Con un cero ahi, el cuadro de marzo enseñaria una mejora espectacular
     * inventada —o un desplome, segun el indicador— en la pantalla con la que se
     * decide si el sistema se renueva.
     */
    $facts = hechosDeAdopcion(
        actual: periodoDeAdopcion(jornadas: 100, completas: 99, byOrigin: ['qr_kiosk' => 500], atendidos: 500),
        anterior: periodoDeAdopcion(),
    );

    foreach ([
        AdoptionIndicatorKey::WorkDaysCompleteRatio,
        AdoptionIndicatorKey::QrScansRatio,
        AdoptionIndicatorKey::ClockingAvailabilityRatio,
    ] as $key) {
        $indicator = indicadorDeAdopcion($facts, $key);

        expect($indicator->current)->not->toBeNull()
            ->and($indicator->previous)->toBeNull()
            ->and($indicator->delta)->toBeNull();
    }
})->group('RF-IN-08');

it('compara las horas trabajadas y contratadas en minutos y admite el cero como dato', function (): void {
    /*
     * 4.860.000 minutos frente a 4.392.000 del periodo anterior: +468.000, que son
     * 7.800 horas mas de trabajo en el hotel.
     *
     * Aqui el CERO SI ES UN DATO y no un «no se sabe», al contrario que en los
     * porcentajes: un periodo sin horas trabajadas son cero horas trabajadas, no una
     * incognita. La diferencia esta en que estos dos no son una fraccion y no
     * necesitan denominador.
     */
    $facts = hechosDeAdopcion(
        actual: periodoDeAdopcion(trabajados: 4_860_000, contratados: 4_914_000),
        anterior: periodoDeAdopcion(trabajados: 4_392_000, contratados: 4_438_800),
    );

    $worked = indicadorDeAdopcion($facts, AdoptionIndicatorKey::WorkedMinutes);
    $contracted = indicadorDeAdopcion($facts, AdoptionIndicatorKey::ContractedMinutes);

    expect($worked->current)->toBe(4_860_000.0)
        ->and($worked->delta)->toBe(468_000.0)
        ->and($worked->unit)->toBe(AdoptionIndicatorUnit::Minutes)
        ->and($contracted->current)->toBe(4_914_000.0)
        ->and($contracted->delta)->toBe(475_200.0);
})->group('RF-IN-08');

// --- (h) La linea base declarada ---------------------------------------------

it('presenta la linea base declarada con su objetivo de reduccion y sin juzgarla', function (): void {
    /*
     * 40 h/mes declaradas son 2.400 minutos, con el objetivo «−80 %» del §1.3 al
     * lado. Y `meetsTarget()` devuelve **null**, que no es «no cumple»: el producto
     * NO PUEDE medir si se ha conseguido, porque las hojas de horas ya no se
     * consolidan a mano — que es justamente el punto. Lo presenta como referencia.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(), lineaBaseMinutos: 2400);

    $indicator = indicadorDeAdopcion($facts, AdoptionIndicatorKey::BaselineManualMinutesPerMonth);

    expect($indicator->current)->toBe(2400.0)
        ->and($indicator->target?->comparison)->toBe(AdoptionTargetComparison::Reduction)
        ->and($indicator->target?->value)->toBe(80.0)
        ->and($indicator->meetsTarget())->toBeNull();
})->group('RF-IN-08');

it('deja vacia la linea base cuando el cliente no la ha declarado', function (): void {
    // Sin declarar, el indicador sale vacio y el cuadro no inventa una mejora: el
    // sistema no puede observar el trabajo anterior a su instalacion, y es honesto
    // decirlo en lugar de rellenar el hueco.
    $facts = hechosDeAdopcion(periodoDeAdopcion(), lineaBaseMinutos: null);

    expect(indicadorDeAdopcion($facts, AdoptionIndicatorKey::BaselineManualMinutesPerMonth)->current)->toBeNull();
})->group('RF-IN-08');

it('traduce a vacio el cero de la linea base, que significa «no declarado»', function (): void {
    // El catalogo de `BASELINE_MANUAL_HOURS_PER_MONTH` usa el cero para decir «no
    // contestado». Si llegara al indicador se pintaria «cero horas al mes
    // consolidando hojas», que es una afirmacion espectacular y falsa. La traduccion
    // ocurre en un solo sitio, y es este.
    $facts = hechosDeAdopcion(periodoDeAdopcion())->withDeclaredBaselineHours(0);

    expect($facts->baselineManualMinutesPerMonth)->toBeNull()
        ->and(indicadorDeAdopcion($facts, AdoptionIndicatorKey::BaselineManualMinutesPerMonth)->current)
        ->toBeNull();
})->group('RF-IN-08');

it('convierte a minutos las horas declaradas', function (): void {
    // 40 h/mes -> 2.400 minutos. La unidad del cuadro es el minuto por lo mismo que
    // en todo el producto: los minutos suman y las horas decimales no.
    $facts = hechosDeAdopcion(periodoDeAdopcion())->withDeclaredBaselineHours(40);

    expect($facts->baselineManualMinutesPerMonth)->toBe(2400);
})->group('RF-IN-08');

// --- Los criterios -----------------------------------------------------------

it('acompaña el cuadro con una linea de criterio por cada cosa que hay que explicar', function (): void {
    // Los criterios viajan como CLAVES porque el dominio no tiene idioma, y las
    // traduce el borde. Son parte del cuadro y no de un manual: un porcentaje sin su
    // definicion es un numero que cada persona interpreta a su manera, y este cuadro
    // se enseña en reuniones donde se decide si el sistema se renueva.
    /*
     * LA LISTA SE COMPARA ENTERA Y EN ORDEN, no «que contenga algunas».
     *
     * Porque lo que se protege es que **no falte ninguna**: una linea de criterio
     * que desaparezca deja un porcentaje en la pantalla sin la frase que lo
     * explica, y eso no rompe nada visible — se descubre en la reunion en la que
     * alguien pregunta que cuenta como correccion. El orden entra tambien porque se
     * leen como un texto seguido, de lo general a lo particular.
     */
    expect((new AdoptionIndicators)->criteria())->toBe([
        'adoption.criteria.workdays_complete',
        'adoption.criteria.origin',
        'adoption.criteria.corrections',
        'adoption.criteria.availability',
        'adoption.criteria.offline',
        'adoption.criteria.incidents',
        'adoption.criteria.credentials',
        'adoption.criteria.hours',
        'adoption.criteria.baseline',
        'adoption.criteria.previous_period',
        'adoption.criteria.timezone',
        'adoption.criteria.aggregate',
        'adoption.criteria.dashboard',
    ]);
})->group('RF-IN-08');

it('decide la ausencia del tiempo de resolucion por el recuento y no por los minutos', function (): void {
    /*
     * El caso que distingue las dos formas de escribir lo mismo: **cero incidencias
     * resueltas con una media dentro**.
     *
     * No es un caso imaginario: es lo que devuelve un `LEFT JOIN` cuyo agregado
     * arrastra el valor de otra fila, o una consulta a la que se le cambia el
     * `GROUP BY`. Si la ausencia se decidiera mirando si los minutos son nulos, el
     * cuadro publicaria «7 h de media para resolver» sobre un periodo en el que no
     * se resolvio nada — un numero perfectamente creible y completamente falso.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(resueltas: 0, media: 420, mediana: 420));

    expect(indicadorDeAdopcion($facts, AdoptionIndicatorKey::IncidentResolutionMeanMinutes)->current)->toBeNull()
        ->and(indicadorDeAdopcion($facts, AdoptionIndicatorKey::IncidentResolutionMedianMinutes)->current)->toBeNull();
})->group('RF-IN-08');

it('entrega el tiempo de resolucion con una sola incidencia resuelta', function (): void {
    // UNA muestra es una muestra. El borde se prueba porque «hace falta mas de una»
    // es una forma facil de escribir el umbral, y dejaria el indicador vacio en el
    // mes en el que se resolvio exactamente una — que es justo el mes en el que
    // alguien quiere ver que ya se resuelven.
    $facts = hechosDeAdopcion(periodoDeAdopcion(resueltas: 1, media: 300, mediana: 300));

    expect(indicadorDeAdopcion($facts, AdoptionIndicatorKey::IncidentResolutionMeanMinutes)->current)->toBe(300.0);
})->group('RF-IN-08');

it('lleva el residuo del redondeo al origen mayor aunque no sea el de la tarjeta', function (): void {
    /*
     * Un hotel que ficha casi todo por PIN —una instalacion recien puesta en marcha
     * sin tarjetas entregadas, que es exactamente el caso que el cuadro tiene que
     * saber enseñar—:
     *
     *   pin_kiosk    2 / 3 = 66,666… -> 66,67
     *   qr_kiosk     1 / 3 = 33,333… -> 33,33
     *
     * Suman 100,00 sin ajuste, asi que lo que esta prueba afirma es lo otro: que el
     * mayor es `pin_kiosk` y que el reparto sigue siendo correcto cuando el QR no
     * manda. Con el candidato inicial fijado en el primer origen de la lista, un
     * recorrido roto habria devuelto `qr_kiosk` y el ajuste habria caido justo en el
     * indicador con objetivo del §1.3.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(byOrigin: ['qr_kiosk' => 1, 'pin_kiosk' => 2]));

    $breakdown = (new AdoptionIndicators)->originBreakdown($facts);
    $shares = array_map(static fn (AdoptionOriginShare $share): ?float => $share->share, $breakdown);

    expect($shares)->toBe([33.33, 66.67, 0.0, 0.0])
        ->and(round(array_sum($shares), 2))->toBe(100.0);
})->group('RF-IN-08');

it('lleva el residuo al origen mayor cuando el reparto no cuadra por si solo', function (): void {
    /*
     * Tres por PIN y uno por QR sobre seis… no: el caso que hace falta es uno cuyas
     * cuotas redondeadas NO sumen 100, y con el mayor fuera del QR.
     *
     *   pin_kiosk    2 / 6 = 33,333… -> 33,33
     *   qr_kiosk     2 / 6 = 33,333… -> 33,33
     *   manual_admin 2 / 6 = 33,333… -> 33,33
     *
     * Suman 99,99 y falta un centesimo. Con tres empatados, el desempate es
     * determinista y gana el primero de la lista —`qr_kiosk`—, porque dos
     * ejecuciones sobre los mismos datos no pueden dar dos reparticiones distintas:
     * la huella del documento exportado cambiaria sin que cambien los datos.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(byOrigin: [
        'qr_kiosk' => 2,
        'pin_kiosk' => 2,
        'manual_admin' => 2,
    ]));

    $shares = array_map(
        static fn (AdoptionOriginShare $share): ?float => $share->share,
        (new AdoptionIndicators)->originBreakdown($facts),
    );

    expect($shares)->toBe([33.34, 33.33, 33.33, 0.0])
        ->and(round(array_sum($shares), 2))->toBe(100.0);
})->group('RF-IN-08');

it('descuenta el residuo del origen mayor cuando ese origen no es el primero de la lista', function (): void {
    /*
     * EL CASO QUE DISTINGUE «EL MAYOR» DE «EL PRIMERO», y hace falta que el residuo
     * sea NEGATIVO para que se vea:
     *
     *   qr_kiosk     1 / 6 = 16,666… -> 16,67
     *   pin_kiosk    4 / 6 = 66,666… -> 66,67
     *   manual_admin 1 / 6 = 16,666… -> 16,67
     *
     * Las tres redondean hacia arriba y suman 100,01, asi que sobra un centesimo. Va
     * al mayor —`pin_kiosk`, que baja a 66,66— y no al primero de la lista. Con el
     * ajuste en el QR, el indicador con objetivo del §1.3 se moveria por un residuo
     * de redondeo que no tiene nada que ver con el.
     *
     * Y es un hotel real, no un caso de laboratorio: una instalacion recien puesta
     * en marcha ficha casi todo por PIN mientras se reparten las tarjetas, que es
     * exactamente el periodo que el cuadro tiene que saber enseñar.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(byOrigin: [
        'qr_kiosk' => 1,
        'pin_kiosk' => 4,
        'manual_admin' => 1,
    ]));

    $shares = array_map(
        static fn (AdoptionOriginShare $share): ?float => $share->share,
        (new AdoptionIndicators)->originBreakdown($facts),
    );

    expect($shares)->toBe([16.67, 66.66, 16.67, 0.0])
        ->and(round(array_sum($shares), 2))->toBe(100.0);
})->group('RF-IN-08');

// --- Los bordes del juicio del objetivo --------------------------------------

it('deja fuera del objetivo el ratio de correcciones que cae justo en el 2 %', function (
    int $correcciones,
    float $ratio,
    bool $cumple,
): void {
    /*
     * EL OTRO BORDE. Arriba se prueba el del objetivo de MINIMO —«99 de 100 cumplen
     * el ≥ 99 %»—; este es el de los objetivos de MAXIMO, y sin el, escribir `<=`
     * donde va `<` pasa desapercibido para siempre: ningun otro caso del fichero cae
     * justo en el umbral, asi que las dos formas de escribirlo dan el mismo verde.
     *
     * EL LIMITE NO CUMPLE, porque el §1.3 escribe el objetivo con `<` («< 2 %») y el
     * documento exportado lo rotula «menos de 2,00 %»
     * (`reports.adoption.target.at_most`). El juicio y el rotulo tienen que contar lo
     * mismo: un cuadro que dijera «menos de 2 %» y marcara «Dentro del objetivo» un
     * 2,00 % clavado seria un papel que se contradice a si mismo en la misma fila.
     *
     * Sobre 1.000 fichajes aceptados, cada correccion vale un centesimo de punto:
     * 20 son el 2,00 % clavado y 19 el 1,90 %.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(
        byOrigin: ['qr_kiosk' => 1000],
        correcciones: $correcciones,
    ));

    $indicator = indicadorDeAdopcion($facts, AdoptionIndicatorKey::ManualCorrectionsRatio);

    expect($indicator->current)->toBe($ratio)
        ->and($indicator->meetsTarget())->toBe($cumple);
})->with([
    'el 2,00 % clavado' => [20, 2.0, false],
    'una correccion menos' => [19, 1.9, true],
])->group('RF-IN-08');

it('deja fuera del objetivo las 24 h clavadas de resolucion, y dentro el minuto anterior', function (
    int $media,
    bool $cumple,
): void {
    /*
     * El mismo borde en la otra unidad, y merece su propio caso porque **1.440
     * minutos es la cifra que las pruebas de feature siembran a proposito** —una
     * incidencia detectada el 3 a las 07:00 y resuelta el 4 a las 07:00—: si algun
     * dia el juicio se relajara a `<=`, ese mes pasaria de «Fuera del objetivo» a
     * «Dentro» sin que nadie hubiera resuelto nada mas rapido.
     *
     * El §1.3 dice «< 24 h», asi que 24 h exactas no cumplen y 23 h 59 min si.
     */
    $facts = hechosDeAdopcion(periodoDeAdopcion(resueltas: 4, media: $media, mediana: $media));

    expect(indicadorDeAdopcion($facts, AdoptionIndicatorKey::IncidentResolutionMeanMinutes)->meetsTarget())
        ->toBe($cumple);
})->with([
    'las 24 h clavadas' => [1440, false],
    'un minuto menos' => [1439, true],
])->group('RF-IN-08');

it('no compara contra el periodo anterior una foto, ni cuando le llega un valor anterior', function (
    AdoptionIndicatorKey $key,
): void {
    /*
     * LA FOTO NO SE COMPARA POR DEFINICION, no por falta de datos, y la unica forma
     * de afirmarlo es **pasandole un valor anterior** y comprobando que lo descarta:
     * con el periodo anterior vacio —como en el resto del fichero— un `previous`
     * nulo no distingue «no se compara» de «no habia con que comparar».
     *
     * Las cuatro claves de la lista son cola pendiente o contexto: incidencias
     * abiertas y personas sin tarjeta son la foto de hoy y no pertenecen a ningun
     * periodo; el porcentaje resuelto sin servidor acompaña a la disponibilidad y no
     * se juzga por su tendencia; la linea base la declara el cliente una vez y no
     * varia con el mes.
     *
     * Se prueba sobre el objeto de valor y no por la politica porque es **el** que
     * decide: la politica los construye con `snapshot()`, asi que si algun dia uno
     * pasara por `of()` con dos cifras —un indicador nuevo, una refactorizacion—, lo
     * unico que impediria publicar una variacion inventada es esto.
     */
    $indicator = AdoptionIndicator::of($key, 5.0, 4.0);

    expect($indicator->current)->toBe(5.0)
        ->and($indicator->previous)->toBeNull()
        ->and($indicator->delta)->toBeNull();
})->with([
    'incidencias abiertas' => [AdoptionIndicatorKey::OpenIncidents],
    'personas sin tarjeta entregada' => [AdoptionIndicatorKey::EmployeesWithoutCredential],
    'resueltos sin servidor' => [AdoptionIndicatorKey::OfflineResolvedRatio],
    'linea base declarada' => [AdoptionIndicatorKey::BaselineManualMinutesPerMonth],
])->group('RF-IN-08');
