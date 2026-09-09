<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Database\Eloquent\Model;

/**
 * Quien lee el historico de errores, quien lo resuelve y quien puede reportar
 * (RF-PD-15, regla dura 18, decision 8 de la ficha 5.12).
 *
 * ## `view`: `admin`, **y tambien un acceso de soporte**
 *
 * El Anexo B marca la ruta como `[rol: admin]` y el §7.3 concede `diagnostics:*`
 * al administrador de instalacion. El middleware `ability` comprueba el ambito y
 * esta policy el rol: dos controles distintos que aqui coinciden.
 *
 * **Un token de soporte SI lee** (RF-PD-11). El alcance `diagnostics` de la 5.9
 * se concede, con todas las letras, «para el paquete anonimizado **y los
 * errores**»: negarle esta pantalla dejaria la concesion sin la mitad de su
 * utilidad y obligaria a que el cliente reenviara un paquete por cada pregunta.
 * Aqui no hay datos personales que proteger (regla dura 21), asi que la unica
 * razon para cerrarla seria simetria con otras policies, y eso no es una razon.
 *
 * **`rrhh` no entra**, aunque suela ser quien detecta el sintoma: lo que hay
 * detras es el estado tecnico del servidor y las decisiones que se toman con el
 * son de quien lo administra. **El `auditor` tampoco**: su ambito es el registro
 * horario y estos errores no forman parte de el. **El quiosco y el portal** se
 * quedan en el middleware, porque sus tokens no llevan `diagnostics:*`.
 *
 * ## `resolve`: `admin` **del cliente**, y ahi el soporte NO entra
 *
 * Es la unica asimetria de esta policy y es deliberada: **dar un fallo por
 * resuelto en la instalacion de un cliente es una decision del cliente**
 * (ADR-020). Un acceso de soporte con alcance `diagnostics` actua como `admin`
 * ante las policies —tiene que hacerlo para poder leer—, asi que sin la segunda
 * comprobacion seria indistinguible del administrador del hotel justo en la
 * accion que dice «esto ya no hay que mirarlo». Que el fabricante pudiera vaciar
 * la bandeja de errores del cliente antes de devolverle el control es
 * exactamente lo que la regla dura 16 impide.
 *
 * ## `report`: cualquiera que tenga una sesion de persona, **y ningun quiosco**
 *
 * `POST /api/v1/client-errors` lo usan el panel y el portal para vaciar su
 * buffer de errores de navegador. Vale **cualquier rol de gestion** —quien sufre
 * el error es quien lo reporta, y un `empleado` con el portal abierto sufre
 * errores igual que un `admin`— y vale una **sesion de portal**.
 *
 * **Un token de dispositivo recibe `403`**, y no por desconfianza: el quiosco
 * **tiene su canal**, dentro del latido (decision 7), y abrirle un segundo
 * competiria con la cola de fichajes por la misma red que ya le falla — que es
 * justo cuando mas errores tiene que reportar (regla dura 19). Dos caminos para
 * lo mismo ademas harian que `source: kiosk` pudiera llegar por una ruta en la
 * que el servidor no sabe de que tablet viene.
 *
 * **No hay ruta anonima.** Los errores anteriores al inicio de sesion esperan en
 * el buffer del navegador y salen con la primera sesion: una superficie publica
 * que escribe en base de datos seria un vector de denegacion de servicio.
 *
 * ## Por que `view` y `resolve` tipan `ManagementActor` y `report` no
 *
 * Las dos primeras son rutas de gestion y pasan por el `Gate`, que entrega una
 * cuenta de panel. La tercera acepta ademas una **sesion de portal**, cuyo
 * `tokenable` es una fila de `employees` y **no es `Authorizable`** —ni debe
 * serlo: meter a la plantilla en el sistema de roles significaria darle
 * permisos, y un empleado no tiene permisos, tiene un ambito—. Pasar eso por el
 * `Gate` global reventaria con un `TypeError` en el `Gate::before` del paquete
 * de permisos, asi que `report()` se invoca **por su nombre** desde el
 * `FormRequest`. Es la misma solucion, y por el mismo motivo, que
 * `Reporting\Http\Policy\SelfJournalPolicy`.
 *
 * ## Por que se pregunta al actor y no se comprueba su clase
 *
 * `Product` no puede importar nada de `Identity` ni de `Workforce` (doc 02 §1.6,
 * verificado por Deptrac), asi que un `instanceof Employee` aqui seria una
 * violacion de frontera. Se identifica **por la tabla**, que ademas es lo
 * estable: una clase puede cambiar de nombre y `employees` no. Mismo criterio
 * que `ScanPolicy` con el quiosco.
 */
final class ErrorEventPolicy
{
    /**
     * La tabla del `tokenable` de una sesion de portal. Ver el docblock: se
     * nombra la tabla y no la clase porque la frontera del §1.6 prohibe
     * importarla.
     */
    public const string EMPLOYEES_TABLE = 'employees';

    /**
     * La del `tokenable` de un quiosco. Esta aqui para poder **rechazarlo con
     * nombre** en lugar de por descarte: que un token de dispositivo no pase es
     * una decision, y una decision merece una linea que se pueda leer y una
     * prueba que la apunte.
     */
    public const string DEVICES_TABLE = 'devices';

    /** `GET /api/v1/diagnostics/errors`. */
    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(UserRole::ADMIN);
    }

    /** `POST /api/v1/diagnostics/errors/{id}/resolve`. */
    public function resolve(ManagementActor $actor): bool
    {
        return $actor->actsAs(UserRole::ADMIN) && ! $actor->isSupportActor();
    }

    /**
     * `POST /api/v1/client-errors`.
     *
     * @param  mixed  $actor  El `tokenable` del token de Sanctum. Se tipa laxo a
     *                        proposito: el guard entrega lo que haya autenticado, y esta
     *                        ruta acepta dos cosas distintas y rechaza una tercera.
     */
    public function report(mixed $actor): bool
    {
        if ($actor instanceof ManagementActor) {
            return true;
        }

        return $actor instanceof Model && $actor->getTable() === self::EMPLOYEES_TABLE;
    }
}
