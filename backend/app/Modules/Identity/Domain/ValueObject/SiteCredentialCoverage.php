<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Cuanta gente de un centro todavia no puede fichar con tarjeta (RF-QR-08,
 * doc 02 §8.2).
 *
 * Es lo que alimenta las dos metricas de esta tarea, las dos etiquetadas por
 * centro porque quien tiene que actuar es la persona de RRHH de ese hotel:
 *
 * ```
 * credentials_pending_print{site}                 -> pendingPrint
 * employees_without_delivered_credential{site}    -> withoutDeliveredCredential
 * ```
 *
 * **La segunda debe llegar a cero antes del primer dia de cada incorporacion**
 * (§8.2). Es la unica metrica de este producto que mide un proceso humano y no
 * un sistema, y por eso incluye a quien tiene la tarjeta impresa pero sin
 * entregar: un PDF impreso que sigue en una bandeja no sirve de nada a las 06:00.
 *
 * **Durante una rotacion de clave hay un tercer recuento** (RF-QR-07, tarea
 * 2.12): cuanta gente sigue fichando con una tarjeta firmada por la clave
 * saliente. No cabe en las dos anteriores porque esas personas **si pueden
 * fichar** —el solape existe justo para eso—, y contarlas ahi dispararia una
 * alerta que no corresponde a ningun problema. Es su propio indicador, y su
 * bajada hasta cero es el avance de la reimpresion:
 *
 * ```
 * credentials_pending_reprint{site,key_id}        -> pendingReprint
 * ```
 *
 * **Y un cuarto que siempre deberia valer cero**: tarjetas vivas firmadas con
 * una clave que la instalacion **ya no reconoce**. Es el unico recuento de esta
 * clase que describe un fallo y no un proceso: quien lleve una de esas tarjetas
 * **no puede fichar ahora mismo** y el panel no lo delataba, porque su fila se
 * ve entregada y correcta. Ocurre cuando se vacia `QR_SIGNING_KEY_PREVIOUS`
 * antes de terminar la reimpresion —el escenario de clave comprometida del §7
 * del runbook— o por un descuido de configuracion.
 *
 * ```
 * credentials_active_unknown_key{site,key_id}     -> unknownKeyCards
 * ```
 */
final readonly class SiteCredentialCoverage
{
    public function __construct(
        public int $siteId,
        /** Nombre del centro. Es la etiqueta legible del panel; la metrica usa el identificador. */
        public string $siteName,
        /** Empleados de alta del centro. Es el denominador de las dos metricas. */
        public int $employees,
        /** Credenciales activas sin `printed_at`: esperan a pasar por la impresora. */
        public int $pendingPrint,
        /** Personas de alta que todavia no tienen una tarjeta entregada en la mano. */
        public int $withoutDeliveredCredential,
        /** La clave saliente de una rotacion en curso, o `null` fuera de una rotacion. */
        public ?string $retiringKeyId = null,
        /** Personas cuya tarjeta en uso sigue firmada con esa clave saliente. */
        public int $pendingReprint = 0,
        /**
         * Tarjetas activas por cada `key_id` que **ya no esta en el llavero**.
         * Vacio es lo normal y lo unico correcto.
         *
         * Se cuenta sobre la tabla y no sobre las filas del panel: una tarjeta
         * huerfana de alguien que ya no esta de alta tambien delata la rotacion
         * mal cerrada, y el panel solo lista a la plantilla activa.
         *
         * @var array<string, int>
         */
        public array $unknownKeyCards = [],
    ) {
        if ($siteId < 1) {
            throw new InvalidArgumentException('La cobertura de credenciales pertenece a un centro concreto.');
        }

        $this->guardCountsFitTheWorkforce();
        $this->guardUnknownKeysAreWellFormed();
    }

    /**
     * Los tres recuentos que se miden **contra la plantilla**: ni negativos ni
     * mayores que la gente que hay de alta.
     */
    private function guardCountsFitTheWorkforce(): void
    {
        $counts = [$this->pendingPrint, $this->withoutDeliveredCredential, $this->pendingReprint];

        if ($this->employees < 0 || min($counts) < 0) {
            throw new InvalidArgumentException('Un recuento de cobertura no puede ser negativo.');
        }

        if (max($counts) > $this->employees) {
            throw new InvalidArgumentException('No puede faltarle la tarjeta a mas gente de la que hay de alta.');
        }
    }

    /**
     * El cuarto recuento se valida aparte porque **no se mide contra la
     * plantilla**: una tarjeta huerfana puede ser de alguien que ya no esta de
     * alta, y ahi el recuento supera legitimamente a `employees`. Lo unico
     * imposible es una entrada sin clave o sin tarjetas.
     */
    private function guardUnknownKeysAreWellFormed(): void
    {
        foreach ($this->unknownKeyCards as $keyId => $cards) {
            if ($keyId === '' || $cards < 1) {
                throw new InvalidArgumentException('Una clave desconocida se declara con su key_id y al menos una tarjeta.');
            }
        }
    }

    /** Cuantas tarjetas vivas quedan con una clave que ya nadie reconoce. */
    public function unknownKeyCardsTotal(): int
    {
        return array_sum($this->unknownKeyCards);
    }

    /**
     * Los `key_id` huerfanos, ordenados, para que el aviso de consola y el
     * fichero de metricas salgan siempre igual.
     *
     * @return list<string>
     */
    public function unknownKeyIds(): array
    {
        $keyIds = array_keys($this->unknownKeyCards);
        sort($keyIds);

        return $keyIds;
    }
}
