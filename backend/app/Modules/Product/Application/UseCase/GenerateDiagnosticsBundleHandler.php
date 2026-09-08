<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Domain\Event\DiagnosticsBundleGenerated;
use App\Modules\Product\Domain\Event\PersonalDataIncludedInDiagnostics;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Shared\Application\Port\Clock;
use Throwable;

/**
 * Genera el paquete de diagnostico (**RF-PD-09**, RL-19, ADR-020).
 *
 * ## Recorre recolectores; no sabe que hay dentro de ninguno
 *
 * Cada seccion la construye su recolector con su propia lista de permitidos.
 * Este caso de uso solo decide **cuales** se piden —`personal_data` unicamente
 * si se pidio explicitamente—, sella el conjunto y publica los asientos.
 *
 * ## Un recolector que falla no tumba el paquete
 *
 * Es lo mismo que hace `doctor` con sus sondas y por el mismo motivo: el
 * paquete se genera cuando algo va mal. Si Redis esta caido, la seccion de
 * servicios no puede completarse, y un paquete sin la seccion de colas sigue
 * valiendo para diagnosticar el resto. La seccion queda como
 * `{"status": "unavailable"}` con la clase de la excepcion —nunca su mensaje,
 * que puede llevar una cadena de conexion.
 *
 * ## Dos asientos, no uno con un booleano (RL-19)
 *
 * `diagnostics.bundle_generated` siempre; `diagnostics.personal_data_included`
 * **ademas**, cuando el paquete lleva datos de la plantilla. Son dos hechos que
 * ocurrieron a la vez, no dos versiones del mismo, y separarlos es lo que
 * permite responder «¿cuando han salido de aqui datos de mi gente?» con un
 * `WHERE action =` en lugar de con una auditoria.
 *
 * **Los asientos se publican dentro de la transaccion de quien llama** y son
 * sincronos: si el asiento no se puede escribir, el paquete no se entrega. Sin
 * esa garantia, la promesa de ADR-020 —«el cliente sabe que sale de aqui»— seria
 * una intencion.
 *
 * ## Funciona con la licencia caducada o ausente (regla dura 15)
 *
 * No consulta la licencia para decidir nada. La seccion `license` **informa** de
 * su estado, que es la primera pregunta de cualquier incidencia.
 */
final readonly class GenerateDiagnosticsBundleHandler
{
    /**
     * @param  list<DiagnosticsCollector>  $collectors  En el orden en que van al documento.
     */
    public function __construct(
        private array $collectors,
        private ProductEventPublisher $events,
        private Clock $clock,
        private string $productVersion,
        private int $maxBytes,
    ) {}

    public function handle(DiagnosticsOptions $options, DiagnosticsActor $actor): DiagnosticsBundle
    {
        $sections = [];

        foreach ($this->collectors as $collector) {
            $name = $collector->section();

            if ($name === 'personal_data' && ! $options->includePersonalData) {
                continue;
            }

            $sections[$name] = $this->collect($collector, $options);
        }

        $bundle = DiagnosticsBundle::of(
            productVersion: $this->productVersion,
            generatedAt: $this->clock->now(),
            anonymized: ! $options->includePersonalData,
            generatedBy: $actor,
            sections: $sections,
            maxBytes: $this->maxBytes,
        );

        $this->audit($bundle, $options, $actor);

        return $bundle;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function collect(DiagnosticsCollector $collector, DiagnosticsOptions $options): array
    {
        try {
            return $collector->collect($options);
        } catch (Throwable $failure) {
            return ['status' => 'unavailable', 'reason' => $failure::class];
        }
    }

    private function audit(DiagnosticsBundle $bundle, DiagnosticsOptions $options, DiagnosticsActor $actor): void
    {
        $now = $this->clock->now();

        $events = [new DiagnosticsBundleGenerated(
            anonymized: $bundle->manifest->anonymized,
            sections: $bundle->manifest->sections,
            sha256: $bundle->manifest->sha256,
            sizeBytes: \strlen($bundle->toJson()),
            generatedBy: $actor->value,
            occurredAt: $now,
        )];

        if ($options->includePersonalData) {
            /** @var array<array-key, mixed> $personal */
            $personal = $bundle->sections['personal_data'] ?? [];

            $events[] = new PersonalDataIncludedInDiagnostics(
                periodDays: $options->periodDays,
                collections: array_values(array_filter(
                    array_map(strval(...), array_keys($personal)),
                    static fn (string $key): bool => ! in_array($key, ['status', 'reason', 'bytes'], true),
                )),
                sha256: $bundle->manifest->sha256,
                generatedBy: $actor->value,
                occurredAt: $now,
            );
        }

        $this->events->publish(...$events);
    }
}
