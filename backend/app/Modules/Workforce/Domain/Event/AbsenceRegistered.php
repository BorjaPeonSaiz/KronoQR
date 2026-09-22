<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Event;

use App\Modules\Shared\Domain\Event\DomainEvent;
use DateTimeImmutable;

/**
 * Se ha registrado una ausencia (**RF-GP-04**, tarea 3.10).
 *
 * ## Por que este hecho se audita (regla dura 6)
 *
 * Una ausencia registrada **cambia el resultado del informe de absentismo**: los
 * dias que cubre dejan de contar como no justificados. Eso tiene consecuencias
 * laborales —un expediente disciplinario se sostiene sobre faltas no
 * justificadas— asi que quien la registro, cuando y sobre quien tiene que poder
 * reconstruirse. Ante la duda de si algo con efecto sobre el registro horario de
 * una persona se audita, la respuesta es si.
 *
 * ## `hasNote` y nunca la nota (regla dura 21)
 *
 * La nota puede llevar un diagnostico, y `audit_log` se conserva cuatro años y
 * se enseña en una inspeccion. El trail **no la necesita** para reconstruir el
 * cambio: con el tipo, las dos fechas y la version basta. Lo unico que se dice
 * de ella es si la habia, que es lo que permite explicar por que una ausencia
 * `other` era valida.
 *
 * **El tipo si entra**, y la asimetria es deliberada: sin el, el asiento no
 * describe el hecho —«hubo una ausencia de cinco dias» no dice nada— y
 * `audit_log` es el registro legal con acceso restringido, no un log tecnico. En
 * logs, mensajes de excepcion y `error_events` no aparece ni el tipo ni la nota.
 *
 * ## Sin actor
 *
 * Quien la registro lo resuelve el asiento a partir de la sesion en curso: es
 * una propiedad de la peticion y no del hecho, igual que en el resto de los
 * eventos de este modulo.
 */
final readonly class AbsenceRegistered implements DomainEvent
{
    /** Registrada a mano desde el panel. */
    public const string SOURCE_MANUAL = 'manual';

    /** Registrada por una linea de un fichero de carga. */
    public const string SOURCE_IMPORT = 'import';

    public function __construct(
        /** Identificador publico de la ausencia, que es el de **esta version**. */
        public string $absenceUuid,
        public string $employeeUuid,
        /** `vacation`, `sick_leave`, `leave` u `other`. */
        public string $type,
        public string $startsOn,
        public string $endsOn,
        public int $version,
        /** Si llevaba nota. **Nunca su contenido**: puede ser un diagnostico. */
        public bool $hasNote,
        /**
         * Instante del puerto `Clock`. **Sin valor por defecto** (regla dura 2):
         * un `new DateTimeImmutable` aqui seria el reloj del proceso metido en
         * el dominio por la puerta de atras, y ninguna prueba con reloj
         * congelado lo notaria hasta que el asiento y el evento discreparan.
         */
        private DateTimeImmutable $occurredAt,
        /**
         * De donde vino: `manual` o `import`.
         *
         * La carga por fichero publica **un evento por linea aplicada** y no un
         * asiento resumen, al contrario que la importacion de plantilla. La
         * razon es que alli cada alta deja ademas su propio rastro por el camino
         * de siempre y aqui no hay ningun otro: sin un asiento por ausencia, una
         * carga de cuarenta bajas seria una sola linea del trail y no habria
         * forma de responder «¿quien registro esta?».
         */
        public string $source = self::SOURCE_MANUAL,
        /**
         * Huella del fichero del que salio, o `null` si se registro a mano.
         *
         * Es lo que ata las cuarenta lineas de una carga entre si. **El nombre
         * del fichero no viaja**: lo pone quien sube y puede llevar dentro el
         * nombre de una persona.
         */
        public ?string $fileSha256 = null,
    ) {}

    #[\Override]
    public function eventName(): string
    {
        return 'workforce.absence_registered';
    }

    #[\Override]
    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
