<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\Policy;

use App\Modules\Reporting\Domain\ValueObject\AdoptionFacts;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicator;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicatorKey;
use App\Modules\Reporting\Domain\ValueObject\AdoptionOriginShare;

/**
 * De hechos a indicadores: **toda la aritmetica del cuadro de impacto, en un solo
 * fichero y sin tocar nada** (**RF-IN-08**, **RNF-D-01**).
 *
 * ## Por que la aritmetica no esta en SQL
 *
 * Porque un `round(100.0 * complete / total, 2)` dentro de un `SELECT` no se puede
 * comprobar a mano. Para verificar que «doce de trece jornadas son 92,31 %» habria
 * que levantar PostgreSQL, sembrar trece jornadas con sus tramos y, cuando el
 * numero saliera mal, no se sabria si la culpa es de la division o del `WHERE`.
 * Aqui las divisiones son unitarias, se leen en cinco lineas y el conjunto de
 * prueba se escribe a mano (decision 1 de la ficha 3.13).
 *
 * Y hay una segunda razon, menos obvia: el `NULL` de SQL y el «no se sabe» de este
 * cuadro no son lo mismo. `100.0 * 0 / 0` en PostgreSQL es una division por cero;
 * `COALESCE(…, 0)` convierte «no hubo jornadas» en «0 % de jornadas completas».
 * Las dos salidas son malas y la segunda es peor, porque es creible.
 *
 * ## La invariante, en una frase
 *
 * **Sin denominador no hay porcentaje.** Un periodo sin jornadas, sin fichajes o
 * sin incidencias resueltas da `null`, nunca `0`: «el hotel estaba cerrado» y «el
 * hotel funciono y no se registro nada» son dos noticias opuestas y un cero las
 * cuenta como la misma. Lo mismo vale para el periodo anterior, que es donde mas
 * duele —una instalacion puesta en marcha a mitad de febrero enseñaria un
 * desplome imaginario en el cuadro de marzo—.
 *
 * ## Puro: ni reloj, ni configuracion, ni base de datos
 *
 * No se llama al reloj del sistema (regla dura 2), no se consulta ningun umbral
 * (regla dura 14) y no se toca nada de `Illuminate` (regla dura 1). Los objetivos
 * del §1.3 los lleva
 * {@see AdoptionIndicatorKey::target()}, la linea base llega ya resuelta dentro de
 * los hechos y los dos periodos llegan ya delimitados. Eso es lo que permite que
 * la mutacion se acote a este fichero con MSI ≥ 80 %.
 */
final readonly class AdoptionIndicators
{
    /**
     * Los cuatro origenes de `scan_events.origin` (doc 01 §5.3), en el orden en el
     * que se leen en el reparto.
     *
     * **Salen los cuatro siempre, tambien a cero**, y de ahi que la lista este
     * escrita y no se deduzca de las claves que traiga la consulta: un rosco con
     * tres porciones un mes y cuatro el siguiente cambia de leyenda sin que haya
     * pasado nada, y `import` a cero es informacion —dice que este periodo no se
     * cargo nada de fuera—.
     *
     * Son cadenas y no un enumerado importado porque `Reporting` no puede importar
     * de `Attendance` (doc 02 §1.6): el valor de la columna es el contrato entre
     * los dos modulos. La prueba de integracion del lector es lo que ata las cuatro
     * cadenas a lo que la tabla contiene de verdad.
     *
     * @var list<string>
     */
    public const array ORIGINS = ['qr_kiosk', 'pin_kiosk', 'manual_admin', 'import'];

    /** El origen que mide el §1.3 con su «≥ 98 % de fichajes por QR». */
    private const string QR_ORIGIN = 'qr_kiosk';

    /**
     * Decimales de los porcentajes y de las cuotas.
     *
     * Dos, porque el objetivo de disponibilidad de RNF-D-01 es **99,9 %** y con un
     * decimal no se distinguiria «99,94» de «99,86»: los dos saldrian «99,9» y uno
     * cumple el objetivo y el otro no. El resto de indicadores no necesita tanto y
     * no molesta que lo lleve.
     */
    private const int SCALE = 2;

    /**
     * Los doce indicadores, en el orden de lectura del cuadro.
     *
     * Siempre los doce, tambien cuando alguno no tiene valor: uno que desapareciera
     * de la lista se leeria en la pantalla como una averia del cuadro, y en el CSV
     * como una columna que se movio.
     *
     * ## Cuatro grupos y no un `match` de doce ramas
     *
     * Los grupos son las cuatro preguntas que el cuadro contesta —«¿esta llegando el
     * registro?», «¿se puede fichar?», «¿queda trabajo pendiente?», «¿en que
     * escala?»— y salen en ese orden porque es el orden en el que se leen. Un `match`
     * de doce ramas habria sido una sola funcion por encima del techo de complejidad
     * de doc 02 §3.5, y bajar el techo por este fichero seria cambiar la regla por el
     * caso.
     *
     * Lo que un `match` sobre el enumerado daba gratis —que un indicador nuevo no
     * pueda quedarse sin calcular— lo garantiza aqui la prueba unitaria, que compara
     * las claves devueltas con `AdoptionIndicatorKey::inReadingOrder()`: **y ademas
     * comprueba el orden**, que el `match` no comprobaba y que importa porque el CSV
     * y el PDF recorren esta lista tal cual.
     *
     * @return list<AdoptionIndicator>
     */
    public function evaluate(AdoptionFacts $facts): array
    {
        return [
            ...$this->recordIndicators($facts),
            ...$this->reliabilityIndicators($facts),
            ...$this->pendingIndicators($facts),
            ...$this->contextIndicators($facts),
        ];
    }

    /**
     * El reparto de los fichajes aceptados por origen, **que suma exactamente 100**.
     *
     * ## El residuo del redondeo se absorbe, no se deja a la vista
     *
     * Tres origenes con un tercio cada uno redondean a 33,33 y suman 99,99. Un
     * rosco cuyas porciones suman 99,99 se lee como un dato perdido, y la pregunta
     * «¿donde esta el 0,01 %?» no tiene respuesta que valga la pena dar. Asi que el
     * residuo va al origen **mayor**, que es donde menos se nota en terminos
     * relativos: sobre 98,6 %, un centesimo no cambia nada; sobre 0,3 %, lo cambia
     * todo.
     *
     * Se elige el mayor y no el primero de la lista a proposito: con el primero, un
     * periodo en el que casi todo viene por PIN metería el ajuste en el QR, que es
     * justo el indicador con objetivo del §1.3.
     *
     * @return list<AdoptionOriginShare>
     */
    public function originBreakdown(AdoptionFacts $facts): array
    {
        $accepted = $facts->current->acceptedScans();
        $counts = [];

        foreach (self::ORIGINS as $origin) {
            $counts[$origin] = $facts->current->acceptedScansFrom($origin);
        }

        if ($accepted === 0) {
            // Sin denominador no hay reparto, y `0 %` por origen sobre un periodo
            // sin actividad seria falso: el «0 % por tarjeta» de un hotel cerrado
            // en febrero parece un quiosco averiado.
            return array_map(
                static fn (string $origin): AdoptionOriginShare => new AdoptionOriginShare($origin, 0, null),
                self::ORIGINS,
            );
        }

        $shares = [];

        foreach ($counts as $origin => $scans) {
            $shares[$origin] = self::round(100.0 * $scans / $accepted);
        }

        $shares[self::largestOrigin($counts)] += self::round(100.0 - array_sum($shares));

        return array_map(
            static fn (string $origin): AdoptionOriginShare => new AdoptionOriginShare(
                $origin,
                $counts[$origin],
                self::round($shares[$origin]),
            ),
            self::ORIGINS,
        );
    }

    /**
     * Las claves de los criterios del cuadro, en el orden en el que se leen.
     *
     * Son **claves** y no texto: el dominio no tiene idioma. Las traduce el
     * `Resource` con el idioma de la peticion y la exportacion con el de la
     * instalacion, exactamente igual que en el informe por periodo.
     *
     * Estan aqui y no en el caso de uso porque describen **lo que esta clase
     * calcula**: la definicion de «jornada completa», que cuenta como correccion y
     * de donde sale la disponibilidad son las decisiones de este fichero, y una
     * lista que viviera aparte acabaria describiendo una version anterior del
     * calculo.
     *
     * @return list<string>
     */
    public function criteria(): array
    {
        return [
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
        ];
    }

    /**
     * «¿Esta llegando el registro?» — los tres primeros del §1.3.
     *
     * @return list<AdoptionIndicator>
     */
    private function recordIndicators(AdoptionFacts $facts): array
    {
        return [
            // (a) §1.3, objetivo ≥ 99 %. Denominador: jornadas con algun tramo.
            // Quien no ficho no ha registrado bien ni mal, y meterlo abajo haria
            // que un dia de cierre del hotel bajara el indicador.
            AdoptionIndicator::of(
                AdoptionIndicatorKey::WorkDaysCompleteRatio,
                self::ratio($facts->current->workDaysComplete, $facts->current->workDaysWithActivity),
                self::ratio($facts->previous->workDaysComplete, $facts->previous->workDaysWithActivity),
            ),

            // (b) §1.3, objetivo ≥ 98 %. Sobre los ACEPTADOS: un escaneo rechazado
            // no es un fichaje, y contarlo abajo castigaria al indicador de
            // adopcion por las tarjetas revocadas que el sistema rechaza bien.
            AdoptionIndicator::of(
                AdoptionIndicatorKey::QrScansRatio,
                self::ratio($facts->current->acceptedScansFrom(self::QR_ORIGIN), $facts->current->acceptedScans()),
                self::ratio($facts->previous->acceptedScansFrom(self::QR_ORIGIN), $facts->previous->acceptedScans()),
            ),

            // (c) §1.3, objetivo < 2 %. La misma fraccion que el panel de Grafana
            // pinta con `manual_corrections_total / scans_total`, para que el
            // cuadro y el cuadro de mando no puedan decir cosas distintas.
            AdoptionIndicator::of(
                AdoptionIndicatorKey::ManualCorrectionsRatio,
                self::ratio($facts->current->corrections, $facts->current->acceptedScans()),
                self::ratio($facts->previous->corrections, $facts->previous->acceptedScans()),
            ),
        ];
    }

    /**
     * «¿Se puede fichar?» — RNF-D-01 y su subindicador.
     *
     * @return list<AdoptionIndicator>
     */
    private function reliabilityIndicators(AdoptionFacts $facts): array
    {
        return [
            // (g) §1.3 y RNF-D-01, objetivo ≥ 99,9 %. ATENDIDOS sobre ATENDIDOS MAS
            // INTENTOS FALLIDOS. Un rechazo por regla de negocio esta arriba: el
            // sistema atendio a esa persona y le dijo algo. Lo que esta solo abajo
            // son los intentos que el quiosco no pudo cursar —camara caida, escaner
            // que no arranca, envio que no sale—, y ahi es donde la cola offline
            // hace su trabajo: un fichaje encolado con el servidor caido cuenta
            // ARRIBA, porque la persona ficho (ADR-008).
            AdoptionIndicator::of(
                AdoptionIndicatorKey::ClockingAvailabilityRatio,
                self::ratio($facts->current->attendedScans, $facts->current->clockingAttempts()),
                self::ratio($facts->previous->attendedScans, $facts->previous->clockingAttempts()),
            ),

            // El subindicador que hace creible al de arriba: sin el, «99,94 %» no
            // dice si el merito es del servidor o de la cola.
            AdoptionIndicator::snapshot(
                AdoptionIndicatorKey::OfflineResolvedRatio,
                self::ratio($facts->current->offlineResolvedScans, $facts->current->attendedScans),
            ),
        ];
    }

    /**
     * «¿Queda trabajo pendiente?» — el tiempo de resolucion y las dos fotos de hoy.
     *
     * @return list<AdoptionIndicator>
     */
    private function pendingIndicators(AdoptionFacts $facts): array
    {
        return [
            // (d) §1.3, objetivo < 24 h. `null` cuando no se resolvio ninguna:
            // «cero minutos de media» seria el mejor resultado posible y significa
            // lo contrario —que nadie resolvio nada—.
            AdoptionIndicator::of(
                AdoptionIndicatorKey::IncidentResolutionMeanMinutes,
                self::minutes($facts->current->resolutionMeanMinutes, $facts->current->resolvedOpenShiftIncidents),
                self::minutes($facts->previous->resolutionMeanMinutes, $facts->previous->resolvedOpenShiftIncidents),
            ),

            // La mediana al lado de la media: una sola incidencia olvidada tres
            // semanas dispara la media y no la mediana, y con las dos se distingue
            // «vamos lentos» de «se nos quedo una».
            AdoptionIndicator::of(
                AdoptionIndicatorKey::IncidentResolutionMedianMinutes,
                self::minutes($facts->current->resolutionMedianMinutes, $facts->current->resolvedOpenShiftIncidents),
                self::minutes($facts->previous->resolutionMedianMinutes, $facts->previous->resolvedOpenShiftIncidents),
            ),

            // (d) segunda parte y (e): las dos fotos de hoy. Sin comparacion, y no
            // por falta de datos: una cola pendiente no pertenece a ningun periodo.
            //
            // SIN `(float)` A LA VISTA, y no es un descuido: `AdoptionIndicator` tipa
            // sus cifras como `?float` y PHP ensancha un entero a coma flotante
            // incluso con `strict_types` —es la unica conversion implicita que
            // permite—, asi que el cast era ruido. Mismo criterio en todo el fichero.
            AdoptionIndicator::snapshot(AdoptionIndicatorKey::OpenIncidents, $facts->openIncidents),
            AdoptionIndicator::snapshot(
                AdoptionIndicatorKey::EmployeesWithoutCredential,
                $facts->employeesWithoutDeliveredCredential,
            ),
        ];
    }

    /**
     * Lo que pone las cifras en contexto: las horas y la linea base declarada.
     *
     * @return list<AdoptionIndicator>
     */
    private function contextIndicators(AdoptionFacts $facts): array
    {
        return [
            // (f) RF-IN-08, «horas trabajadas frente a contratadas». Cero es un
            // valor legitimo aqui y no un «no se sabe»: un periodo sin horas
            // trabajadas son cero horas trabajadas, no una incognita.
            AdoptionIndicator::of(
                AdoptionIndicatorKey::WorkedMinutes,
                $facts->current->workedMinutes,
                $facts->previous->workedMinutes,
            ),
            AdoptionIndicator::of(
                AdoptionIndicatorKey::ContractedMinutes,
                $facts->current->contractedMinutes,
                $facts->previous->contractedMinutes,
            ),

            // (h) §1.3, objetivo −80 %. Lo declara el cliente o no existe, y ese
            // `null` viaja tal cual: aqui no hay nada que traducir, porque el cero
            // del ajuste ya se convirtio en ausencia al construir los hechos.
            AdoptionIndicator::snapshot(
                AdoptionIndicatorKey::BaselineManualMinutesPerMonth,
                $facts->baselineManualMinutesPerMonth,
            ),
        ];
    }

    /**
     * Un porcentaje de 0 a 100, o `null` si no hay denominador.
     *
     * **El `null` es el contenido de este metodo**, no un detalle: es el unico
     * sitio del producto donde se decide que un cuadro de adopcion no inventa un
     * cero. Ver el docblock de la clase.
     */
    private static function ratio(int $part, int $total): ?float
    {
        return $total === 0 ? null : self::round(100.0 * $part / $total);
    }

    /**
     * Los minutos de un tiempo medio, o `null` si no hubo ninguna muestra.
     *
     * El recuento entra como segundo argumento y no se deduce de que los minutos
     * sean nulos: asi la ausencia la decide **el numero de incidencias resueltas**,
     * que es el hecho, y no un `null` que podria venir de una columna vacia.
     *
     * Devuelve `?int` y no `?float` porque no divide nada: la media viene ya
     * calculada y redondeada a minutos enteros. Quien la recibe la tipa como `?float`
     * y PHP la ensancha, que es la unica conversion implicita que `strict_types`
     * permite.
     */
    private static function minutes(?int $average, int $samples): ?int
    {
        if ($samples === 0) {
            return null;
        }

        return $average;
    }

    /**
     * El origen con mas fichajes, que es el que absorbe el residuo del redondeo.
     *
     * En empate gana el primero de {@see self::ORIGINS}, que es determinista: dos
     * ejecuciones sobre los mismos datos no pueden dar dos reparticiones distintas,
     * o la huella del documento exportado cambiaria sin que cambien los datos.
     *
     * **Se recorre `$counts` y no `self::ORIGINS`**, y se empieza sin candidato en
     * lugar de con el primero de la lista: asi el resultado no depende de que el
     * recorrido funcione. Con `$largest = self::ORIGINS[0]` de partida, un bucle
     * roto devolvia `qr_kiosk` —que casi siempre ES el mayor— y el fallo quedaba
     * invisible justo en el indicador con objetivo del §1.3.
     *
     * El `(string)` del final es el unico mutante que sobrevive a la mutacion de este
     * fichero, y sobrevive porque es **equivalente**: `$largest` solo puede ser nulo
     * con `$counts` vacio, y quien llama siempre pasa los cuatro origenes. Queda
     * escrito para que nadie lo persiga: matarlo exigiria una prueba de un estado que
     * el tipo de la firma ya impide.
     *
     * @param  array<string, int>  $counts  Los cuatro origenes, en el orden de la lista.
     */
    private static function largestOrigin(array $counts): string
    {
        $largest = null;

        foreach ($counts as $origin => $scans) {
            if ($largest === null || $scans > $counts[$largest]) {
                $largest = $origin;
            }
        }

        return (string) $largest;
    }

    /**
     * Redondeo a {@see self::SCALE} decimales, en un solo sitio.
     *
     * `round()` de PHP usa el redondeo al mas cercano con desempate hacia arriba,
     * que es lo que una persona espera de un porcentaje. Lo que importa aqui no es
     * cual sea la regla sino que sea **la misma** en las cuotas, en los indicadores
     * y en el ajuste del residuo: con dos reglas, el reparto dejaria de sumar 100.
     */
    private static function round(float $value): float
    {
        return round($value, self::SCALE);
    }
}
