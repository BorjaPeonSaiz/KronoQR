<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DoctorProbe;
use App\Modules\Product\Application\Port\DoctorTranslator;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorFinding;
use App\Modules\Product\Domain\ValueObject\DoctorReport;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\Port\Clock;
use Throwable;

/**
 * Ejecuta todas las sondas y compone el informe de `product:doctor`
 * (**RF-PD-13**).
 *
 * ## La red debajo de cada sonda
 *
 * Las sondas se comprometen a no lanzar, pero este caso de uso no lo da por
 * hecho, y es la decision que hace que el comando sirva: **`doctor` se ejecuta
 * cuando algo esta roto**. Si Postgres no responde, la sonda de base de datos
 * tiene que fallar sola y las otras siete tienen que seguir. Sin este `try`, un
 * fallo en la primera dejaria a la persona de informatica del hotel con un
 * volcado de pila y sin saber nada del disco, del certificado ni de las colas.
 *
 * Cuando una sonda revienta, su familia aparece como **una comprobacion en
 * `failure`** con la clase de la excepcion en `details` —nunca su mensaje, que
 * podria llevar una cadena de conexion con contraseña— y un `fix` que dice a
 * quien avisar y con que.
 *
 * ## Con licencia caducada o ausente funciona igual (regla dura 15)
 *
 * No hay ninguna comprobacion de licencia en este camino. La sonda de licencia
 * **informa** de su estado y su hallazgo esta acotado a `warning` como maximo
 * (ADR-019): `doctor` es lo que se necesita cuando algo va mal, y degradarlo por
 * una licencia vencida seria quitarle al cliente la herramienta con la que
 * arreglaria el problema.
 *
 * ## El idioma es un argumento
 *
 * Y no el locale del proceso. `--lang=en` no puede cambiarselo a nadie mas: el
 * mismo proceso puede estar sirviendo un paquete pedido desde el panel en
 * español.
 */
final readonly class RunDoctorHandler
{
    /**
     * @param  list<DoctorProbe>  $probes
     */
    public function __construct(
        private array $probes,
        private DoctorTranslator $translator,
        private Clock $clock,
        private string $productVersion,
    ) {}

    public function handle(string $locale): DoctorReport
    {
        $checks = [];

        foreach ($this->probes as $probe) {
            foreach ($this->findingsOf($probe) as $finding) {
                $checks[] = $this->resolve($finding, $locale);
            }
        }

        return DoctorReport::of($this->clock->now(), $this->productVersion, $checks);
    }

    /**
     * @return list<DoctorFinding>
     */
    private function findingsOf(DoctorProbe $probe): array
    {
        try {
            return $probe->run();
        } catch (Throwable $failure) {
            return [DoctorFinding::failure(
                $probe->family().'.probe',
                params: ['family' => $probe->family()],
                // La CLASE de la excepcion, jamas su mensaje: un
                // `PDOException` lleva el DSN, y el DSN lleva la contraseña.
                // Este informe viaja al fabricante dentro del paquete.
                details: ['exception' => $failure::class],
            )];
        }
    }

    private function resolve(DoctorFinding $finding, string $locale): DoctorCheck
    {
        $summary = $this->translator->translate($finding->messageKey(), $finding->params, $locale);
        $fixKey = $finding->fixKey();
        $fix = $fixKey === null ? null : $this->translator->translate($fixKey, $finding->params, $locale);

        return new DoctorCheck(
            id: $finding->id,
            status: $finding->status,
            // Sin traduccion, la clave. Un informe con una clave a la vista es
            // feo y delata el descuido en la primera ejecucion; uno con la
            // frase en blanco lo esconde, y el cliente se queda sin saber que
            // se comprobo.
            summary: $summary ?? $finding->messageKey(),
            fix: $finding->status === DoctorStatus::Ok ? null : ($fix ?? $fixKey),
            details: $finding->details,
        );
    }
}
