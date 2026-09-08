<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Port;

use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportGrantAuthor;
use DateTimeImmutable;

/**
 * La tabla `support_grants` (doc 01 §5, **RF-PD-11**).
 *
 * ## Nada se borra
 *
 * No hay `delete()` y no lo habra (regla dura 5). Revocar **marca**: una
 * concesion revocada o caducada sigue en la lista con sus fechas, porque la
 * pregunta que responde esta tabla —«¿entro el fabricante en mi instalacion,
 * cuando y por que?»— hay que poder contestarla años despues. La retencion de
 * `audit_log` es de cuatro años (RL-02) y esta tabla es su indice legible.
 *
 * ## Las cuatro escrituras son distintas a proposito
 *
 * Guardar, revocar, anotar el hash del token y anotar el uso son cuatro
 * operaciones y no un `save()` generico. Con un `save(SupportGrant)` que
 * escribiera la fila entera, anotar un uso —que ocurre en cada peticion de una
 * sesion de soporte— reescribiria `revoked_at` con lo que la entidad tuviera en
 * memoria, y una revocacion hecha desde otra pestaña un segundo antes se
 * perderia. Son cuatro `UPDATE` de columnas disjuntas y no compiten.
 */
interface SupportGrantRepository
{
    /**
     * Guarda la concesion recien concedida y devuelve su clave interna.
     *
     * Se devuelve el `id` porque es lo que va a `audit_log.actor_id` cuando esa
     * concesion actue, y porque el token se cuelga de esa fila.
     */
    public function store(SupportGrant $grant): int;

    /** La concesion con ese UUID publico, o `null` si no existe. */
    public function findByUuid(string $uuid): ?SupportGrant;

    /**
     * La cuenta que va a autorizar, con su nombre para la pantalla del cliente.
     *
     * **Lo resuelve el repositorio y no el controlador**, aunque el nombre este
     * a mano en la sesion: leerlo del modelo autenticado obligaria al controlador
     * a tratar al actor como una fila de Eloquent —de otro modulo, ademas— para
     * sacarle un atributo. Aqui es un `JOIN` que este adaptador ya hace para
     * listar.
     *
     * `null` si esa cuenta no existe, que detras de `auth:sanctum` no puede
     * pasar y por eso quien llama lo trata como error y no como caso.
     */
    public function authorOf(int $userId): ?SupportGrantAuthor;

    /**
     * La cuenta de gestion activa con ese correo, para `support:grant --as=`.
     *
     * **La consola tiene que decir a nombre de quien concede.** `granted_by` no
     * admite nulos y no debe: autorizar la entrada del fabricante es la firma del
     * encargo del art. 28 RGPD (RL-18), y una firma en blanco no acredita nada.
     * Quien ejecuta el comando tiene acceso al servidor y por tanto ya puede
     * hacer cualquier cosa; lo que este campo hace no es autorizar el acto, es
     * **atribuirlo**.
     */
    public function authorByEmail(string $email): ?SupportGrantAuthor;

    /**
     * La unica cuenta de gestion activa de la instalacion, si hay exactamente
     * una.
     *
     * `null` con cero y con dos o mas. Es lo que permite que `support:grant` sin
     * `--as=` funcione en la instalacion tipica recien montada —un
     * administrador y nadie mas— y **exija decidir** en cuanto hay a quien
     * confundir: atribuir el acto a una cuenta elegida al azar entre varias seria
     * poner el nombre de otra persona en el trail.
     */
    public function soleAuthor(): ?SupportGrantAuthor;

    /**
     * Las mas recientes, de la mas nueva a la mas antigua.
     *
     * @param  int  $limit  El contrato declara `maxItems: 100`. El historico
     *                      completo esta en `audit_log`.
     * @return list<SupportGrant>
     */
    public function recent(int $limit): array;

    /**
     * Las que siguen vivas en ese instante: sin revocar y sin caducar.
     *
     * Lo usa `support:revoke --all`, que es el boton de panico: «corta todo
     * acceso del fabricante ahora mismo».
     *
     * @return list<SupportGrant>
     */
    public function active(DateTimeImmutable $now): array;

    /**
     * Marca la revocacion. La fila se conserva entera (regla dura 5).
     *
     * **Devuelve cuantas filas ha cambiado, y quien llama lo usa para decidir si
     * el hecho ocurrio.** La escritura lleva `WHERE revoked_at IS NULL`, asi que
     * de dos revocaciones simultaneas de la misma concesion —dos pestañas, el
     * panel y la consola a la vez— solo una cambia algo y la otra devuelve `0`.
     *
     * Sin este dato, la comprobacion en memoria del dominio no basta: las dos
     * peticiones leen la fila **antes** de que ninguna escriba, las dos ven
     * `revoked_at` a nulo, las dos creen haber revocado y se escriben **dos
     * asientos del mismo hecho** en una cadena que se conserva cuatro años. Es
     * la misma razon por la que la idempotencia del fichaje se resuelve con el
     * `UNIQUE` de `scan_events.scan_id` y no con un `SELECT` previo.
     *
     * @return int Filas afectadas: `1` si esta llamada revoco, `0` si ya estaba.
     */
    public function markRevoked(int $grantId, DateTimeImmutable $revokedAt, ?int $revokedByUserId): int;

    /**
     * Anota el hash del token emitido, igual que `devices.token_hash`.
     *
     * El token en claro no pasa por aqui y no puede pasar: sale una sola vez,
     * en la respuesta que lo entrega.
     */
    public function storeTokenHash(int $grantId, string $tokenHash): void;

    /** Anota un uso efectivo del token. Un `UPDATE` de una columna, sin candado. */
    public function markAccessed(int $grantId, DateTimeImmutable $accessedAt): void;
}
