<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Port;

use App\Modules\Kiosk\Domain\Model\PairingRequest;
use DateTimeImmutable;

/**
 * La persistencia de las solicitudes de emparejamiento (**RF-PD-06**, tarea
 * 5.6).
 *
 * ## EL ADAPTADOR ARBITRA LA CONCURRENCIA, NO EL AGREGADO
 *
 * Es lo mas importante de este puerto y hay que leerlo antes de implementarlo.
 * {@see PairingRequest} decide **con la foto que tiene delante**: dice si una
 * solicitud se puede confirmar y que devolverle a la tablet. Pero entre esa
 * lectura y la escritura pueden colarse otras peticiones —dos administradores
 * tecleando el mismo codigo, dos sondeos de la misma tablet cruzados—, y la foto
 * ya no vale.
 *
 * Quien resuelve el empate es PostgreSQL: {@see markConfirmed()} y
 * {@see markClaimed()} escriben con la condicion de estado **en el `WHERE`** y
 * devuelven si afectaron a alguna fila. **Si afectaron a cero, el caso de uso
 * degrada su desenlace a rechazo**, aunque el agregado hubiera dicho que si.
 *
 * No se hace con un `SELECT` previo y una comprobacion en PHP: esa es la
 * implementacion con condicion de carrera, y aqui produciria dos tokens para la
 * misma tablet o dos dispositivos para el mismo codigo.
 *
 * ## Aqui viven el hash y el `hash_equals`, no en el dominio
 *
 * `findByPairingId()` devuelve el agregado **y** el hash del secreto, para que el
 * caso de uso pueda pedirle a {@see PairingSecrets} la comparacion en tiempo
 * constante. El dominio no sabe con que algoritmo se guarda un secreto y no tiene
 * por que saberlo (regla dura 1).
 *
 * ## Habla en escalares y en tipos propios
 *
 * Nunca en modelos Eloquent (ADR-025, restriccion 2). Lo que sale de aqui es el
 * agregado, hashes y enteros.
 */
interface PairingRequests
{
    /**
     * Crea una solicitud pendiente y devuelve su identificador publico.
     *
     * **El codigo llega ya sorteado y hasheado**, y esta operacion puede fallar
     * por colision: el UNIQUE parcial
     * `device_pairing_requests_code_hash_pending_uidx` impide que dos solicitudes
     * pendientes compartan codigo, que es la invariante «un codigo señala una
     * sola solicitud». Cuando eso ocurre se devuelve `null` y **quien llama
     * vuelve a sortear**.
     *
     * Se resuelve asi y no con un `SELECT` previo porque preguntar «¿existe este
     * codigo?» antes de insertar tiene condicion de carrera con otro
     * `POST /kiosk/pair` simultaneo; el indice no la tiene (mismo criterio que la
     * idempotencia del fichaje, regla dura 8).
     *
     * @param  string  $codeHash  SHA-256 del codigo de seis digitos.
     * @param  string  $secretHash  SHA-256 del secreto de recogida.
     * @return string|null El `pairing_id` publico (UUID v7), o `null` si el codigo
     *                     ya lo tenia otra solicitud pendiente.
     */
    public function create(
        string $codeHash,
        string $secretHash,
        ?string $appVersion,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): ?string;

    /**
     * La solicitud pendiente cuyo codigo coincide, si la hay.
     *
     * **Solo entre las pendientes**, que es donde el codigo es unico. Una
     * solicitud confirmada o consumida no se encuentra por su codigo, y por eso
     * teclear dos veces el mismo numero produce el rechazo generico en lugar de
     * un segundo dispositivo.
     *
     * **Devuelve tambien de que solicitud vino**: la version de la PWA que
     * declaro la tablet y cuando pidio el codigo. No son datos del agregado
     * —no deciden nada— sino del hecho, y el panel los enseña para que quien
     * acaba de teclear seis digitos pueda contrastarlos con la tablet que tiene
     * delante. Un digito cambiado confirma otra tablet, y esto es lo que lo hace
     * visible en el acto en lugar de semanas despues.
     *
     * @return array{request: PairingRequest, app_version: string|null, requested_at: DateTimeImmutable}|null
     */
    public function findPendingByCodeHash(string $codeHash): ?array;

    /**
     * La solicitud por su identificador publico, con el hash de su secreto.
     *
     * Devuelve `null` cuando no existe, y quien llama **tiene que seguir haciendo
     * el mismo trabajo**: comparar contra un hash señuelo y respetar el suelo de
     * tiempo. Un camino que se ahorra la comparacion cuando la fila no existe
     * convierte este endpoint en un comprobador de `pairing_id` (regla dura 17).
     *
     * @return array{request: PairingRequest, secret_hash: string}|null
     */
    public function findByPairingId(string $pairingId): ?array;

    /**
     * Marca la solicitud como confirmada, **solo si sigue pendiente**.
     *
     * @return bool `false` si otra peticion se adelanto. El caso de uso degrada a rechazo.
     */
    public function markConfirmed(
        string $pairingId,
        int $deviceId,
        ?int $confirmedByUserId,
        DateTimeImmutable $now,
    ): bool;

    /**
     * Marca la solicitud como consumida, **solo si estaba confirmada**.
     *
     * Es la escritura que garantiza el «un solo uso» del token: dos sondeos
     * simultaneos entran los dos, y solo uno cambia una fila.
     *
     * @return bool `false` si otro sondeo llego antes. El caso de uso degrada a rechazo.
     */
    public function markClaimed(string $pairingId, DateTimeImmutable $now): bool;

    /**
     * Borra las solicitudes creadas hace mas de `$olderThan`.
     *
     * **Perezosa y no programada**: se llama al crear una solicitud nueva. Un
     * barrido en el scheduler que dejara de correr no purgaria nada en silencio,
     * y este solo hace falta cuando alguien empareja. No es retencion legal —aqui
     * no hay ni un dato personal— sino higiene: la tabla no crece sin limite y el
     * espacio de codigos no se agota.
     *
     * @return int Cuantas filas se borraron, para la traza.
     */
    public function purgeOlderThan(DateTimeImmutable $olderThan): int;

    /**
     * Borra las solicitudes **pendientes que ya han caducado**.
     *
     * Es la otra mitad de la purga, y hace falta por el indice unico parcial: una
     * pendiente caducada sigue ocupando su `code_hash` entre las pendientes —el
     * indice no sabe de relojes— asi que reservaria uno de los codigos durante
     * las 24 h que tarda el barrido por antiguedad. Con diez minutos de vida y
     * diez peticiones por minuto de techo, eso es un goteo que estrecha el
     * espacio de sorteo sin que nadie lo vea.
     *
     * Se llama en la misma peticion que la de arriba, y por eso la cuenta de
     * solicitudes vivas puede fiarse de que lo pendiente esta vivo de verdad.
     *
     * @return int Cuantas filas se borraron, para la traza.
     */
    public function purgeExpiredPending(DateTimeImmutable $now): int;

    /**
     * Cuantas solicitudes **pendientes y sin caducar** hay ahora mismo.
     *
     * La cota que se compara con esto (`kiosk.pairing.max_live_pending`) es un
     * control de recursos, no de negocio: una instalacion es un hotel con unos
     * pocos quioscos, asi que cientos de solicitudes vivas a la vez solo pueden
     * ser una inundacion de la ruta publica. El limitador por IP frena a UN
     * origen; esto frena al conjunto, que es lo que aquel no puede hacer cuando
     * el trafico viene repartido.
     */
    public function countLivePending(DateTimeImmutable $now): int;
}
