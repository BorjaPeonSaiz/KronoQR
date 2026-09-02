<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Application\Port\LicenseStatePublisher;
use App\Modules\Product\Application\Port\LicenseVerifier;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Resuelve el estado de la licencia: leer la fila, verificar la firma y
 * comparar la vigencia con el reloj (RF-PD-04, ADR-018).
 *
 * ## Es el unico sitio donde se resuelve
 *
 * Igual que `GetSettingsHandler` es el unico punto de resolucion de la
 * configuracion (tarea 5.1), este lo es de la licencia. El `FeatureGate`, el
 * endpoint, los dos comandos de consola y la sonda de salud salen todos de aqui;
 * si cada uno leyera la tabla por su cuenta, tendriamos cuatro respuestas
 * posibles a la misma pregunta y la prueba de arquitectura de ADR-023 no
 * significaria nada.
 *
 * ## Nunca lanza
 *
 * Ni sin fila, ni con la fila corrupta, ni sin clave publica configurada. Es la
 * regla dura 15 aplicada al detalle: si esta resolucion pudiera fallar con una
 * excepcion, esa excepcion acabaria algun dia en el camino de una pantalla o de
 * un comando, y el sintoma seria «el sistema se ha caido por la licencia». Todo
 * fallo se convierte en un estado con nombre —`absent` o `unverifiable`— que el
 * producto sabe explicar.
 *
 * ## `touch`
 *
 * `last_verified_at` se escribe **solo cuando alguien verifica a proposito**:
 * `GET /api/v1/license`, `license:show` y la activacion. Las lecturas del
 * `FeatureGate` no escriben. La alternativa —anotar cada resolucion— convertiria
 * cada pantalla del panel en una escritura sobre la tabla de licencia, y ademas
 * llenaria de ruido la unica marca que sirve para responder «¿esta instalacion
 * ha arrancado desde que le pasamos la clave?».
 */
final readonly class GetLicenseStatusHandler
{
    public function __construct(
        private LicenseRepository $licenses,
        private LicenseVerifier $verifier,
        private LicenseStatePublisher $probe,
        private Clock $clock,
        /**
         * Dias de antelacion del aviso de caducidad.
         *
         * Llega **ya resuelto** desde el `ServiceProvider` (30 de serie,
         * `config/license.php`). El caso de uso no consulta la configuracion,
         * por lo mismo que no la consulta el dominio: una prueba tiene que poder
         * fijar la ventana sin tocar el estado global.
         */
        private int $expiryWarningDays,
    ) {}

    public function handle(bool $touch = false): LicenseStatus
    {
        return $this->publish($this->resolve($touch));
    }

    private function resolve(bool $touch): LicenseStatus
    {
        $now = $this->clock->now();
        $stored = $this->licenses->current();

        if (! $stored instanceof StoredLicense) {
            return LicenseStatus::absent($now, $this->expiryWarningDays);
        }

        $verification = $this->verifier->verify($stored->signedKey);

        if (! $verification->isVerified()) {
            // Una clave ilegible degrada exactamente igual que una ausente y no
            // se anota como verificada: `last_verified_at` significa «se
            // comprobo y era buena».
            return LicenseStatus::unverifiable(
                $verification->rejection ?? throw new \LogicException('A rejected verification has a reason.'),
                $now,
                $this->expiryWarningDays,
            );
        }

        if ($touch) {
            $this->licenses->markVerified($now);
        }

        return LicenseStatus::of($verification->license, $now, $this->expiryWarningDays);
    }

    /**
     * Deja el estado donde `GET /api/v1/health` pueda leerlo sin tocar la base
     * de datos (§10.5).
     *
     * **Aqui y no en el `FeatureGate`**, aunque el gate sea quien mas veces
     * resuelve: si solo publicara el gate, la sonda seguiria diciendo `unknown`
     * justo despues de activar una clave —que es el momento en el que alguien la
     * mira— hasta que una pantalla pidiera una funcionalidad accesoria. Al
     * publicarlo el punto unico de resolucion, se refresca por cualquier camino:
     * el panel, la consola y la activacion.
     */
    private function publish(LicenseStatus $status): LicenseStatus
    {
        $this->probe->publish($status->state->value);

        return $status;
    }

    /**
     * La licencia guardada, para quien necesita su huella o cuando se activo.
     *
     * Existe para que el endpoint y `license:show` no tengan que pedirle la fila
     * al repositorio por su cuenta —lo que abriria un segundo camino de lectura
     * de `license` y romperia la prueba de arquitectura de ADR-023.
     */
    public function stored(): ?StoredLicense
    {
        return $this->licenses->current();
    }
}
