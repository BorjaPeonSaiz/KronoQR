<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Tests\Architecture\Support\Repo;

/*
 * La mutacion completa nocturna (RQ-10) esta repartida en una matriz de jobs
 * (31-10-2026): un solo runner la cortaba a los 120 y 180 min. Un reparto solo
 * sirve si ninguna parte del dominio queda fuera de la matriz y si cada parte
 * cabe en su tiempo; sin estas pruebas, un modulo nuevo con `Domain/` dejaria
 * de mutarse en silencio y el MSI global seguiria en verde midiendo menos.
 */

/**
 * Un job de un workflow, como mapa.
 *
 * @return array<string, mixed>
 */
function nightlyMutationMatrixJob(string $workflow, string $job): array
{
    $contenido = Yaml::parseFile(Repo::file($workflow));
    $jobs = is_array($contenido) && is_array($contenido['jobs'] ?? null) ? $contenido['jobs'] : [];
    $definicion = $jobs[$job] ?? null;
    if (! is_array($definicion)) {
        throw new RuntimeException("El job {$job} no existe en {$workflow}.");
    }

    $mapa = [];
    foreach ($definicion as $clave => $valor) {
        $mapa[(string) $clave] = $valor;
    }

    return $mapa;
}

/**
 * Un valor de un job que tiene que ser texto.
 *
 * @param  array<string, mixed>  $job
 */
function nightlyMutationMatrixText(array $job, string $clave): string
{
    $valor = $job[$clave] ?? null;
    if (! is_string($valor)) {
        throw new RuntimeException("«{$clave}» no es texto en el job.");
    }

    return $valor;
}

/**
 * Un valor del YAML que tiene que ser un mapa.
 *
 * @return array<string, mixed>
 */
function nightlyMutationMatrixMap(mixed $valor, string $donde): array
{
    if (! is_array($valor)) {
        throw new RuntimeException("«{$donde}» no es un mapa en el workflow.");
    }

    /** @var array<string, mixed> $valor */
    return $valor;
}

/**
 * Las partes de la matriz de `mutation`: nombre => modulos.
 *
 * @return array<string, list<string>>
 */
function nightlyMutationMatrixParts(): array
{
    $job = nightlyMutationMatrixJob('.github/workflows/ci.yml', 'mutation');
    $estrategia = nightlyMutationMatrixMap($job['strategy'] ?? null, 'strategy');
    $matriz = nightlyMutationMatrixMap($estrategia['matrix'] ?? null, 'strategy.matrix');

    $partes = [];
    foreach (nightlyMutationMatrixMap($matriz['include'] ?? null, 'strategy.matrix.include') as $entrada) {
        $parte = nightlyMutationMatrixMap($entrada, 'una parte de la matriz');
        $partes[nightlyMutationMatrixText($parte, 'part')] = array_map('trim', explode(',', nightlyMutationMatrixText($parte, 'modules')));
    }

    return $partes;
}

it('reparte entre las partes de la matriz todos los modulos con Domain, cada uno una sola vez', function (): void {
    $partes = nightlyMutationMatrixParts();
    $enLaMatriz = $partes === [] ? [] : array_merge(...array_values($partes));
    $conDominio = array_map(
        static fn (string $ruta): string => basename(dirname($ruta)),
        glob(Repo::file('backend/app/Modules').'/*/Domain', GLOB_ONLYDIR) ?: [],
    );
    sort($conDominio);
    sort($enLaMatriz);

    expect($conDominio)->not->toBe([])
        ->and($enLaMatriz)->toBe($conDominio, 'Un modulo con Domain/ sin parte en la matriz de `mutation` (ci.yml) no se muta de noche; uno repetido se muta dos veces y descuadra el MSI.');
})->group('RQ-10');

it('da a cada parte un tope que cabe con margen y no cancela las demas si una falla', function (): void {
    $parte = nightlyMutationMatrixJob('.github/workflows/ci.yml', 'mutation');
    $estrategia = $parte['strategy'] ?? null;

    expect($parte['timeout-minutes'] ?? null)->toBeInt()->toBeLessThanOrEqual(90)
        ->and(is_array($estrategia) ? ($estrategia['fail-fast'] ?? null) : null)->toBeFalse()
        ->and(count(nightlyMutationMatrixParts()))->toBeGreaterThanOrEqual(4);
})->group('RQ-10');

it('suma las partes en un job que corre aunque una falle, pero no si el run se cancela', function (): void {
    $ci = Repo::contents('.github/workflows/ci.yml');
    $total = nightlyMutationMatrixJob('.github/workflows/ci.yml', 'mutation-total');
    $condicion = nightlyMutationMatrixText($total, 'if');

    expect($total['needs'] ?? null)->toBe('mutation')
        ->and($condicion)->toContain('!cancelled()')
        ->and($condicion)->not->toContain('always()')
        ->and($condicion)->toContain("github.event_name == 'schedule'")
        ->and($condicion)->toContain('inputs.full_mutation')
        // Lo que sube cada parte es lo que descarga el total.
        ->and($ci)->toContain('name: mutacion-parte-${{ matrix.part }}-log')
        ->and($ci)->toContain('pattern: mutacion-parte-*-log')
        // El umbral global es el de RQ-10 y no se afloja aqui.
        ->and($ci)->toContain('mutation-log.sh total 80')
        // Si la matriz no termina en verde, el total falla aunque la suma salga bien.
        ->and($ci)->toContain('needs.mutation.result');
})->group('RQ-10');

/**
 * Los pasos de un job, como mapas.
 *
 * @return list<array<string, mixed>>
 */
function nightlyMutationMatrixSteps(string $job): array
{
    return nightlyMutationMatrixWorkflowSteps('ci.yml', $job);
}

it('pasa al total una ruta por cada parte de la matriz, y un log que falta es un error, no una parte menos', function (): void {
    $pasos = nightlyMutationMatrixSteps('mutation-total');
    $esperadas = null;
    foreach ($pasos as $paso) {
        $entorno = $paso['env'] ?? null;
        if (is_array($entorno) && isset($entorno['PARTES_ESPERADAS'])) {
            $esperadas = $entorno['PARTES_ESPERADAS'];
            $script = nightlyMutationMatrixText($paso, 'run');
        }
    }
    $enLaLista = is_string($esperadas) ? (preg_split('/\s+/', trim($esperadas)) ?: []) : [];
    $enLaMatriz = array_keys(nightlyMutationMatrixParts());
    sort($enLaMatriz);
    sort($enLaLista);

    expect($enLaLista)->toBe($enLaMatriz, 'PARTES_ESPERADAS de `mutation-total` y matrix.include de `mutation` tienen que ser las mismas partes.')
        ->and($script ?? '')->toContain('${RUNNER_TEMP}/partes/${parte}.log')
        // Nunca un glob: con `partes/*.log` una parte que no baja se omite en silencio.
        ->and($script ?? '')->not->toContain('partes/*.log');

    foreach ($pasos as $paso) {
        // Un artefacto que no baja pone el job en rojo; no se traga.
        expect($paso)->not->toHaveKey('continue-on-error');
    }
})->group('RQ-10');

it('deja a la cobertura un tope por encima de lo medido en octubre, sin cambiar el nombre del check protegido', function (): void {
    // Medido: 32-48 min en el PR y 32-45 min de noche, y el 31-10 se corto a
    // los 50 sin terminar. El tope de 50 era de cuando la suite tardaba 16-25.
    $cobertura = nightlyMutationMatrixJob('.github/workflows/gates.yml', 'coverage');

    expect($cobertura['name'] ?? null)->toBe('Cobertura (RNF-M-01)')
        ->and($cobertura['timeout-minutes'] ?? null)->toBeInt()->toBeGreaterThanOrEqual(60);
})->group('RNF-M-01', 'RQ-10');

it('explica en el runbook como se lee la mutacion nocturna repartida', function (): void {
    $runbook = Repo::contents('docs/runbooks/fallo-de-ci.md');

    expect($runbook)->toContain('mutation-total')
        ->and($runbook)->toContain('mutacion-parte-')
        ->and($runbook)->toContain('make mutate-part');
})->group('RQ-10');

it('lleva las tres piezas de la mutacion nocturna a los mismos eventos', function (): void {
    $condicion = "github.event_name == 'schedule' || (github.event_name == 'workflow_dispatch' && inputs.full_mutation)";

    expect(nightlyMutationMatrixText(nightlyMutationMatrixJob('.github/workflows/ci.yml', 'mutation'), 'if'))->toBe($condicion)
        ->and(nightlyMutationMatrixText(nightlyMutationMatrixJob('.github/workflows/ci.yml', 'mutation-scope'), 'if'))->toBe($condicion)
        ->and(nightlyMutationMatrixText(nightlyMutationMatrixJob('.github/workflows/ci.yml', 'mutation-total'), 'if'))->toBe('${{ !cancelled() && ('.$condicion.') }}');
})->group('RQ-10');

it('avisa cuando una parte de la mutacion o la cobertura pasan de 60 min, que es cuando hay que partirlas', function (): void {
    // El runbook dice «si pasa de 60 min, se parte»; sin una medida que lo avise
    // esa regla no la ejecuta nadie. Mismo patron que el presupuesto de la etapa ①.
    foreach (['mutation' => 'ci.yml', 'coverage' => 'gates.yml'] as $job => $workflow) {
        $presupuestos = array_values(array_filter(
            nightlyMutationMatrixWorkflowSteps($workflow, $job),
            static fn (array $paso): bool => str_starts_with(is_string($paso['name'] ?? null) ? $paso['name'] : '', 'Presupuesto de la '),
        ));
        expect($presupuestos)->toHaveCount(1, "El job {$job} de {$workflow} necesita su paso «Presupuesto».");

        $paso = $presupuestos[0];
        $entorno = nightlyMutationMatrixMap($paso['env'] ?? null, 'env del presupuesto');
        expect($entorno['PRESUPUESTO'] ?? null)->toBe('3600')
            ->and($paso['if'] ?? null)->toBe('always()')
            ->and(nightlyMutationMatrixText($paso, 'run'))->toContain('::warning title=Presupuesto excedido::');
    }
})->group('RQ-10', 'RNF-M-01');

/**
 * Los pasos de un job de un workflow, como mapas.
 *
 * @return list<array<string, mixed>>
 */
function nightlyMutationMatrixWorkflowSteps(string $workflow, string $job): array
{
    $pasos = nightlyMutationMatrixJob('.github/workflows/'.$workflow, $job)['steps'] ?? null;

    return array_map(
        static fn (mixed $paso): array => nightlyMutationMatrixMap($paso, 'un paso de '.$job),
        is_array($pasos) ? array_values($pasos) : [],
    );
}
