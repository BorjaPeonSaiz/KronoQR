<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Policy;

use Illuminate\Database\Eloquent\Model;

/**
 * Quien puede registrar un escaneo (regla dura 18, RS-04).
 *
 * **Solo un quiosco emparejado.** No es una policy de rol como las de
 * `Workforce`: aqui no hay una persona autenticada en un panel, hay una tablet
 * colgada en una pared con un token de dispositivo (RF-ID-04). Por eso la
 * pregunta que responde es distinta —«¿es esto un quiosco?»— y por eso no
 * consume `ManagementActor`, que describe a alguien de gestion.
 *
 * **Las dos comprobaciones, no una** (doc 02 §7.3). El ambito `scan:write` lo
 * verifica el middleware `ability` de Sanctum antes de llegar aqui; esta policy
 * verifica **quien** porta el token. Con las dos, una sesion del panel con
 * ambitos amplios no puede fabricar fichajes, y un token de quiosco al que
 * alguien le anadiera ambitos de gestion seguiria sin alcanzar la plantilla.
 *
 * **Se decide por la tabla y no por la clase del modelo.** `Attendance` no puede
 * importar el modelo `Device` de `Identity` (doc 02 §1.6), y tampoco le
 * conviene: la clase puede cambiar de nombre y la tabla no, porque
 * `scan_events.device_id` es una clave foranea hacia ella. Esto vale sin cambios
 * para los tokens que emite la tarea 1.5.
 *
 * **Deniega tambien al token de gestion mas poderoso.** Un administrador no
 * ficha por nadie desde este endpoint: para eso existe la correccion manual
 * trazada de RF-PA-04, que deja autor y motivo (RN-13). Un `admin` que pudiera
 * llamar a `/scan` produciria fichajes indistinguibles de los reales.
 *
 * **Se invoca directamente y no por el `Gate`, y hay un motivo tecnico
 * concreto.** El paquete de permisos registra un `Gate::before` cuya firma
 * exige `Illuminate\Contracts\Auth\Access\Authorizable`, porque da por hecho que
 * quien autoriza es una cuenta con roles. El `tokenable` de un quiosco es su
 * fila de `devices`, que no lo es —ni debe serlo: meter los quioscos en el
 * pipeline de roles significaria darles permisos, y un quiosco no tiene
 * permisos, tiene un ambito—. Pasar por el `Gate` global reventaria con un
 * `TypeError` antes de llegar aqui. La policy sigue siendo obligatoria y sigue
 * teniendo su prueba de autorizacion negativa por rol (regla dura 18); lo unico
 * que cambia es que se llama por su nombre.
 */
final class ScanPolicy
{
    public const string DEVICES_TABLE = 'devices';

    /**
     * @param  mixed  $actor  El `tokenable` del token de Sanctum. Se tipa laxo a
     *                        proposito: el Gate entrega lo que haya autenticado, y
     *                        lo que este endpoint acepta es una sola cosa.
     */
    public function record(mixed $actor): bool
    {
        return $actor instanceof Model && $actor->getTable() === self::DEVICES_TABLE;
    }

    /**
     * `POST /api/v1/scan/batch` — sincronizar la cola offline (tarea 1.7).
     *
     * Misma respuesta que {@see record()} y **metodo propio de todos modos**: la
     * regla dura 18 pide una policy por endpoint, y un endpoint que reutiliza el
     * metodo de otro es indistinguible de uno al que se le olvido la suya. El dia
     * que sincronizar exija algo que fichar no exige —que el dispositivo no este
     * en cuarentena, por ejemplo— este metodo ya existe y hay una prueba negativa
     * apuntandole.
     *
     * @param  mixed  $actor  El `tokenable` del token de Sanctum.
     */
    public function sync(mixed $actor): bool
    {
        return $this->record($actor);
    }
}
