<?php

declare(strict_types=1);

use Tests\Architecture\Support\ModuleTree;

/*
 * Ningun correo del producto se compone con la plantilla de Laravel
 * (R6-PL-07, V6-PL-05).
 *
 * `new MailMessage` sale con `notifications::email`, que pone `APP_NAME` en la
 * cabecera, la despedida y el pie, y «Regards,» en el idioma del proceso: la
 * marca del fabricante y en ingles en el correo que RRHH lee cada dia. El unico
 * sitio que construye un `MailMessage` es `BrandedMailMessage`, en
 * `Shared/Infrastructure/Notification/`; un correo nuevo empieza por
 * `BrandedMailMessage::create($brandName, $locale)`.
 *
 * Se recorre todo `app/` con `ModuleTree::phpFilesUnder()` (`scandir`, por el
 * bind mount), no solo `Modules/`: un correo puede nacer fuera de un modulo.
 */

const BRANDED_MAIL_ONLY_ALLOWED = 'Modules/Shared/Infrastructure/Notification/';

/**
 * Los ficheros de `app/` que construyen un `MailMessage`, relativos a `app/`.
 *
 * @return list<string>
 */
function brandedMailOnlyConstructors(): array
{
    // Un solo recorrido por ejecucion: `app/` entero sobre el bind mount tarda.
    /** @var list<string>|null $cached */
    static $cached = null;

    if (\is_array($cached)) {
        return $cached;
    }

    $app = \dirname(ModuleTree::root());
    $found = [];

    foreach (ModuleTree::phpFilesUnder($app) as $file) {
        $php = (string) file_get_contents($file);
        $found[] = preg_match('/new\s+\\\\?(?:Illuminate\\\\Notifications\\\\Messages\\\\)?MailMessage\b/', $php) === 1
            ? ModuleTree::relative($file, $app)
            : null;
    }

    return $cached = array_values(array_filter($found));
}

it('encuentra el unico sitio que construye un MailMessage', function (): void {
    // El control: sin el, la prueba de abajo pasaria tambien si el recorrido no
    // viera ningun fichero.
    expect(brandedMailOnlyConstructors())->toContain(BRANDED_MAIL_ONLY_ALLOWED.'BrandedMailMessage.php');
})->group('RF-PD-08');

it('no construye un MailMessage fuera de Shared/Infrastructure/Notification', function (): void {
    $outside = array_values(array_filter(
        brandedMailOnlyConstructors(),
        static fn (string $file): bool => ! str_starts_with($file, BRANDED_MAIL_ONLY_ALLOWED),
    ));

    expect($outside)->toBe(
        [],
        'Correo con la plantilla de Laravel en: '.implode(', ', $outside)
        .'. Usa BrandedMailMessage::create($brandName, $locale): lleva la marca de la instalacion '
        .'y la despedida en el idioma del destinatario (R6-PL-07, V6-PL-05).',
    );
})->group('RF-PD-08');
