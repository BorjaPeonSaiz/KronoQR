<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * Verificacion de la firma cosign de las imagenes del producto (B18, 2.2.1,
 * enmienda de ADR-053). `lib/image-signature.sh` se carga con `source` y se
 * ejecuta de verdad, con un `cosign` falso en el PATH que anota como se le llama.
 */

const IMAGE_SIGNATURE_REGISTRY = 'ghcr.io/acme/kronoqr';
const IMAGE_SIGNATURE_DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

/**
 * @param  list<string>  $images
 * @param  int|null  $cosignExit  null = sin cosign en el PATH
 * @return array{result: string, failed: string, detail: string, calls: string, exit: int|null}
 */
function imageSignatureRun(array $images, ?int $cosignExit, bool $skip = false): array
{
    $dir = sys_get_temp_dir().'/kq-image-signature-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0777, true);

    if ($cosignExit !== null) {
        file_put_contents(
            $dir.'/bin/cosign',
            "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >>".escapeshellarg($dir.'/llamadas')."\n"
            .($cosignExit === 0 ? '' : "echo 'Error: no matching signatures' >&2\n")
            ."exit {$cosignExit}\n",
        );
        chmod($dir.'/bin/cosign', 0755);
    }

    $quoted = implode(' ', array_map('escapeshellarg', $images));
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/lib/image-signature.sh')).'; '
        .($skip ? 'KQ_SKIP_SIGNATURE_CHECK=1; ' : '')
        .'status=0; kq_verify_product_images '.escapeshellarg(IMAGE_SIGNATURE_REGISTRY).' '.$quoted.' || status=$?; '
        .'printf "%s|%s|%s|%s" "${KQ_SIG_RESULT}" "${KQ_SIG_FAILED_IMAGE}" "${KQ_SIG_DETAIL}" "${status}"';

    // El cosign falso va el primero; sin el, se quita cualquier cosign real del PATH.
    $path = $cosignExit !== null
        ? $dir.'/bin:'.getenv('PATH')
        : implode(':', array_filter(
            explode(':', (string) getenv('PATH')),
            static fn (string $part): bool => ! is_file($part.'/cosign'),
        ));

    $process = new Process(['bash', '-c', $script], env: ['PATH' => $path], timeout: 30.0);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    [$result, $failed, $detail, $exit] = array_pad(explode('|', $process->getOutput(), 4), 4, '');
    $calls = is_file($dir.'/llamadas') ? (string) file_get_contents($dir.'/llamadas') : '';

    return ['result' => $result, 'failed' => $failed, 'detail' => $detail, 'calls' => $calls, 'exit' => (int) $exit];
}

function imageSignaturePinned(string $name): string
{
    return IMAGE_SIGNATURE_REGISTRY.'/'.$name.':2.2.1@'.IMAGE_SIGNATURE_DIGEST;
}

it('verifica cada imagen del producto por digest con la identidad del workflow de release y el emisor de GitHub', function (): void {
    $run = imageSignatureRun([
        imageSignaturePinned('php'),
        imageSignaturePinned('nginx'),
        imageSignaturePinned('postgres'),
    ], 0);

    expect($run['result'])->toBe('verified')
        ->and($run['exit'])->toBe(0)
        // Una llamada por imagen, con el digest y SIN la etiqueta delante.
        ->and(substr_count($run['calls'], 'verify '))->toBe(3)
        ->and($run['calls'])->toContain(IMAGE_SIGNATURE_REGISTRY.'/php@'.IMAGE_SIGNATURE_DIGEST)
        ->and($run['calls'])->not->toContain(':2.2.1@')
        ->and($run['calls'])->toContain('--certificate-oidc-issuer https://token.actions.githubusercontent.com')
        ->and($run['calls'])->toContain('--certificate-identity-regexp')
        ->and($run['calls'])->toContain('/\.github/workflows/release\.yml@refs/');
})->group('RS-10', 'RF-PD-02');

it('una firma que no vale es un fallo: dice que imagen y deja el detalle de cosign, y no sigue con las demas', function (): void {
    $run = imageSignatureRun([
        imageSignaturePinned('php'),
        imageSignaturePinned('nginx'),
    ], 1);

    expect($run['result'])->toBe('failed')
        ->and($run['exit'])->toBe(1)
        ->and($run['failed'])->toBe(imageSignaturePinned('php'))
        ->and($run['detail'])->toContain('no matching signatures')
        ->and(substr_count($run['calls'], 'verify '))->toBe(1);
})->group('RS-10', 'RF-PD-02');

it('sin cosign no falla: avisa y sigue valiendo el digest', function (): void {
    $run = imageSignatureRun([imageSignaturePinned('php')], null);

    expect($run['result'])->toBe('no-cosign')
        ->and($run['exit'])->toBe(0);
})->group('RS-10', 'RF-PD-02');

it('--skip-signature-check no llama a cosign aunque este y fallaria', function (): void {
    $run = imageSignatureRun([imageSignaturePinned('php')], 1, skip: true);

    expect($run['result'])->toBe('skipped')
        ->and($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe('');
})->group('RS-10', 'RF-PD-02');

it('no hay nada que verificar sin digest ni en las imagenes de terceros (redis, observabilidad)', function (): void {
    $run = imageSignatureRun([
        IMAGE_SIGNATURE_REGISTRY.'/php:2.2.1',
        'redis:7-alpine@'.IMAGE_SIGNATURE_DIGEST,
        'grafana/grafana:11.5.1@'.IMAGE_SIGNATURE_DIGEST,
    ], 1);

    expect($run['result'])->toBe('not-pinned')
        ->and($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe('');
})->group('RS-10', 'RF-PD-02');

it('install.sh y update.sh verifican antes de descargar y salen con 7 si la firma falla; la CI de las imagenes sin firmar usa la opcion', function (): void {
    $install = Repo::contents('infra/scripts/install.sh');
    $update = Repo::contents('infra/scripts/update.sh');
    $ci = Repo::contents('.github/workflows/ci.yml');

    foreach ([$install, $update] as $script) {
        expect($script)->toContain('. "${SCRIPT_DIR}/lib/image-signature.sh"')
            ->and($script)->toContain('--skip-signature-check)')
            ->and($script)->toContain('kq_verify_product_images')
            ->and($script)->toContain('"${KQ_EXIT_SECURITY}"');
    }

    // Antes del primer `docker pull` de cada script.
    expect(strpos($install, 'verify_image_signatures ${all+'))->toBeLessThan((int) strpos($install, 'docker pull --quiet'))
        ->and(strpos($update, 'kq_verify_product_images "'))->toBeLessThan((int) strpos($update, 'docker pull --quiet'));

    // La etapa 8b instala desde un registro local con imagenes SIN firmar y fijadas por
    // digest: cada install.sh/update.sh sobre los paquetes `paquete-fijado-*` (y sobre
    // `paquete-anterior` tras superponerlos) lleva la opcion, a la vista en el log.
    expect($ci)->toContain('--skip-signature-check');
})->group('RS-10', 'RF-PD-02');
