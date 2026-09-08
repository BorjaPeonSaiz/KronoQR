<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Command\GrantSupportAccessCommand;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Application\Port\SupportTokenIssuer;
use App\Modules\Product\Domain\Event\SupportAccessGranted;
use App\Modules\Product\Domain\Exception\InvalidSupportGrant;
use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Concede un acceso temporal de soporte: crea la concesion, emite su token y
 * audita (**RF-PD-11**, RL-18, ADR-020, regla dura 6).
 *
 * ## Todo dentro de una transaccion, y el asiento tambien
 *
 * El evento se publica **dentro** y el listener de `Compliance` es sincrono: si
 * el asiento falla, la concesion no se guarda y el token no llega a existir
 * (ADR-027, regla dura 6). Es la unica combinacion aceptable: un acceso del
 * fabricante sin rastro es exactamente lo que ADR-020 existe para impedir, y
 * entre «no se concede» y «se concede sin traza», lo primero es un reintento y lo
 * segundo es un agujero.
 *
 * ## El token se emite dentro de la misma transaccion
 *
 * Y no despues, por lo mismo: si se emitiera fuera y la transaccion se
 * revirtiera, quedaria un token de Sanctum vivo colgando de una fila que no
 * existe. Sanctum guarda su token en la misma base de datos, asi que entra en la
 * misma transaccion sin ningun esfuerzo.
 *
 * ## No consulta la licencia, ni puede
 *
 * Ni aqui ni en el revocador (regla dura 15, ADR-019). Conceder acceso de
 * soporte con la licencia caducada tiene que funcionar **porque es cuando mas
 * falta hace**: la incidencia que hay que resolver puede ser justamente que la
 * activacion de la renovacion no va.
 *
 * ## El tope de horas entra resuelto
 *
 * Como el aviso de caducidad de la licencia y como los umbrales legales (regla
 * dura 14): el dominio no consulta `config()`, y una prueba puede fijar un tope
 * de una hora sin tocar el `.env` de nadie.
 */
final readonly class GrantSupportAccessHandler
{
    public function __construct(
        private SupportGrantRepository $grants,
        private SupportTokenIssuer $tokens,
        private ProductEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        private int $maximumHours,
    ) {}

    /**
     * @throws InvalidSupportGrant si el motivo o la duracion no valen
     */
    public function handle(GrantSupportAccessCommand $command): IssuedSupportGrant
    {
        $now = $this->clock->now();

        // Quien autoriza se resuelve por el repositorio y no llega desde el
        // borde: el controlador tendria que tratar al actor autenticado como una
        // fila de Eloquent —de otro modulo— para sacarle el nombre que la
        // pantalla del cliente necesita.
        $author = $this->grants->authorOf($command->grantedByUserId)
            ?? throw new RuntimeException('La cuenta que autoriza el acceso de soporte no existe.');

        // Se construye ANTES de abrir la transaccion: si el motivo o la duracion
        // no valen, no hay nada que revertir.
        $grant = SupportGrant::grant(
            // UUID v7 como el resto de los identificadores publicos del producto:
            // ordenado en el tiempo, asi que el indice de la clave publica no se
            // fragmenta, y sin decir nada de cuantas concesiones hay.
            uuid: Str::uuid7()->toString(),
            grantedBy: $author,
            reason: $command->reason,
            scope: $command->scope,
            hours: $command->hours,
            maximumHours: $this->maximumHours,
            grantedAt: $now,
        );

        /** @var IssuedSupportGrant $issued */
        $issued = $this->connection->transaction(function () use ($grant, $command, $now): IssuedSupportGrant {
            $stored = $grant->withId($this->grants->store($grant));

            $token = $this->tokens->issueFor($stored);

            $this->events->publish(new SupportAccessGranted(
                grantId: $stored->id ?? 0,
                grantUuid: $stored->uuid,
                scope: $stored->scope->value,
                hours: $command->hours,
                expiresAt: $stored->expiresAt,
                reason: $stored->reason,
                grantedByUserId: $command->grantedByUserId,
                occurredAt: $now,
            ));

            return new IssuedSupportGrant($stored, $token);
        });

        return $issued;
    }
}
