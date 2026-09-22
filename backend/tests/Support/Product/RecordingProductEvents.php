<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Shared\Domain\Event\DomainEvent;

/**
 * Apunta lo que publica un caso de uso de `Product`, sin bus y sin base de datos.
 *
 * El puerto existe, entre otras cosas, «para que una prueba pueda comprobar que
 * se publico lo que se tenia que publicar sin arrancar nada»
 * ({@see ProductEventPublisher}): esta es esa prueba. La suite `Unit` corre
 * sobre PHPUnit puro, sin `app()` ni PostgreSQL.
 */
final class RecordingProductEvents implements ProductEventPublisher
{
    /** @var list<DomainEvent> */
    public array $published = [];

    public function publish(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->published[] = $event;
        }
    }
}
