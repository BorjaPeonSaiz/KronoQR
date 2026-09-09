<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Que paso al escribir una ocurrencia en el historico (RF-PD-15, decision 14).
 *
 * ## Por que no basta con `true`/`false`
 *
 * Porque hay **dos series de metricas y miden cosas distintas**, y la diferencia
 * la decide justo esta escritura:
 *
 * - `application_errors_total{source,level}` cuenta **ocurrencias**: sube
 *   siempre que algo se guarda (doc 02 §8.2). Responde a «¿cuanto esta pasando?».
 * - `application_error_groups_opened_total{source,level}` cuenta **grupos
 *   nuevos o reabiertos**: sube solo con {@see self::Opened}. Es la serie de la
 *   alerta `ErroresCriticosNuevos`.
 *
 * El motivo de que la alerta use la segunda es concreto: una camara de quiosco
 * rota emite el mismo error cada pocos segundos durante dias. Con la serie de
 * ocurrencias, `increase(...[5m]) > 0` mantendria la alerta encendida
 * indefinidamente por un problema que el IT del cliente **ya conoce y ya ha
 * dado por atendido**, y una alerta que no se apaga deja de leerse. Con la de
 * grupos, salta cuando aparece algo nuevo —o cuando algo dado por arreglado
 * vuelve—, que es exactamente cuando hay que mirar.
 *
 * ## Como se decide, y por que en la base de datos
 *
 * El `INSERT … ON CONFLICT` devuelve `xmax = 0` cuando la fila se ha insertado,
 * y la reapertura se detecta comparando el `resolved_at` de antes con el de
 * despues dentro de la misma sentencia. Decidirlo en PHP con un `SELECT` previo
 * seria una condicion de carrera: dos procesos verian «no existe» y los dos
 * contarian una apertura.
 */
enum ErrorWriteOutcome
{
    /** No se pudo escribir. Quien llama lo registra en el log y sigue (regla dura 19). */
    case Failed;

    /** El grupo ya existia y estaba abierto: solo ha subido `occurrences`. */
    case Recurred;

    /** El grupo se ha creado, o estaba resuelto y ha vuelto a ocurrir. */
    case Opened;

    /** Si quedo persistido, sea nuevo o repetido. */
    public function persisted(): bool
    {
        return $this !== self::Failed;
    }
}
