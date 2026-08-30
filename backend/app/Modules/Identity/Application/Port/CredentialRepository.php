<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\Exception\EmployeeAlreadyHasCredential;
use App\Modules\Identity\Domain\Model\Credential;

/**
 * Las credenciales, vistas por los casos de uso de este modulo.
 *
 * Habla en {@see Credential} y en escalares, nunca en modelos Eloquent
 * (ADR-025, restriccion 2).
 *
 * **El token en claro no entra ni sale por aqui.** Lo unico que viaja es su
 * hash, que es lo que se persiste y por lo que se busca (§5.2, paso 4). No hay
 * ningun metodo que devuelva un token porque no existe ninguna razon para
 * leerlo: si se pierde la tarjeta, se reemite.
 */
interface CredentialRepository
{
    /**
     * Persiste una credencial nueva y devuelve su clave interna.
     *
     * Devuelve el `id` porque es lo que `audit_log.subject_id` necesita: el
     * asiento de una emision tiene que apuntar a la fila concreta.
     *
     * **No comprueba antes si el empleado ya tiene una credencial pendiente.**
     * Lo intenta y deja hablar al indice parcial
     * `one_pending_credential_per_employee`: un `SELECT` previo seria una
     * condicion de carrera con aspecto de comprobacion, exactamente igual que en
     * el alta de empleado. La otra mitad de la invariante —dos tarjetas
     * escaneables con la misma clave— la declara
     * `one_active_credential_per_key_and_employee` y salta al imprimir, que es
     * cuando la credencial estrena `key_id` (ADR-034).
     *
     * @throws EmployeeAlreadyHasCredential
     */
    public function add(Credential $credential): int;

    /**
     * Guarda los cambios de una credencial que ya existe, identificada por su
     * UUID. Escribe **solo la revocacion**: la impresion y la entrega tienen sus
     * propios metodos porque las dos necesitan una condicion en el `WHERE`.
     */
    public function save(Credential $credential): void;

    /**
     * **Acuña el QR de forma condicional**: escribe `key_id`, `secret_hash` y
     * `printed_at` con un `UPDATE ... WHERE printed_at IS NULL` y devuelve si de
     * verdad cambio alguna fila (paso 5 del orden de impresion, ADR-034).
     *
     * **El `WHERE` es la garantia, no una comprobacion previa.** Entre leer la
     * credencial y escribirla cabe otra impresion —dos pestañas del panel, un
     * lote y una impresion suelta a la vez— y ese hueco produciria dos tarjetas
     * con QR distinto de las que solo una valdria, sin que nadie se enterase
     * hasta que alguien no pudiera fichar. Es la misma decision que en el alta de
     * empleado: quien decide es la base de datos.
     *
     * Devolver `false` **no es un error de infraestructura**: significa que otro
     * proceso llego antes. Quien llama deshace la transaccion y responde `409`.
     */
    public function markPrinted(Credential $credential): bool;

    /**
     * Registra la entrega con `UPDATE ... WHERE delivered_at IS NULL` y devuelve
     * si cambio alguna fila (RF-QR-06).
     *
     * Condicional por el mismo motivo que {@see markPrinted()}: dos personas de
     * RRHH marcando la misma entrega a la vez escribirian dos responsables
     * distintos, y el segundo borraria al primero del registro que existe
     * precisamente para saber quien entrego que.
     */
    public function markDelivered(Credential $credential): bool;

    /**
     * La credencial **vigente** de cada empleado de la lista y, si no le queda
     * ninguna activa, la ultima que tuvo.
     *
     * Es lo que el panel de RF-QR-08 necesita para derivar sus cinco estados de
     * una sola consulta, en lugar de una por persona.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, Credential> Indexado por `employee_id`. Los empleados sin ninguna fila no aparecen.
     */
    public function latestForEmployees(array $employeeIds): array;

    /**
     * Las credenciales **pendientes de imprimir** de esos empleados: activas y
     * sin `printed_at`.
     *
     * Es la seleccion de `credentials:print-batch --pending`, y es lo que hace
     * que el lote sea idempotente por construccion (ADR-034): la segunda pasada
     * no encuentra nada. **No existe ninguna variante que devuelva tambien las ya
     * impresas**, porque no existe la reimpresion.
     *
     * @param  list<int>  $employeeIds
     * @return list<Credential> En el orden en que se reciben los empleados.
     */
    public function pendingPrintForEmployees(array $employeeIds): array;

    public function findByUuid(string $uuid): ?Credential;

    /**
     * La credencial activa de un empleado, si la tiene.
     *
     * Es la que hay que revocar antes de reemitir (doc 01 §5.2).
     *
     * **Durante una rotacion con solape puede haber dos** —la que esta en la
     * mano y la reemision pendiente de imprimir—, y entonces devuelve **la que
     * la persona esta usando**: primero la entregada, luego la impresa, luego la
     * mas reciente. Es la que hay que revocar al reemitir por perdida, y no la
     * que todavia no ha salido de la impresora. Quien busque la pendiente tiene
     * {@see pendingPrintForEmployees()}.
     */
    public function activeForEmployee(int $employeeId): ?Credential;

    /**
     * **Paso 4 de la verificacion del §5.2**: la credencial cuyo token produce
     * ese hash, firmada con ese `key_id`.
     *
     * Se busca por la pareja y no solo por el hash porque la pareja es la que
     * lleva indice unico, y porque durante un solape de claves dos credenciales
     * distintas podrian —con probabilidad despreciable, pero podrian— no ser
     * distinguibles sin el.
     *
     * **Devuelve tambien las revocadas.** Quien llama tiene que poder
     * distinguir «no existe» de «revocada» para escribir el `scan_events.result`
     * correcto; lo que no puede es contarselo al cliente (RS-03).
     */
    public function findByKeyAndSecretHash(string $keyId, string $secretHash): ?Credential;

    /**
     * Cuantas credenciales **activas** siguen firmadas con esa clave.
     *
     * Es la pregunta que decide si la clave anterior de un solape ya se puede
     * retirar (§5.3, RF-QR-07): mientras devuelva mas de cero, hay tarjetas por
     * reimprimir.
     */
    public function countActiveSignedWith(string $keyId): int;

    /**
     * Cuantas credenciales llego a firmar esa clave, activas o no.
     *
     * Es un dato del asiento de retirada (`signing_key.retired`): dice el tamaño
     * del lote de tarjetas que muere con la clave, para que dentro de dos años
     * la pregunta «por que dejo de funcionar este QR» se conteste sin recorrer
     * la tabla entera.
     */
    public function countSignedWith(string $keyId): int;

    /**
     * Las credenciales **activas y escaneables** firmadas con esa clave.
     *
     * Es la seleccion de `credentials:rotate-key`: por cada una de ellas hay que
     * emitir una tarjeta nueva que la releve (RF-QR-07). Solo salen las
     * impresas, porque una pendiente no esta firmada con ninguna clave todavia.
     *
     * @return list<Credential> Ordenadas por empleado, para que dos ejecuciones den el mismo orden.
     */
    public function activeSignedWith(string $keyId): array;

    /**
     * Cuantas credenciales activas hay por cada `key_id`.
     *
     * Sirve para dos cosas y las dos importan: la **comprobacion previa** de la
     * rotacion —si quedan tarjetas firmadas con una clave que ya no esta en el
     * llavero, esas personas no pueden fichar y hay una rotacion anterior sin
     * cerrar— y la metrica de avance de la reimpresion (§8.2).
     *
     * @return array<string, int> Indexado por `key_id`. Las pendientes de imprimir no cuentan.
     */
    public function activeCountsByKeyId(): array;

    /**
     * Las demas credenciales **escaneables** del mismo empleado.
     *
     * Existe para el relevo de la rotacion: al **entregar** la tarjeta nueva se
     * revoca la que esa persona tenia firmada con la clave saliente, que es lo
     * que hace que el recuento de la clave anterior baje hasta cero y se pueda
     * retirar (§5.3). Se excluye la propia credencial por UUID y no por
     * `key_id`, para que quien llama decida el criterio.
     *
     * @return list<Credential>
     */
    public function otherActivePrintedForEmployee(int $employeeId, string $exceptUuid): array;
}
