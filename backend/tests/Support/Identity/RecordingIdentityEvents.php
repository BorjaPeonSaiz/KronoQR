<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Domain\Event\ManagementAccountDeactivated;
use App\Modules\Identity\Domain\Event\ManagementPasswordReset;
use App\Modules\Shared\Domain\Event\DomainEvent;
use RuntimeException;
use Tests\Support\Domain\RecordedEvents;

/**
 * Recoge lo que `Identity` publica, que es por donde pasa la auditoria (regla
 * dura 6).
 *
 * El modulo no puede importar nada de `Compliance`: una baja o una sustitucion
 * de contrasena **publican su hecho** y un listener de `Compliance` lo sella en
 * `audit_log`. En una prueba unitaria eso significa que el evento publicado
 * **es** el asiento: si no se publica, no hay traza, y una baja sin traza es
 * justo el hecho que alguien querria que no constara.
 *
 * Los dos accesores tipados evitan que una prueba escriba `$published[0]` o un
 * `instanceof` para estrechar el tipo, por lo mismo que
 * {@see RecordedEvents}: el indice acopla la prueba al
 * orden de publicacion y el `instanceof` mete una rama donde no debe haberlas.
 */
final class RecordingIdentityEvents implements IdentityEventPublisher
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
        }
    }

    public function deactivation(): ManagementAccountDeactivated
    {
        return $this->only(ManagementAccountDeactivated::class);
    }

    public function passwordReset(): ManagementPasswordReset
    {
        return $this->only(ManagementPasswordReset::class);
    }

    /**
     * El unico evento de ese tipo publicado, o un fallo en voz alta.
     *
     * Exige que haya **exactamente uno**: dos asientos para un mismo hecho
     * serian dos entradas en la cadena de ADR-010 contando lo mismo, y una
     * prueba que se quedara con el primero no lo veria.
     *
     * @template T of DomainEvent
     *
     * @param  class-string<T>  $class
     * @return T
     */
    private function only(string $class): DomainEvent
    {
        $found = array_values(array_filter(
            $this->published,
            static fn (DomainEvent $event): bool => $event instanceof $class,
        ));

        return \count($found) === 1
            ? $found[0]
            : throw new RuntimeException('Se esperaba exactamente un '.$class.' publicado, y hay '.\count($found).'.');
    }
}
