<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use SensitiveParameter;

/**
 * Baja de una cuenta de gestion y sustitucion de su contrasena (RS-05, RS-06,
 * RL-16; hallazgo H-03 de la revision interna ASVS de 2026-09).
 *
 * **Puerto propio, y no dos metodos mas en {@see UserAccounts} ni en
 * {@see ManagementAccountRegistry}.** El primero es «las cuentas vistas por
 * quien autentica» y no tiene ninguna escritura: darle un `deactivate()`
 * significaria que el caso de uso que comprueba una contrasena tambien puede
 * cerrarle la puerta a alguien. El segundo es el alta, y quien da de alta no
 * tiene por que poder dar de baja — es la misma separacion que aquel puerto
 * explica en su propio docblock.
 *
 * **Todo se identifica por el `uuid` publico**, nunca por la clave interna ni
 * por el correo: es el unico identificador que puede viajar a un evento de
 * dominio y de ahi a `audit_log` (regla dura 21). El correo solo aparece en la
 * busqueda, que es donde el operador de consola lo escribe.
 */
interface ManagementAccountLifecycle
{
    /**
     * El `uuid` publico de la cuenta **activa** con ese correo, o `null`.
     *
     * Devuelve `null` tambien cuando la cuenta existe pero ya esta desactivada:
     * quien llama distingue los dos casos con {@see self::accountExists()}. Se
     * separan porque el comando tiene que decir cosas distintas —«no existe» y
     * «ya estaba de baja»— y aqui no hay ningun riesgo de enumeracion que
     * proteger: esto no se alcanza por HTTP, solo desde el servidor del cliente.
     */
    public function uuidOfActiveAccount(string $email): ?string;

    /**
     * ¿Hay **alguna** cuenta con ese correo, activa o no?
     */
    public function accountExists(string $email): bool;

    /**
     * Pone `users.is_active` a `false`.
     *
     * No borra nada (regla dura 5): la cuenta sigue existiendo, con su historial
     * y con sus asientos, y sigue contando para la guarda del alta publica del
     * primer administrador. Lo unico que pierde es la capacidad de entrar.
     */
    public function deactivate(string $uuid): void;

    /**
     * Sustituye la contrasena.
     *
     * Llega **en claro** y se hashea en el adaptador, que es quien conoce el
     * algoritmo y el coste configurados; nunca se almacena tal cual y nunca
     * vuelve a salir.
     */
    public function replacePassword(string $uuid, #[SensitiveParameter] string $password): void;
}
