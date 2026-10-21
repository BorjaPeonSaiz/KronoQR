<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\ComplianceProfileRepository;
use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Telemetry\RecordingTracer;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * EL SPAN DE UN CASO DE USO QUE FALLA SE VE COMO FALLO, Y SIN EL MENSAJE
 * (V21-4-BE-2, RQ-06, regla dura 21).
 *
 * De extremo a extremo, por HTTP: `GET /api/v1/compliance-profile` con su
 * repositorio roto. El span `product.get_compliance_profile` sale con
 * `status=error` —lo que se busca en Tempo— y un evento `exception` con la
 * clase, nunca con el mensaje: el de una excepcion de infraestructura puede
 * llevar una consulta con valores o un host.
 */

uses(RefreshDatabase::class);

const FAILED_USE_CASE_SPAN_MARKER = 'host=10.20.30.40 user=Eustaquio Villarejo';

it('marca el span del caso de uso con status error, con la clase y sin el mensaje', function (): void {
    WorkforceFixtures::site();
    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    app()->instance(ComplianceProfileRepository::class, new class implements ComplianceProfileRepository
    {
        public function forSite(int $siteId): ?ComplianceProfileSnapshot
        {
            throw new RuntimeException('SQLSTATE[08006] '.FAILED_USE_CASE_SPAN_MARKER);
        }

        public function forSiteForWrite(int $siteId): ?ComplianceProfileSnapshot
        {
            throw new RuntimeException('SQLSTATE[08006] '.FAILED_USE_CASE_SPAN_MARKER);
        }

        public function nameIsTakenByAnotherProfile(int $profileId, string $name): bool
        {
            return false;
        }

        public function save(ComplianceProfileSnapshot $profile, ?int $actorUserId, DateTimeImmutable $at): void {}
    });

    RecordingTracer::around(function (RecordingTracer $tracer) use ($token): void {
        Api::as($token)->get('/api/v1/compliance-profile')->assertStatus(500);

        $spans = $tracer->finishedSpans();
        $useCase = array_values(array_filter($spans, static fn (ImmutableSpan $span): bool => $span->getName() === 'product.get_compliance_profile'));

        expect($useCase)->toHaveCount(1);

        $span = $useCase[0];
        $volcado = (string) json_encode(array_map(static fn (ImmutableSpan $s): array => [
            $s->getName(),
            $s->getAttributes()->toArray(),
            $s->getStatus()->getDescription(),
            array_map(static fn ($event): array => $event->getAttributes()->toArray(), $s->getEvents()),
        ], $spans));

        expect($span->getStatus()->getCode())->toBe(StatusCode::STATUS_ERROR)
            ->and($span->getAttributes()->get('outcome'))->toBe('error')
            ->and($span->getEvents()[0]->getAttributes()->get('exception.type'))->toBe(RuntimeException::class)
            ->and($volcado)->not->toContain('Eustaquio')
            ->and($volcado)->not->toContain('10.20.30.40');
    });
})->group('RQ-06', 'RNF-D-01', 'RS-05');
