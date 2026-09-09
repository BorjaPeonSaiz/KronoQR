<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\ErrorEventRepository;
use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use App\Modules\Shared\Application\Port\Clock;

/**
 * `POST /api/v1/diagnostics/errors/{id}/resolve` — dar un grupo por atendido
 * (RF-PD-15).
 *
 * ## No escribe en `audit_log`, y es deliberado (regla dura 6, en sentido
 * inverso)
 *
 * `audit_log` guarda las acciones con **relevancia legal**: correcciones de
 * jornada, exportaciones, accesos de soporte, cambios de umbral. Marcar como
 * atendido un error tecnico no lo es. Meterlo alli mezclaria ruido de
 * mantenimiento con la evidencia que se conserva cuatro anos y que un inspector
 * puede leer, y ademas obligaria a pasar por el candado global de ADR-010 —el
 * mismo por el que pasa cada fichaje— para pulsar un boton de una pantalla de
 * diagnostico.
 *
 * Quien y cuando quedan en la propia fila (`resolved_at`,
 * `resolved_by_user_id`), que es donde los mira quien los necesita — **pero son
 * el ESTADO actual, no un historial**: si el grupo vuelve a ocurrir, la
 * escritura los vacia y con ellos se va el rastro de esta resolucion. Es
 * deliberado y el contrato lo declara asi (un grupo abierto lleva `resolved_by`
 * nulo): lo que importa de un fallo que reaparece es que esta abierto otra vez,
 * no quien creyo haberlo arreglado.
 *
 * ## Idempotente
 *
 * Resolver un grupo ya resuelto devuelve la misma fila, con su autor y su
 * instante **originales**: la accion no tiene segundo efecto. Dos pestanas
 * abiertas, un reintento tras un corte de red o una segunda pulsacion impaciente
 * no pueden reescribir quien lo resolvio ni cuando.
 *
 * ## Un grupo resuelto que vuelve a ocurrir se reabre
 *
 * Y eso no lo hace este caso de uso: lo hace la escritura, al recurrir el error
 * ({@see RecordErrorEvent}). Es lo que permite que «resuelto» signifique «ya no
 * pasa» y no «ya no se ve».
 *
 * ## Nulo y no excepcion cuando el identificador no existe
 *
 * Para que el controlador responda `404` sin que aqui haya que conocer codigos
 * HTTP. No hay excepcion de dominio porque no hay ninguna regla de negocio que
 * violar: es una fila que no esta.
 */
final readonly class ResolveErrorEvent
{
    public function __construct(
        private ErrorEventRepository $errors,
        private Clock $clock,
    ) {}

    /**
     * @param  int  $id  Identificador del grupo.
     * @param  int  $userId  Clave interna de la cuenta que lo resuelve.
     * @return ErrorEvent|null Nulo si no existe.
     */
    public function handle(int $id, int $userId): ?ErrorEvent
    {
        return $this->errors->resolve($id, $userId, $this->clock->now());
    }
}
