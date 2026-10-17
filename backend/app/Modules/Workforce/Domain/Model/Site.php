<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Model;

use App\Modules\Workforce\Domain\Exception\SiteTimezoneLocked;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezone;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneCorrection;
use App\Modules\Workforce\Domain\ValueObject\SiteTimezoneLock;
use InvalidArgumentException;

/**
 * Un centro de trabajo (doc 01 §5.5).
 *
 * Lo que este modelo protege es la zona horaria, **el dato del que depende
 * RN-05** y el unico de esta tabla cuyo error cambia el resultado de un calculo
 * legal: que sea una zona de verdad y que no cambie una vez que hay registro
 * horario (RN-23, ADR-056).
 *
 * `id` es `null` mientras el centro no se ha guardado. Se acepta esa nulabilidad
 * en lugar de inventar un identificador en el dominio porque `sites` no tiene
 * UUID publico: su clave la asigna la base de datos.
 */
final readonly class Site
{
    public function __construct(
        public ?int $id,
        public string $name,
        public SiteTimezone $timezone,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Un centro necesita nombre.');
        }

        if ($id !== null && $id < 1) {
            throw new InvalidArgumentException('El identificador de un centro es positivo.');
        }
    }

    public static function create(string $name, SiteTimezone $timezone): self
    {
        return new self(null, $name, $timezone);
    }

    public function rename(string $name): self
    {
        return new self($this->id, $name, $this->timezone);
    }

    /**
     * Cambia la zona horaria **mientras no haya registro horario** (RN-23,
     * ADR-056).
     *
     * La zona decide a que jornada va cada tramo (RN-05) y con ella se presenta
     * y se exporta todo el historico. Cambiarla con tramos ya fichados repintaria
     * el pasado con una zona distinta de la que atribuyo su `work_date`, asi que,
     * desde el primero, se niega con {@see SiteTimezoneLocked}.
     *
     * **La misma zona no es un cambio** y no se niega nunca: el panel manda el
     * formulario entero, y guardar el nombre con la zona de siempre tiene que
     * funcionar con registro horario o sin el. Devuelve `$this`.
     *
     * Si existe registro horario lo resuelve quien llama ({@see SiteTimezoneLock}):
     * el dominio no lo consulta. Solo lo llama `UpdateSiteHandler`, que lo
     * pregunta con la fila del centro bloqueada (lo comprueba
     * `SiteTimezoneLockBoundaryTest`).
     *
     * @throws SiteTimezoneLocked
     */
    public function relocateTo(SiteTimezone $timezone, SiteTimezoneLock $lock): self
    {
        if ($timezone->equals($this->timezone)) {
            return $this;
        }

        if ($lock->isLocked()) {
            throw SiteTimezoneLocked::because($this->timezone->identifier, $timezone->identifier);
        }

        return new self($this->id, $this->name, $timezone);
    }

    /**
     * Corrige la zona de una instalacion puesta en marcha con la equivocada,
     * **aunque ya haya registro horario** (RN-23, ADR-056 §3).
     *
     * Es la salida de {@see self::relocateTo()} para el unico caso legitimo: un
     * error de la puesta en marcha. No mueve ninguna jornada —cada tramo
     * conserva su `work_date`— y el historico pasa a presentarse con la zona
     * nueva. Por eso **solo la llama `CorrectSiteTimezoneHandler`**, la orden de
     * consola que exige motivo, responsable y confirmacion y deja asiento
     * (`SiteTimezoneLockBoundaryTest`).
     *
     * **La firma de la correccion va en la firma del metodo** (V21-1-AR-5):
     * sin un {@see SiteTimezoneCorrection} —motivo valido, tramos que ya
     * existian y cuenta que responde— no se puede llamar, y eso lo comprueba el
     * compilador y no una revision. Una firma valida ya dice que hay registro
     * horario (`SiteTimezoneCorrection::of` exige al menos un tramo). El metodo
     * no la guarda: viaja en el asiento `site.updated` que publica el caso de
     * uso, que es quien la construye y se la pasa.
     *
     * La misma zona devuelve `$this`.
     */
    public function correctTimezone(SiteTimezone $to, SiteTimezoneCorrection $correction): self
    {
        if ($to->equals($this->timezone)) {
            return $this;
        }

        return new self($this->id, $this->name, $to);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->name, $this->timezone);
    }
}
