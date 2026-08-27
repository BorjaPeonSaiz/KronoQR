<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede emitir y revocar credenciales (Anexo B del doc 01, regla dura 18).
 *
 * **`rrhh+`, que en la Fase 1 resuelve a `{admin, rrhh}`.** Ni el auditor —que
 * es de solo lectura— ni el responsable de departamento, que no tiene ambito
 * propio hasta la tarea 2.1 (RF-ID-03): darselo hoy seria darle la potestad de
 * emitir tarjetas para toda la plantilla, que es justo lo que ese requisito
 * viene a acotar.
 *
 * **La policy es la mitad de la autorizacion.** La otra es el ambito
 * `credentials:*` del token, que comprueba el middleware `ability` antes de
 * llegar aqui (doc 02 §7.3). Un token de quiosco no alcanza estas rutas aunque
 * su portador tuviera rol: lleva `scan:write`, `roster:read` y
 * `heartbeat:write`, y ninguno de los tres abre esta puerta. Es la prueba de
 * RS-04.
 *
 * **Cada acto tiene su propia habilidad, aunque hoy las cinco resuelvan al mismo
 * conjunto.** Emitir una tarjeta, **acuñar su QR**, firmar que se entrego, dejar
 * a alguien sin poder fichar y leer la lista nominal de quien no tiene tarjeta
 * son cinco responsabilidades distintas. El dia que se repartan —un recepcionista
 * que solo registra entregas, un auditor que solo lee el panel—, una sola lista
 * obligaria a separarlas justo cuando se cometen los errores. Escribirlas
 * separadas hoy cuesta cuatro metodos; separarlas despues cuesta revisar cada
 * llamada.
 *
 * **`print` es la mas seria de las cinco y conviene decirlo.** Es el acto que
 * acuña el QR (ADR-034): quien pueda invocarlo recibe por respuesta un documento
 * con el que se puede fichar en nombre de otra persona. No es «generar un PDF».
 */
final class CredentialPolicy
{
    /**
     * `rrhh+` del Anexo B, que en la Fase 1 resuelve a `{admin, rrhh}`.
     *
     * Se escribe **una vez** y la usan las cinco habilidades: si cada metodo
     * tuviera su propia lista literal, el dia que un rol nuevo entre en el
     * catalogo habria cinco sitios donde acordarse, y el que se olvidara seria el
     * que nadie prueba.
     *
     * @return list<UserRole>
     */
    private static function credentialManagers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    public function create(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::credentialManagers());
    }

    public function revoke(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::credentialManagers());
    }

    /**
     * Acuñar el QR y recibir el PDF de la tarjeta (RF-QR-04).
     *
     * Cubre tanto la impresion individual como el lote: son el mismo acto sobre
     * distinta seleccion, y quien puede una puede la otra.
     */
    public function print(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::credentialManagers());
    }

    /**
     * Firmar que la tarjeta llego a manos de su titular (RF-QR-06).
     */
    public function deliver(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::credentialManagers());
    }

    /**
     * Leer el panel de estado (RF-QR-08).
     *
     * **Es de solo lectura y aun asi no lo abre el auditor.** El panel es una
     * lista nominal de la plantilla con su centro y su departamento: quien la lee
     * reconstruye el organigrama del hotel. El auditor tiene `attendance:read`,
     * `audit:read` y `reports:legal`, y la plantilla no esta ahi.
     */
    public function viewStatus(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::credentialManagers());
    }
}
