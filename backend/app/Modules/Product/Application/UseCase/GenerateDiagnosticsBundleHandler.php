<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Exception\DiagnosticsResponsibleNotAllowed;
use App\Modules\Product\Application\Exception\DiagnosticsSealingUnavailable;
use App\Modules\Product\Application\Port\DiagnosticsBundleSealer;
use App\Modules\Product\Application\Port\DiagnosticsCollector;
use App\Modules\Product\Application\Port\ProbeFailureClassifier;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Support\DiagnosticsSigners;
use App\Modules\Product\Domain\Event\DiagnosticsBundleGenerated;
use App\Modules\Product\Domain\Event\PersonalDataIncludedInDiagnostics;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsBundle;
use App\Modules\Product\Domain\ValueObject\DiagnosticsManifest;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\PendingDiagnosticsAudit;
use App\Modules\Product\Domain\ValueObject\SealedDiagnosticsBundle;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ResponsibleAccounts;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use DateTimeImmutable;
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
 * **Los asientos son sincronos**: se escriben antes de devolver el paquete, y
 * si no se pueden escribir el paquete no se entrega. Sin esa garantia, la
 * promesa de ADR-020 —«el cliente sabe que sale de aqui»— seria una intencion.
 *
 * ## La unica excepcion: la base caida, el paquete anonimizado y la consola
 *
 * El paquete se necesita sobre todo cuando algo va mal, y lo que mas a menudo
 * va mal es la base de datos. Por eso, **y solo si se cumplen las tres
 * condiciones** —el asiento fallo porque la base no esta disponible (segun
 * {@see ProbeFailureClassifier}), el paquete es el anonimizado y lo pidio la
 * consola—, el asiento se **aplaza**, no se pierde (ADR-020, enmienda del
 * 14-10-2026):
 *
 * 1. se vuelve a sellar el paquete con `audit.generation_entry` pendiente,
 *    dentro de su huella;
 * 2. se devuelve el paquete, y lo entrega {@see DeliverDiagnosticsBundle}:
 *    temporal, pendiente guardado y renombrado, en ese orden. El escritor no
 *    publica un paquete pendiente sin el comprobante de su pendiente, asi que
 *    el orden no depende de quien llama.
 *
 * Solo la consola recibe un paquete pendiente: un paquete pedido por el panel
 * o con datos personales nunca se aplaza, asi que `handle()` lanza.
 *
 * El asiento lo escribe despues {@see RecordPendingDiagnosticsAudits}, en
 * cuanto la base vuelve. Cualquier otro fallo del asiento —con la base arriba,
 * con datos personales o desde el panel— sube tal cual: sin asiento no hay
 * paquete.
 *
 * ## Por consola, los datos personales los firma una cuenta
 *
 * El paquete con datos personales pedido por consola lleva la cuenta `admin`
 * que lo autoriza (`--responsible`), y sus dos asientos son de esa cuenta, no
 * de `system` (doc 01 §7.2, «Firmas por consola»). Sin ella no se
 * genera, y la cuenta se vuelve a comprobar aqui aunque la consola ya la haya
 * resuelto: es la comprobacion que vale si la cuenta se da de baja entre la
 * pregunta y la generacion. El manifiesto sigue diciendo `console` y nada mas
 * (ADR-020): la cuenta se queda en `audit_log`, que no sale de la instalacion.
 *
 * ## Con datos personales, cifrado o nada (ADR-020, enmienda del 01-11-2026)
 *
 * El paquete con `personal_data` sale como {@see SealedDiagnosticsBundle}, para
 * la clave publica de soporte del fabricante. **Sin clave valida no se genera**:
 * se comprueba antes de recolectar, asi que no se recoge nada ni queda asiento
 * ({@see DiagnosticsSealingUnavailable}). Se sella **antes** de escribir los
 * asientos, que llevan la huella de los bytes que salen (`sealed_sha256`). El
 * anonimizado no pasa nunca por el sellador.
 *
 * El tope `PRODUCT_DIAGNOSTICS_MAX_BYTES` vale para el fichero que sale. El
 * texto cifrado viaja en base64, asi que el recorte se hace contra unos 3/4 del
 * tope; y como el recorte mide la forma canonica y lo que se cifra es el texto
 * indentado, si el sobre todavia no cabe se vuelve a recortar contra un
 * presupuesto menor, sin volver a recolectar.
 *
 * ## Funciona con la licencia caducada o ausente (regla dura 15)
 *
 * No consulta la licencia para decidir nada. La seccion `license` **informa** de
 * su estado, que es la primera pregunta de cualquier incidencia.
 */
final readonly class GenerateDiagnosticsBundleHandler
{
    /** Cuantas veces se vuelve a recortar un sobre que no cabe en el tope. */
    private const int SEALED_FIT_ATTEMPTS = 4;

    /**
     * @param  list<DiagnosticsCollector>  $collectors  En el orden en que van al documento.
     */
    public function __construct(
        private array $collectors,
        private ProductEventPublisher $events,
        private Clock $clock,
        private string $productVersion,
        private int $maxBytes,
        private ProbeFailureClassifier $failures,
        private ResponsibleAccounts $accounts,
        private SerializedLedgerWrite $ledger,
        private DiagnosticsBundleSealer $sealer,
    ) {}

    /**
     * @param  int|null  $responsibleUserId  la cuenta `admin` que firma el paquete con datos personales pedido por consola
     * @return DiagnosticsBundle|SealedDiagnosticsBundle el anonimizado en claro; el que lleva datos personales, siempre sellado
     *
     * @throws DiagnosticsResponsibleNotAllowed
     * @throws DiagnosticsSealingUnavailable
     */
    public function handle(DiagnosticsOptions $options, DiagnosticsActor $actor, ?int $responsibleUserId = null): DiagnosticsBundle|SealedDiagnosticsBundle
    {
        return UseCaseInstrumentation::run(
            'product.generate_diagnostics_bundle',
            fn (): DiagnosticsBundle|SealedDiagnosticsBundle => $this->doHandle($options, $actor, $responsibleUserId),
        );
    }

    private function doHandle(DiagnosticsOptions $options, DiagnosticsActor $actor, ?int $responsibleUserId): DiagnosticsBundle|SealedDiagnosticsBundle
    {
        $this->assertResponsible($options, $actor, $responsibleUserId);

        $sections = [];

        foreach ($this->collectors as $collector) {
            $name = $collector->section();

            if ($name === 'personal_data' && ! $options->includePersonalData) {
                continue;
            }

            $sections[$name] = $this->collect($collector, $options);
        }

        $generatedAt = $this->clock->now();

        if ($options->includePersonalData) {
            $sealed = $this->seal($sections, $generatedAt, $actor, $options);
            $this->audit($sealed->manifest, \strlen($sealed->toJson()), $options, $actor, $responsibleUserId, $sealed);

            return $sealed;
        }

        $bundle = DiagnosticsBundle::of(
            productVersion: $this->productVersion,
            generatedAt: $generatedAt,
            anonymized: true,
            generatedBy: $actor,
            sections: $sections,
            maxBytes: $this->maxBytes,
        );

        try {
            $this->audit($bundle->manifest, \strlen($bundle->toJson()), $options, $actor, $responsibleUserId);
        } catch (Throwable $failure) {
            if (! $this->mayDefer($failure, $options, $actor)) {
                throw $failure;
            }

            return $bundle->withGenerationEntryPending(PendingDiagnosticsAudit::REASON_DATABASE_UNAVAILABLE);
        }

        return $bundle;
    }

    /**
     * El paquete con datos personales, recortado y sellado para que el sobre
     * quepa en el tope. Ver el docblock de la clase.
     *
     * @param  array<string, array<array-key, mixed>>  $sections
     */
    private function seal(
        array $sections,
        DateTimeImmutable $generatedAt,
        DiagnosticsActor $actor,
        DiagnosticsOptions $options,
    ): SealedDiagnosticsBundle {
        $budget = SealedDiagnosticsBundle::plaintextBudget($this->maxBytes);
        $attempt = 0;

        while (true) {
            $sealed = $this->sealer->seal(DiagnosticsBundle::of(
                productVersion: $this->productVersion,
                generatedAt: $generatedAt,
                anonymized: false,
                generatedBy: $actor,
                sections: $sections,
                maxBytes: $budget,
            ), $options->periodDays);

            $size = \strlen($sealed->toJson());

            // Con el ultimo intento ya se ha renunciado a todo lo que se puede
            // renunciar: las seis secciones fijas no se sacrifican nunca.
            if ($size <= $this->maxBytes || ++$attempt >= self::SEALED_FIT_ATTEMPTS) {
                return $sealed;
            }

            // Proporcional a lo que sobra, con un 10 % mas de margen: el
            // indentado no crece igual en todas las secciones.
            $budget = max(1, intdiv(intdiv($budget * 9, 10) * $this->maxBytes, $size));
        }
    }

    /**
     * Las tres condiciones de la enmienda de ADR-020, todas a la vez. Ver el
     * docblock de la clase.
     */
    private function mayDefer(Throwable $failure, DiagnosticsOptions $options, DiagnosticsActor $actor): bool
    {
        return $actor === DiagnosticsActor::Console
            && ! $options->includePersonalData
            && $this->failures->unavailableService($failure) === ProbeFailureClassifier::DATABASE;
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

    /**
     * Por consola, el paquete con datos personales exige una cuenta `admin`
     * activa que lo firme; quien lo pide por el panel firma con su sesion y no
     * pasa cuenta. Y, venga de donde venga, una clave publica de soporte con la
     * que cifrarlo. Antes de recolectar nada: un paquete que no se va a poder
     * firmar o cifrar no se genera.
     *
     * @throws DiagnosticsResponsibleNotAllowed
     * @throws DiagnosticsSealingUnavailable
     */
    private function assertResponsible(DiagnosticsOptions $options, DiagnosticsActor $actor, ?int $responsibleUserId): void
    {
        if ($options->includePersonalData && ! $this->sealer->available()) {
            throw DiagnosticsSealingUnavailable::missingKey();
        }

        if ($responsibleUserId === null) {
            if ($actor === DiagnosticsActor::Console && $options->includePersonalData) {
                throw DiagnosticsResponsibleNotAllowed::missing();
            }

            return;
        }

        $this->assertActiveAdmin($responsibleUserId);
    }

    /**
     * @throws DiagnosticsResponsibleNotAllowed
     */
    private function assertActiveAdmin(int $responsibleUserId): void
    {
        if (! $this->accounts->isActiveManagementAccount($responsibleUserId, DiagnosticsSigners::ROLES)) {
            throw DiagnosticsResponsibleNotAllowed::notAnActiveAdmin();
        }
    }

    /**
     * @param  int  $sizeBytes  el tamaño de lo que se entrega: el sobre, si va sellado
     */
    private function audit(
        DiagnosticsManifest $manifest,
        int $sizeBytes,
        DiagnosticsOptions $options,
        DiagnosticsActor $actor,
        ?int $responsibleUserId,
        ?SealedDiagnosticsBundle $sealed = null,
    ): void {
        $now = $this->clock->now();
        $sealedSha256 = $sealed?->sha256();

        $events = [new DiagnosticsBundleGenerated(
            anonymized: $manifest->anonymized,
            sections: $manifest->sections,
            sha256: $manifest->sha256,
            sizeBytes: $sizeBytes,
            generatedBy: $actor->value,
            occurredAt: $now,
            responsibleUserId: $responsibleUserId,
            sealed: $sealed instanceof SealedDiagnosticsBundle,
            recipientKeyId: $sealed?->recipientKeyId,
            sealedSha256: $sealedSha256,
        )];

        if ($sealed instanceof SealedDiagnosticsBundle) {
            $events[] = new PersonalDataIncludedInDiagnostics(
                periodDays: $options->periodDays,
                // Las colecciones de la cabecera del sobre: lo mismo que el
                // cliente lee sin abrirlo.
                collections: array_map(strval(...), array_keys($sealed->contents['collections'] ?? [])),
                sha256: $manifest->sha256,
                generatedBy: $actor->value,
                occurredAt: $now,
                responsibleUserId: $responsibleUserId,
                sealed: true,
                recipientKeyId: $sealed->recipientKeyId,
                sealedSha256: $sealedSha256,
            );
        }

        if ($responsibleUserId === null) {
            $this->events->publish(...$events);

            return;
        }

        // Por consola, la cuenta que firma se vuelve a comprobar con la cadena
        // de `audit_log` tomada, y los dos asientos se escriben dentro de esa
        // misma transaccion: la baja de una cuenta toma la cadena antes de tocar
        // su fila (ADR-051 §6), asi que una baja durante la generacion no deja
        // un paquete con datos personales firmado por quien ya no responde. Si
        // lanza, no hay asiento y el paquete no se entrega.
        $this->ledger->withChainLock(function () use ($responsibleUserId, $events): void {
            $this->assertActiveAdmin($responsibleUserId);
            $this->events->publish(...$events);
        });
    }
}
