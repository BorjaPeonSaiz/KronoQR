<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Policy;

use Illuminate\Database\Eloquent\Model;

/**
 * Quien puede entrar por `/api/v1/me/*` (RF-ID-07, regla dura 18).
 *
 * ## Una sola pregunta: «¿es esto una sesion de portal?»
 *
 * No es una policy de rol como `WorkDayJournalPolicy`, que es la hermana de
 * gestion de estos dos endpoints. Aqui no hay una cuenta de panel con roles: hay
 * una persona que entro con su codigo de empleado y su PIN (ADR-015), y el
 * `tokenable` de su token es su fila de `employees`. Por eso no consume
 * `ManagementActor`, que describe a alguien de gestion, y por eso ningun token
 * de panel pasa por aqui **aunque su portador sea administrador**.
 *
 * Que un `admin` reciba `403` en `/me/workdays` no es una laguna: su registro
 * horario, si lo tiene, lo consulta con su sesion de portal como todo el mundo,
 * y el de los demas por `GET /api/v1/employees/{uuid}/workdays`, que es la ruta
 * de gestion y queda auditada como divulgacion (RS-05). Mezclar las dos habria
 * significado que la ruta sin `uuid` sirviera datos distintos segun quien
 * preguntara, que es la clase de ambiguedad con la que se filtra el registro de
 * un tercero.
 *
 * ## La otra mitad no esta aqui, y sin ella esto no basta
 *
 * El ambito `self:read` lo verifica el middleware `ability` de Sanctum antes de
 * llegar (doc 02 §7.3). Con los dos, un token de gestion no entra —le falta el
 * ambito— y un token con el ambito pero colgado de otra cosa tampoco.
 *
 * **Y la tercera mitad es que estas rutas no tienen `uuid`.** El empleado sale
 * del token, asi que no hay nada que manipular en la URL: esta policy dice
 * *quien* entra, y la forma de la ruta garantiza *sobre que datos*.
 *
 * ## Se decide por la tabla y no por la clase del modelo
 *
 * `Reporting` no puede importar el modelo `Employee` de `Workforce` (doc 02
 * §1.6), y tampoco le conviene: la clase puede cambiar de nombre y la tabla no.
 * Es el mismo criterio que usa `Attendance\Http\Policy\ScanPolicy` con el
 * quiosco —nombrada en prosa y no con `@see`, porque una referencia resoluble
 * seria la dependencia entre modulos que la frontera prohibe—.
 *
 * ## Se invoca por su nombre y no por el `Gate`
 *
 * Por lo mismo que `ScanPolicy`: el paquete de permisos registra un
 * `Gate::before` cuya firma exige `Authorizable`, y el `tokenable` de una sesion
 * de portal no lo es —ni debe serlo: meter a la plantilla en el sistema de roles
 * significaria darle permisos, y un empleado no tiene permisos, tiene un ambito—.
 * Pasar por el `Gate` global reventaria con un `TypeError` antes de llegar aqui.
 * La policy sigue siendo obligatoria y sigue teniendo sus pruebas negativas.
 */
final class SelfJournalPolicy
{
    public const string EMPLOYEES_TABLE = 'employees';

    /**
     * `GET /api/v1/me/workdays`.
     *
     * @param  mixed  $actor  El `tokenable` del token de Sanctum. Se tipa laxo a
     *                        proposito: el guard entrega lo que haya autenticado, y
     *                        lo que esta ruta acepta es una sola cosa.
     */
    public function view(mixed $actor): bool
    {
        return $actor instanceof Model && $actor->getTable() === self::EMPLOYEES_TABLE;
    }

    /**
     * `GET /api/v1/me/export`.
     *
     * Misma respuesta que {@see view()} y **metodo propio de todos modos**: la
     * regla dura 18 pide una policy por endpoint, y un endpoint que reutiliza el
     * metodo de otro es indistinguible de uno al que se le olvido la suya.
     *
     * Aqui ademas hay una razon concreta para que exista desde el primer dia:
     * descargar el historico completo es un acto distinto de mirar un mes en
     * pantalla, y es plausible que un cliente quiera acotarlo —o que la tarea
     * 2.9, al añadir el PDF, quiera exigir algo mas—. El dia que eso ocurra,
     * este metodo ya existe y hay una prueba negativa apuntandole.
     *
     * @param  mixed  $actor  El `tokenable` del token de Sanctum.
     */
    public function export(mixed $actor): bool
    {
        return $this->view($actor);
    }
}
