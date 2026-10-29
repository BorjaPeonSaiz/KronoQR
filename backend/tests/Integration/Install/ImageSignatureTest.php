<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Architecture\Support\Repo;

/*
 * Verificacion de la firma cosign de las imagenes del producto (B18, 2.2.1,
 * enmienda de ADR-053). `lib/image-signature.sh` se carga con `source` y se
 * ejecuta de verdad, con un `cosign` falso en el PATH que anota como se le llama.
 * Los caminos de salida 7 de `install.sh` y `update.sh` se ejercitan tambien de
 * verdad (con `docker` y `cosign` falsos), no por busqueda de texto.
 */

const IMAGE_SIGNATURE_REGISTRY = 'ghcr.io/acme/kronoqr';
const IMAGE_SIGNATURE_DIGEST = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

/**
 * Crea un directorio con un `bin/` que lleva un cosign falso, y devuelve el
 * directorio. `$mode`: ok | invalid | network. `null` = sin cosign.
 */
function imageSignatureSandbox(?string $mode, string $version = 'v3.1.3'): string
{
    $dir = sys_get_temp_dir().'/kq-image-signature-'.bin2hex(random_bytes(4));
    mkdir($dir.'/bin', 0777, true);

    if ($mode !== null) {
        $failure = match ($mode) {
            'invalid' => "echo 'Error: no matching signatures' >&2\nexit 1\n",
            'network' => "echo 'Error: dial tcp: lookup rekor.sigstore.dev: no such host' >&2\nexit 1\n",
            'denied' => "echo 'Error: GET https://ghcr.io/v2/acme/kronoqr/php/referrers: DENIED: denied' >&2\nexit 1\n",
            'ratelimit' => "echo 'Error: unexpected status code 429 Too Many Requests: toomanyrequests' >&2\nexit 1\n",
            'sig404' => "echo 'Error: GET https://ghcr.io/v2/acme/kronoqr/php/manifests/sha256-aaa.sig: MANIFEST_UNKNOWN: manifest unknown; unexpected status code 404 Not Found' >&2\nexit 1\n",
            'denied-and-nosig' => "echo 'Error: no signatures found; GET https://ghcr.io/token: DENIED: denied' >&2\nexit 1\n",
            // Se verifican todas las imagenes aunque la primera no tenga red. La primera llamada no tiene red; las siguientes, firma inexistente.
            'network-then-invalid' => 'if [ ! -e '.escapeshellarg($dir.'/segunda').' ]; then : >'.escapeshellarg($dir.'/segunda')."; echo 'Error: dial tcp: i/o timeout' >&2; exit 1; fi\necho 'Error: no matching signatures' >&2\nexit 1\n",
            default => "exit 0\n",
        };
        file_put_contents(
            $dir.'/bin/cosign',
            "#!/usr/bin/env bash\n"
            ."if [ \"\$1\" = version ]; then printf 'GitVersion:    %s\\n' ".escapeshellarg($version)."; exit 0; fi\n"
            ."printf '%s\\n' \"\$*\" >>".escapeshellarg($dir.'/llamadas')."\n"
            .$failure,
        );
        chmod($dir.'/bin/cosign', 0755);
    }

    return $dir;
}

/** El PATH con el cosign falso delante, o sin ningun cosign si no hay falso. */
function imageSignaturePath(string $dir, bool $hasCosign): string
{
    return $hasCosign
        ? $dir.'/bin:'.getenv('PATH')
        : implode(':', array_filter(
            explode(':', (string) getenv('PATH')),
            static fn (string $part): bool => ! is_file($part.'/cosign'),
        ));
}

/**
 * @param  list<string>  $images
 * @return array{result: string, failed: string, detail: string, calls: string, exit: int}
 */
function imageSignatureRun(array $images, ?string $mode, bool $skip = false, string $version = 'v3.1.3'): array
{
    $dir = imageSignatureSandbox($mode, $version);

    $quoted = implode(' ', array_map('escapeshellarg', $images));
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/lib/image-signature.sh')).'; '
        .($skip ? 'KQ_SKIP_SIGNATURE_CHECK=1; ' : '')
        .'status=0; kq_verify_product_images '.escapeshellarg(IMAGE_SIGNATURE_REGISTRY).' '.$quoted.' || status=$?; '
        .'printf "%s|%s|%s|%s" "${KQ_SIG_RESULT}" "${KQ_SIG_FAILED_IMAGE}" "${KQ_SIG_DETAIL}" "${status}"';

    $process = new Process(['bash', '-c', $script], env: ['PATH' => imageSignaturePath($dir, $mode !== null)], timeout: 30.0);
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
    ], 'ok');

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
    ], 'invalid');

    expect($run['result'])->toBe('failed')
        ->and($run['exit'])->toBe(1)
        ->and($run['failed'])->toBe(imageSignaturePinned('php'))
        ->and($run['detail'])->toContain('no matching signatures')
        ->and(substr_count($run['calls'], 'verify '))->toBe(1);
})->group('RS-10', 'RF-PD-02');

it('no poder consultar Sigstore o el registro NO es una firma invalida: aviso y sigue valiendo el digest', function (): void {
    $run = imageSignatureRun([imageSignaturePinned('php'), imageSignaturePinned('nginx')], 'network');

    expect($run['result'])->toBe('unverifiable')
        ->and($run['exit'])->toBe(0)
        ->and($run['detail'])->toContain('no such host')
        ->and(substr_count($run['calls'], 'verify '))->toBe(2);
})->group('RS-10', 'RF-PD-02');

it('una respuesta del registro (denied, 429) a la firma es una firma invalida, nunca un aviso', function (string $mode): void {
    $run = imageSignatureRun([imageSignaturePinned('php')], $mode);

    expect($run['result'])->toBe('failed')
        ->and($run['exit'])->toBe(1)
        ->and($run['failed'])->toBe(imageSignaturePinned('php'));
})->with(['denied', 'ratelimit'])->group('RS-10', 'RF-PD-02');

it('una imagen inaccesible seguida de otra invalida es un fallo: se verifican todas y manda la peor', function (): void {
    $run = imageSignatureRun([imageSignaturePinned('php'), imageSignaturePinned('nginx')], 'network-then-invalid');

    expect($run['result'])->toBe('failed')
        ->and($run['exit'])->toBe(1)
        ->and($run['failed'])->toBe(imageSignaturePinned('nginx'))
        ->and(substr_count($run['calls'], 'verify '))->toBe(2);
})->group('RS-10', 'RF-PD-02');

it('sin cosign no falla: avisa y sigue valiendo el digest', function (): void {
    $run = imageSignatureRun([imageSignaturePinned('php')], null);

    expect($run['result'])->toBe('no-cosign')
        ->and($run['exit'])->toBe(0);
})->group('RS-10', 'RF-PD-02');

it('un cosign anterior a 3.0.0 se trata como no instalado, con su aviso, y no se le llama', function (string $version, string $esperado): void {
    $run = imageSignatureRun([imageSignaturePinned('php')], 'invalid', version: $version);

    expect($run['result'])->toBe($esperado)
        ->and($run['exit'])->toBe($esperado === 'failed' ? 1 : 0)
        ->and(str_contains($run['calls'], 'verify '))->toBe($esperado === 'failed');
})->with([
    'cosign 2.4.1' => ['v2.4.1', 'no-cosign'],
    'cosign 2.6.0' => ['v2.6.0', 'no-cosign'],
    'cosign 3.0.0 (el minimo)' => ['v3.0.0', 'failed'],
    'cosign 3.1.3' => ['v3.1.3', 'failed'],
    'una prueba de 3.2' => ['v3.2.0-rc.1', 'failed'],
])->group('RS-10', 'RF-PD-02');

it('--skip-signature-check no llama a cosign aunque este y fallaria', function (): void {
    $run = imageSignatureRun([imageSignaturePinned('php')], 'invalid', skip: true);

    expect($run['result'])->toBe('skipped')
        ->and($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe('');
})->group('RS-10', 'RF-PD-02');

it('no hay nada que verificar sin digest ni en las imagenes de terceros (redis, observabilidad)', function (): void {
    $run = imageSignatureRun([
        IMAGE_SIGNATURE_REGISTRY.'/php:2.2.1',
        'redis:7-alpine@'.IMAGE_SIGNATURE_DIGEST,
        'grafana/grafana:11.5.1@'.IMAGE_SIGNATURE_DIGEST,
    ], 'invalid');

    expect($run['result'])->toBe('not-pinned')
        ->and($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe('');
})->group('RS-10', 'RF-PD-02');

it('verifica SHA256SUMS contra su bundle con la misma identidad (verify-blob)', function (): void {
    $dir = imageSignatureSandbox('ok');
    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/lib/image-signature.sh')).'; '
        .'kq_cosign_verify_blob SHA256SUMS SHA256SUMS.bundle && echo valida';
    $process = new Process(['bash', '-c', $script], env: ['PATH' => imageSignaturePath($dir, true)], timeout: 30.0);
    $process->run();
    $calls = (string) file_get_contents($dir.'/llamadas');

    expect($process->getOutput())->toContain('valida')
        ->and($calls)->toContain('verify-blob --bundle SHA256SUMS.bundle')
        ->and($calls)->toContain('--certificate-oidc-issuer https://token.actions.githubusercontent.com')
        ->and($calls)->toContain('--certificate-identity-regexp');
})->group('RS-10', 'RF-PD-02');

/**
 * Un directorio con `docker` y (si se pide) `cosign` falsos. El `docker` falso
 * lista las cuatro imagenes de `compose config --images` y dice que NINGUNA esta
 * en local, para que cualquier descarga se vea en `docker.log`.
 *
 * @return array{dir: string, path: string}
 */
function imageSignatureInstallation(?string $cosignMode): array
{
    $dir = imageSignatureSandbox($cosignMode);
    file_put_contents($dir.'/imagenes', implode("\n", [
        imageSignaturePinned('php'),
        imageSignaturePinned('nginx'),
        imageSignaturePinned('postgres'),
        'redis:7-alpine@'.IMAGE_SIGNATURE_DIGEST,
    ])."\n");
    file_put_contents(
        $dir.'/bin/docker',
        "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >>".escapeshellarg($dir.'/docker.log')."\n"
        ."case \"\$*\" in *'config --images'*) cat ".escapeshellarg($dir.'/imagenes')."; exit 0 ;; *'image inspect'*) exit 1 ;; esac\nexit 0\n",
    );
    chmod($dir.'/bin/docker', 0755);
    file_put_contents($dir.'/.env', "APP_KEY=base64:secreto\nIMAGE_REGISTRY=".IMAGE_SIGNATURE_REGISTRY."\nIMAGE_TAG=2.2.1\n");
    file_put_contents($dir.'/docker-compose.yml', '');

    return ['dir' => $dir, 'path' => imageSignaturePath($dir, $cosignMode !== null)];
}

it('install.sh con una firma invalida deshace lo escrito, no descarga nada y sale con 7', function (): void {
    ['dir' => $dir, 'path' => $path] = imageSignatureInstallation('invalid');

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/install.sh')).'; '
        .'kq_msg_init es; ENV_FILE='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; PRODUCT_VERSION=2.2.1; '
        // Lo que la fase 3 habria dejado, y su deshacer.
        .'register_undo "env" "rm -f \'${ENV_FILE}\'"; '
        .'pull_images_if_needed';
    $process = new Process(['bash', '-c', $script], env: ['PATH' => $path, 'KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();

    $docker = is_file($dir.'/docker.log') ? (string) file_get_contents($dir.'/docker.log') : '';

    expect($process->getExitCode())->toBe(7, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('no es valida segun cosign')
        ->and($process->getErrorOutput())->toContain('no matching signatures')
        // Vuelta atras hecha: el .env con secretos ya no esta, ni la constancia.
        ->and(is_file($dir.'/.env'))->toBeFalse()
        ->and(is_file($dir.'/.kronoqr-signature-check'))->toBeFalse()
        // Y no se descargo ni un byte.
        ->and($docker)->not->toContain('pull');
})->group('RS-10', 'RF-PD-02');

it('install.sh con la firma valida deja constancia 0600 junto al .env; con --skip-signature-check, tambien', function (string $mode, bool $skip, string $esperado): void {
    ['dir' => $dir, 'path' => $path] = imageSignatureInstallation($mode);

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/install.sh')).'; '
        .'kq_msg_init es; ENV_FILE='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; PRODUCT_VERSION=2.2.1; '
        .($skip ? 'KQ_SKIP_SIGNATURE_CHECK=1; ' : '')
        .'verify_image_signatures $(compose config --images)';
    $process = new Process(['bash', '-c', $script], env: ['PATH' => $path, 'KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and((string) file_get_contents($dir.'/.kronoqr-signature-check'))->toContain('image_signature='.$esperado)
        ->and(substr(sprintf('%o', fileperms($dir.'/.kronoqr-signature-check')), -4))->toBe('0600');
})->with([
    'verificada' => ['ok', false, 'verified'],
    'omitida' => ['invalid', true, 'skipped'],
    'sin poder consultar' => ['network', false, 'unverifiable'],
])->group('RS-10', 'RF-PD-02');

it('install.sh detecta la firma invalida YA en la fase 1 (--check-only), antes de generar secretos, y sale con 7', function (): void {
    ['dir' => $dir, 'path' => $path] = imageSignatureInstallation('invalid');
    file_put_contents($dir.'/images.lock', 'php '.IMAGE_SIGNATURE_DIGEST."\nnginx ".IMAGE_SIGNATURE_DIGEST."\npostgres ".IMAGE_SIGNATURE_DIGEST."\n");

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/install.sh')).'; '
        .'kq_msg_init es; ENV_FILE='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; '
        .'check_image_signatures';
    $process = new Process(['bash', '-c', $script], env: ['PATH' => $path, 'KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();

    expect($process->getExitCode())->toBe(7, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('no es valida segun cosign');
})->group('RS-10', 'RF-PD-02');

it('update.sh con una firma invalida sale con 7 en el paso 1, no descarga nada y deja image-signature en el informe', function (): void {
    ['dir' => $dir, 'path' => $path] = imageSignatureInstallation('invalid');

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
        .'kq_msg_init es; DOCKER_OK=1; TARGET_VERSION=2.2.1; SOURCE_VERSION=2.2.0; '
        .'CURRENT_ENV='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; PACKAGE_DIR='.escapeshellarg($dir).'; '
        .'REPORT_WORK='.escapeshellarg($dir.'/informe.txt').'; : >"${REPORT_WORK}"; '
        .'trap \'cat "${REPORT_WORK}"\' EXIT; '
        .'check_images';
    $process = new Process(['bash', '-c', $script], env: ['PATH' => $path, 'KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();

    $docker = is_file($dir.'/docker.log') ? (string) file_get_contents($dir.'/docker.log') : '';

    expect($process->getExitCode())->toBe(7, $process->getErrorOutput().$process->getOutput())
        ->and($process->getErrorOutput())->toContain('no es valida segun cosign')
        ->and($process->getOutput())->toContain('image-signature')
        ->and($process->getOutput())->toContain('Salida 7')
        ->and($docker)->not->toContain('pull');
})->group('RS-10', 'RF-PD-02', 'RF-PD-10');

it('update.sh sin poder consultar Sigstore sigue (aviso) y lo anota en el informe', function (): void {
    ['dir' => $dir, 'path' => $path] = imageSignatureInstallation('network');

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
        .'kq_msg_init es; DOCKER_OK=1; TARGET_VERSION=2.2.1; SOURCE_VERSION=2.2.0; '
        .'CURRENT_ENV='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; PACKAGE_DIR='.escapeshellarg($dir).'; '
        .'check_images >/dev/null 2>&1 || true; '
        .'printf "%s|%s\n" "${KQ_SIG_RESULT}" "$(printf "%s;" "${REPORT_CHECKS[@]}")"';
    $process = new Process(['bash', '-c', $script], env: ['PATH' => $path, 'KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();

    expect($process->getOutput())->toContain('unverifiable|')
        ->and($process->getOutput())->toContain('image-signature|no se pudo consultar');
})->group('RS-10', 'RF-PD-10');

it('install.sh y update.sh llaman a la verificacion antes de descargar, usan el 7 y la CI de las imagenes sin firmar usa la opcion', function (): void {
    $install = Repo::contents('infra/scripts/install.sh');
    $update = Repo::contents('infra/scripts/update.sh');
    // ⑧b vive en gates.yml desde la 23.3.
    $ci = Repo::contents('.github/workflows/gates.yml');

    foreach ([$install, $update] as $script) {
        expect($script)->toContain('. "${SCRIPT_DIR}/lib/image-signature.sh"')
            ->and($script)->toContain('--skip-signature-check)')
            ->and($script)->toContain('"${KQ_EXIT_SECURITY}"');
    }

    // La etapa 8b instala desde un registro local con imagenes SIN firmar y fijadas por
    // digest: cada install.sh/update.sh sobre los paquetes `paquete-fijado-*` lleva la
    // opcion, a la vista en el log.
    expect(substr_count($ci, '--skip-signature-check'))->toBeGreaterThanOrEqual(4);
})->group('RS-10', 'RF-PD-02');

it('avisa cuando el .env declara REDIS_IMAGE_DIGEST (vacia o con otro valor) y no avisa si no esta', function (string $env, string $esperado): void {
    $dir = sys_get_temp_dir().'/kq-redis-digest-'.bin2hex(random_bytes(4));
    mkdir($dir, 0777, true);
    file_put_contents($dir.'/.env', $env);

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; kq_msg_init es; '
        .'check_image_digest_overrides '.escapeshellarg($dir.'/.env').' '.escapeshellarg($dir).'; '
        .'printf "avisos=%s nombres=%s\n" "${CHECKS_WARNED}" "${KQ_DIGEST_OVERRIDES}"';
    $process = new Process(['bash', '-c', $script], env: ['KRONOQR_LANG' => 'es'], timeout: 30.0);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain($esperado);
})->with([
    'vacia (opt-out sin internet)' => ["REDIS_IMAGE_DIGEST=\n", 'avisos=1 nombres=REDIS_IMAGE_DIGEST'],
    'con otro digest' => ["REDIS_IMAGE_DIGEST=@sha256:otro\n", 'avisos=1 nombres=REDIS_IMAGE_DIGEST'],
    'sin declarar' => ["APP_KEY=x\n", 'avisos=0 nombres='],
])->group('RS-10', 'RF-PD-02');

it('update.sh lee el images.lock separado por espacios y pasa IMAGE_DIGEST_*=@sha256 a compose tras una vuelta atras in place', function (): void {
    // Con IFS=$'\n\t' (el de update.sh) `read -r name digest` metia la linea entera en
    // `name` y el reintento nunca fijaba los digests del paquete.
    ['dir' => $dir, 'path' => $path] = imageSignatureInstallation(null);
    file_put_contents(
        $dir.'/bin/docker',
        "#!/usr/bin/env bash\ncase \"\$*\" in *'config --images'*) printf 'ENV_PHP=%s ENV_NGINX=%s ENV_POSTGRES=%s\n' \"\$IMAGE_DIGEST_PHP\" \"\$IMAGE_DIGEST_NGINX\" \"\$IMAGE_DIGEST_POSTGRES\" >>".escapeshellarg($dir.'/entorno').'; cat '.escapeshellarg($dir.'/imagenes')." ;; esac\nexit 0\n",
    );
    $a = 'sha256:'.str_repeat('a', 64);
    $b = 'sha256:'.str_repeat('b', 64);
    $c = 'sha256:'.str_repeat('c', 64);
    file_put_contents($dir.'/images.lock', "php {$a}\nnginx {$b}\npostgres {$c}\n");
    file_put_contents($dir.'/.env', "APP_KEY=x\nIMAGE_REGISTRY=".IMAGE_SIGNATURE_REGISTRY."\nIMAGE_DIGEST_PHP=\nIMAGE_DIGEST_NGINX=\nIMAGE_DIGEST_POSTGRES=\n");

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; kq_msg_init es; '
        .'kq_env_mark_rollback_digests '.escapeshellarg($dir.'/.env').'; '
        .'DOCKER_OK=1; TARGET_VERSION=2.2.1; SOURCE_VERSION=2.2.0; '
        .'CURRENT_ENV='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; PACKAGE_DIR='.escapeshellarg($dir).'; '
        .'check_images >/dev/null 2>&1 || true';
    $process = new Process(['bash', '-c', $script], env: ['PATH' => $dir.'/bin:'.getenv('PATH'), 'KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();

    expect((string) file_get_contents($dir.'/entorno'))
        ->toContain("ENV_PHP=@{$a} ENV_NGINX=@{$b} ENV_POSTGRES=@{$c}");
})->group('RS-10', 'RF-PD-10');

it('un registro que niega el acceso nombra IMAGE_REGISTRY y su valor, y no habla de firma rota (V21-1-PL-01)', function (string $script): void {
    // Sigue saliendo con 7 (se falla en cerrado: un registro comprometido puede
    // negar solo la firma), pero el texto empieza por lo que casi siempre es: un
    // IMAGE_REGISTRY mal escrito. install.sh y update.sh comparten el texto.
    ['dir' => $dir, 'path' => $path] = imageSignatureInstallation('denied');
    file_put_contents($dir.'/images.lock', 'php '.IMAGE_SIGNATURE_DIGEST."\nnginx ".IMAGE_SIGNATURE_DIGEST."\npostgres ".IMAGE_SIGNATURE_DIGEST."\n");

    $llamada = $script === 'install'
        ? 'ENV_FILE='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; check_image_signatures'
        : 'DOCKER_OK=1; TARGET_VERSION=2.2.1; SOURCE_VERSION=2.2.0; CURRENT_ENV='.escapeshellarg($dir.'/.env')
            .'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; PACKAGE_DIR='.escapeshellarg($dir).'; check_images';
    $process = new Process(
        ['bash', '-c', 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/'.$script.'.sh')).'; kq_msg_init es; '.$llamada],
        env: ['PATH' => $path, 'KRONOQR_LANG' => 'es'],
        timeout: 60.0,
    );
    $process->run();

    expect($process->getExitCode())->toBe(7, $process->getErrorOutput())
        ->and($process->getErrorOutput())->toContain('--skip-signature-check')
        ->and($process->getErrorOutput())->not->toContain('no es valida segun cosign');

    if ($script === 'install') {
        expect($process->getErrorOutput())->toContain('NEGADO EL ACCESO')
            ->and($process->getErrorOutput())->toContain("IMAGE_REGISTRY: ahora vale '".IMAGE_SIGNATURE_REGISTRY."'");
    } else {
        // En update.sh el registro es el que servia la version instalada: «mal
        // escrito» no es lo probable, y el texto no lo sugiere.
        expect($process->getErrorOutput())->toContain('el registro que servia la version instalada ('.IMAGE_SIGNATURE_REGISTRY.') NIEGA EL ACCESO')
            ->and($process->getErrorOutput())->not->toContain('mal escrito');
    }
})->with(['install', 'update'])->group('RS-10', 'RF-PD-02', 'RF-PD-10');

it('una firma inexistente o un 429 siguen siendo «firma no valida», y solo el acceso negado culpa al registro', function (string $mode, string $esperado): void {
    $dir = imageSignatureSandbox($mode);
    $process = new Process(
        ['bash', '-c', 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/lib/messages.sh'))
            .'; . '.escapeshellarg(Repo::file('infra/scripts/lib/image-signature.sh')).'; kq_msg_init es; '
            .'kq_verify_product_images '.escapeshellarg(IMAGE_SIGNATURE_REGISTRY).' '.escapeshellarg(imageSignaturePinned('php')).' || true; '
            .'printf "%s|%s|" "${KQ_SIG_RESULT}" "${KQ_SIG_DENIED}"; kq_sig_failed_text '.escapeshellarg(IMAGE_SIGNATURE_REGISTRY)],
        env: ['PATH' => imageSignaturePath($dir, true)],
        timeout: 30.0,
    );
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toStartWith('failed|')
        ->and($process->getOutput())->toContain($esperado);
})->with([
    'firma inexistente' => ['invalid', 'no es valida segun cosign'],
    'limite de peticiones' => ['ratelimit', 'no es valida segun cosign'],
    // Una imagen sin firma: su `.sig` no existe y el registro da 404 / manifest
    // unknown. Es firma ausente, no acceso negado.
    'un 404 en el .sig' => ['sig404', 'no es valida segun cosign'],
    // Acceso negado Y firma ausente en la misma salida: manda la firma.
    'denied junto a firma ausente' => ['denied-and-nosig', 'no es valida segun cosign'],
    'acceso negado' => ['denied', 'NEGADO EL ACCESO'],
])->group('RS-10', 'RF-PD-02');

/**
 * update.sh `check_images` con un `docker` falso: ninguna imagen esta por su
 * digest; la descarga funciona o no segun `$pull`, y la etiqueta de cada una
 * responde a `{{len .RepoDigests}}` con `$repoDigests`.
 *
 * @return array{salida: string, docker: string}
 */
function updateConImagenes(bool $pull, int $repoDigests): array
{
    ['dir' => $dir] = imageSignatureInstallation(null);
    // Sin cosign, pero con el `docker` falso delante.
    $path = $dir.'/bin:'.getenv('PATH');
    file_put_contents(
        $dir.'/bin/docker',
        "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" >>".escapeshellarg($dir.'/docker.log')."\n"
        ."case \"\$*\" in *'config --images'*) cat ".escapeshellarg($dir.'/imagenes').'; exit 0 ;; '
        ."*'{{len .RepoDigests}}'*) echo ".$repoDigests.'; exit 0 ;; '
        ."*'image inspect'*) exit 1 ;; *pull*) exit ".($pull ? '0' : '1')." ;; esac\nexit 0\n",
    );

    $script = 'set -Eeuo pipefail; . '.escapeshellarg(Repo::file('infra/scripts/update.sh')).'; '
        .'kq_msg_init es; DOCKER_OK=1; TARGET_VERSION=2.2.1; SOURCE_VERSION=2.2.0; CHECKS_RUN=0; CHECKS_FAILED=0; CHECKS_WARNED=0; '
        .'CURRENT_ENV='.escapeshellarg($dir.'/.env').'; COMPOSE_FILE='.escapeshellarg($dir.'/docker-compose.yml').'; PACKAGE_DIR='.escapeshellarg($dir).'; '
        .'check_images; printf "fallos=%s\n" "${CHECKS_FAILED}"';
    $process = new Process(['bash', '-c', $script], env: ['PATH' => $path, 'KRONOQR_LANG' => 'es'], timeout: 60.0);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());

    return ['salida' => $process->getOutput(), 'docker' => (string) file_get_contents($dir.'/docker.log')];
}

it('update.sh sin internet, con la imagen cargada sin su digest: intenta descargar y luego nombra su variable (V1-PL-02)', function (): void {
    $resultado = updateConImagenes(pull: false, repoDigests: 0);

    expect($resultado['salida'])->toContain('fallos=1')
        ->and($resultado['docker'])->toContain('pull')
        ->and($resultado['salida'])->toContain('se cargo con \'docker load\'')
        ->and($resultado['salida'])->toContain('la linea vacia IMAGE_DIGEST_PHP=');
})->group('RF-PD-10');

it('update.sh con internet descarga por digest aunque haya etiquetas en local, con o sin digest de registro (V1-PL-02)', function (int $repoDigests): void {
    $resultado = updateConImagenes(pull: true, repoDigests: $repoDigests);

    expect($resultado['salida'])->toContain('fallos=0')
        ->and($resultado['salida'])->not->toContain('docker load');
})->with(['con digest' => [1], 'sin digest' => [0]])->group('RF-PD-10');
