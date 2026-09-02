<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

/**
 * La ruta nombra un paso del asistente que no esta en el catalogo (RF-PD-03).
 *
 * Es `404` en el borde y no `422`: el paso no existe, asi que el recurso
 * direccionado tampoco. Se distingue de {@see SetupStepNotRecordable}, que es el
 * caso en que el paso **si** existe y lo que no existe es la potestad de
 * declararlo hecho a mano.
 */
final class UnknownSetupStep extends ProductDomainException
{
    public const string TRANSLATION_KEY = 'setup.unknown_step';

    public readonly string $translationKey;

    /** @var array<string, string|int> */
    public readonly array $parameters;

    public function __construct(string $step)
    {
        $this->translationKey = self::TRANSLATION_KEY;
        $this->parameters = ['step' => $step];

        parent::__construct('Setup step "'.$step.'" is not part of the start-up wizard.');
    }
}
