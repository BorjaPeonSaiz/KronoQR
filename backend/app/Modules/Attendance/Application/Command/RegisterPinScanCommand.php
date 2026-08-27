<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

use App\Modules\Attendance\Application\Port\ScanIntent;
use DateTimeImmutable;
use SensitiveParameter;

/**
 * La orden de registrar un fichaje de respaldo por PIN (RF-AT-11).
 *
 * **Es un comando propio y no un `RegisterScanCommand` con otro campo**, y la
 * razon es que lo que trae es una **credencial sin resolver de otra clase**: un
 * payload QR es opaco y se entrega tal cual, mientras que aqui hay dos datos
 * —quien dice ser y que teclea— que hay que descifrar y comprobar antes de que
 * exista un escaneo del que hablar. Meter los dos en el comando del camino
 * normal habria dejado tres campos mutuamente excluyentes en un solo DTO, y con
 * ellos la posibilidad de construir un escaneo que declara tarjeta y PIN a la
 * vez. A partir de la resolucion los dos caminos convergen en el mismo caso de
 * uso, que es donde tienen que converger.
 *
 * **El PIN llega cerrado, no en claro** (RL-12, regla dura 19). El quiosco lo
 * sella con la clave publica de la instalacion en el momento de teclearlo, de
 * modo que puede encolarlo sin red sin dejar en la tablet nada que sirva para
 * suplantar a nadie. El formato exacto lo documenta
 * `Shared\Application\Port\SealedPinOpener`.
 *
 * **El origen no viaja aqui**: es siempre `ScanOrigin::PIN_KIOSK`, y lo fija el
 * caso de uso. Si el comando pudiera declararlo, un fichaje por PIN podria
 * presentarse como un escaneo de tarjeta y perderia la marca de revision que
 * RF-AT-11 exige.
 *
 * **Dos marcas de tiempo y solo una viaja aqui** (regla dura 9, RF-AT-09), igual
 * que en el camino normal: `occurredAt` es del dispositivo y puede llegar con
 * retraso desde la cola offline; `recordedAt` lo pone el servidor.
 */
final readonly class RegisterPinScanCommand
{
    /**
     * @param  string  $scanId  UUID v7 generado en el cliente: la clave de idempotencia
     *                          (regla dura 8, RF-AT-07).
     * @param  string  $employeeCode  Codigo opaco del empleado (doc 01 §5.5). Es la mitad
     *                                publica de la credencial del portal (ADR-015): la misma
     *                                que se usa para entrar a ver el registro propio, para no
     *                                inventar una segunda credencial que gestionar.
     * @param  string  $sealedPin  Base64 del sobre cerrado con el PIN. **Nunca se registra,
     *                             ni siquiera cerrado**: no aporta nada al diagnostico y
     *                             conservarlo convertiria una copia de la base de datos en
     *                             una copia de los PIN el dia que la clave privada se filtre.
     * @param  DateTimeImmutable  $occurredAt  Momento real del fichaje, en UTC.
     * @param  array<string, scalar>  $clientMeta  Version de la app, modelo de tablet. Nunca datos
     *                                             personales y nunca nada del PIN.
     */
    public function __construct(
        public string $scanId,
        public string $employeeCode,
        #[SensitiveParameter] public string $sealedPin,
        public DateTimeImmutable $occurredAt,
        public int $deviceId,
        public string $deviceUuid,
        public ScanIntent $intent = ScanIntent::AUTO,
        public array $clientMeta = [],
    ) {}
}
