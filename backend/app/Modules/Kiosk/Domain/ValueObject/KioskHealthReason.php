<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

/**
 * **Por que** un quiosco tiene el veredicto que tiene (`kiosk:health`, RF-PA-07).
 *
 * ## Por que no basta con el veredicto
 *
 * Porque `aviso` no dice que hacer, y las dos causas de un aviso piden acciones
 * distintas: una cola pendiente se resuelve **esperando** a que la tablet drene
 * —y es justo lo que el runbook `alta-nuevo-quiosco.md` §5.1 manda hacer antes
 * de desvincular, porque los fichajes sin enviar se pierden al revocar el
 * token—, y un latido atrasado se resuelve **mirando la red**. Un comando que
 * solo dijera «aviso» dejaria esa distincion en manos de quien lo lee.
 *
 * La razon es tambien la clave de traduccion de la linea de consejo y el campo
 * `reason` del `--json`: quien automatiza la comprobacion no tiene que analizar
 * una frase en español para saber que paso.
 *
 * ## Se elige UNA, por prioridad, y esa es la decision
 *
 * Un quiosco puede estar callado **y** con cola a la vez. Se informa de lo que
 * hay que atender primero —el silencio, porque la cola de un quiosco que no
 * habla no la va a drenar nadie— en lugar de acumular razones: una lista de
 * causas en una celda de tabla no la lee quien tiene una tablet apagada delante.
 */
enum KioskHealthReason: string
{
    /** Late dentro de plazo y sin nada encolado. */
    case Beating = 'beating';

    /** Late, pero declara fichajes sin enviar. */
    case QueuePending = 'queue_pending';

    /** Su ultimo latido pasa del plazo fresco y no llega al de silencio. */
    case Late = 'late';

    /** Pasa del plazo de silencio: es la alerta «quiosco sin latido» del doc 01 §9.3. */
    case Silent = 'silent';

    /**
     * Recien vinculado y todavia sin primer latido, dentro de su plazo de gracia.
     *
     * Es el estado normal durante los segundos que van del `confirm` al primer
     * latido, que es exactamente cuando el runbook §4.2 manda ejecutar este
     * comando. Marcarlo como fallo enseñaria a ignorar el resultado.
     */
    case AwaitingFirstHeartbeat = 'awaiting_first_heartbeat';

    /** Vinculado hace rato y sin haber latido nunca: la tablet no llego a arrancar. */
    case NeverSeen = 'never_seen';

    /** Desvinculado. No late porque no debe latir. */
    case Revoked = 'revoked';

    /**
     * Bateria por debajo del umbral **y sin cargar**.
     *
     * Una tablet colgada de la pared que se descarga es una tablet a la que
     * alguien ha quitado el cargador, y morira durante el turno: se sabe antes
     * de que la pantalla se apague, no despues. Con `battery_charging` a `true`
     * no hay nada que decir, y con cualquiera de los dos a `null` tampoco: la
     * Battery Status API solo la ofrece Chrome en Android y una tablet que no
     * informa no es una tablet averiada (decision 5 de la ficha 3.3).
     */
    case BatteryLow = 'battery_low';

    /**
     * La cola de la tablet salio de IndexedDB —memoria o nada— (ADR-047).
     *
     * **Fallo y no aviso**: lo que se encola ahora se pierde si la tablet se
     * reinicia, y lo que habia en el disco no se ve. Por eso va detras del
     * silencio —un quiosco que no habla no dice donde guarda nada— y delante
     * del retraso.
     */
    case QueueStorageDegraded = 'queue_storage_degraded';

    /**
     * Hay fichajes descartados cuyo aviso aun no llego al servidor (RN-22,
     * ADR-047): nadie los revisara hasta que el aviso salga.
     */
    case DiscardsUnreported = 'discards_unreported';

    /**
     * Late al dia, pero su aplicacion es anterior a la del servidor o no dice
     * una version legible (`AppVersionPolicy`, RF-KI-07; 2.2.1, bloque 1).
     *
     * **Aviso, y el ultimo de los avisos**: la tablet sigue fichando con el
     * build viejo, y se pone al dia sola en cuanto su cola esta vacia y nadie
     * ficha. Por eso va detras de la cola pendiente: lo que hay que mirar
     * antes es lo que impide que se actualice. Con un servidor sin version
     * comparable (`0.0.0`, `-dev`) no aparece nunca.
     */
    case AppVersionBehind = 'app_version_behind';
}
