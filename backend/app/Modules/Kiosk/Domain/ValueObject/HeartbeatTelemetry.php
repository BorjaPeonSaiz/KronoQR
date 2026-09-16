<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Lo que un quiosco declara de si mismo en cada latido
 * (`POST /api/v1/kiosk/heartbeat`, **RF-PA-07**).
 *
 * ## Por que un objeto y no cinco escalares en la firma del puerto
 *
 * Porque ya iban por tres y la tarea 3.3 anadia dos mas. Una firma de cinco
 * parametros posicionales del mismo tipo primitivo —dos enteros, un booleano,
 * dos instantes— es un sitio donde tarde o temprano alguien cruza la cola con la
 * bateria sin que el tipado lo note. Aqui el rango lo comprueba el constructor y
 * el nombre lo dice la propiedad.
 *
 * ## Es informacion operativa, **no autoridad**
 *
 * Ni un campo influye en el registro horario. Todo lo de aqui lo declara el
 * propio dispositivo y nadie lo concilia con nada: un quiosco que mienta sobre
 * su cola ensucia el panel de salud y no cambia ni un fichaje. Por eso se
 * escribe tal cual llega.
 *
 * ## `null` significa «no lo se», y no es lo mismo que cero
 *
 * `batteryLevel` y `batteryCharging` son `null` cuando el navegador no informa:
 * la Battery Status API solo la ofrece Chrome en Android, que es la tablet del
 * producto, y **una tablet que no informa no es una tablet averiada**.
 * `oldestPendingAt` es `null` cuando la cola esta vacia, que es lo que distingue
 * «37 pendientes de hace un minuto» de «37 pendientes de hace tres horas».
 *
 * ## Ni un dato personal (regla dura 21)
 *
 * Una version, dos numeros, un booleano y un instante. Un quiosco es un aparato
 * en una pared y no tiene titular.
 */
final readonly class HeartbeatTelemetry
{
    public function __construct(
        /** Version de la PWA que corre en la tablet (RF-KI-07). */
        public string $appVersion,
        /** Fichajes en su cola local sin sincronizar. */
        public int $pendingQueueSize,
        /** `occurred_at` del mas antiguo de esa cola; `null` con la cola vacia. */
        public ?DateTimeImmutable $oldestPendingAt = null,
        /** Nivel de bateria en tanto por ciento; `null` si el navegador no lo sabe. */
        public ?int $batteryLevel = null,
        /** Si la tablet esta enchufada; `null` si el navegador no lo sabe. */
        public ?bool $batteryCharging = null,
    ) {
        if ($pendingQueueSize < 0) {
            throw new InvalidArgumentException('La cola pendiente de un quiosco no puede ser negativa.');
        }

        if ($batteryLevel !== null && ($batteryLevel < 0 || $batteryLevel > 100)) {
            // El borde ya lo rechaza con un `400` y la columna lleva su `CHECK`.
            // Esto es la tercera linea y la unica que protege a quien construya
            // el objeto desde una prueba o desde un comando de consola.
            throw new InvalidArgumentException('El nivel de bateria va entre 0 y 100.');
        }
    }
}
