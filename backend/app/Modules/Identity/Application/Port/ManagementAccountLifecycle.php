<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use DateTimeImmutable;

/**
 * Baja de una cuenta de gestion, sustitucion de su contrasena y lo que hace
 * falta para decidirlas bajo candado (RS-05, RS-06, RL-16, **RF-ID-10**).
 *
 * **Puerto propio, y no metodos mas en {@see UserAccounts} ni en
 * {@see ManagementAccountRegistry}.** El primero es «las cuentas vistas por
 * quien autentica» y no tiene ninguna escritura: darle un `deactivate()`
 * significaria que el caso de uso que comprueba una contrasena tambien puede
 * cerrarle la puerta a alguien. El segundo es el alta, y quien da de alta no
 * tiene por que poder dar de baja.
 *
 * **Todo se identifica por el `uuid` publico**, nunca por la clave interna ni
 * por el correo: es el unico identificador que puede viajar a un evento de
 * dominio y de ahi a `audit_log` (regla dura 21). Desde la 2.2.0 esto **se
 * alcanza por HTTP** (`/management-accounts/{uuid}/*`), y por eso la busqueda
 * por correo quedo reducida a un unico metodo que solo usa la consola.
 *
 * **Los metodos de escritura se llaman con la fila ya bloqueada** por
 * {@see self::lockAccount()}, dentro de la transaccion que abre el caso de uso
 * con el candado de la cadena de auditoria (orden unico: cadena → padron de
 * cuentas → fila de `users`; ADR-010).
 */
interface ManagementAccountLifecycle
{
    /**
     * El `uuid` publico de la cuenta con ese correo, **activa o no**, o `null`.
     *
     * Solo la consola: el operador escribe el correo, que es lo que tiene en su
     * lista de personal, y el caso de uso trabaja con el `uuid`. Quien llama
     * distingue «no existe» de «ya de baja» por el desenlace del caso de uso,
     * no por esta busqueda. **La API no lo usa**: alli la cuenta se nombra por
     * `uuid` y el correo no viaja en ninguna URL.
     */
    public function uuidOfAccount(string $email): ?string;

    /**
     * Bloquea la fila de la cuenta (`SELECT … FOR UPDATE`) y devuelve lo que
     * hace falta para decidir sobre ella, o `null` si no existe.
     *
     * Tiene que llamarse dentro de una transaccion: fuera, el candado se suelta
     * al terminar la sentencia y no protege nada.
     */
    public function lockAccount(string $uuid): ?AccountSnapshot;

    /**
     * Cuantas cuentas `admin` **activas** hay.
     *
     * Solo tiene sentido bajo el candado del padron de cuentas: sin el, dos bajas
     * simultaneas leen las dos el mismo numero.
     */
    public function countActiveAdmins(): int;

    /**
     * Pone `users.is_active` a `false`.
     *
     * No borra nada (regla dura 5): la cuenta sigue existiendo, con su historial
     * y con sus asientos, y sigue contando para la guarda del alta publica del
     * primer administrador. Lo unico que pierde es la capacidad de entrar.
     */
    public function deactivate(string $uuid): void;

    /**
     * El hash de la contrasena vigente de la cuenta **activa**, o `null`.
     *
     * Para el cambio de la contrasena propia, que compara la actual **fuera** del
     * candado (el hash cuesta decenas de milisegundos) y vuelve a leerlo dentro
     * para comprobar que nadie la sustituyo entre medias.
     */
    public function currentPasswordHash(string $uuid): ?string;

    /**
     * Sustituye el hash de la contrasena y su caducidad de temporal.
     *
     * **Recibe el hash, no la contrasena**: se calcula fuera del candado con
     * {@see PasswordHasher}. `$temporaryExpiresAt` no nulo marca la contrasena
     * como temporal y caduca en ese instante; `null`, como propia.
     */
    public function replacePasswordHash(string $uuid, string $hash, ?DateTimeImmutable $temporaryExpiresAt): void;
}
