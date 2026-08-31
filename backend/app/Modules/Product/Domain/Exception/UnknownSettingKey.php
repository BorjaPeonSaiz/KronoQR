<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\Exception;

/**
 * Alguien pide escribir o leer una clave que el catalogo no declara (RF-PD-01).
 *
 * **Falla cerrado en la escritura, y esto es deliberado.** Aceptar una clave
 * desconocida en un `PATCH` produciria una fila que nadie lee: el cliente creeria
 * haber configurado algo —un umbral, un idioma— y el sistema seguiria aplicando
 * el valor de serie. Un ajuste que se guarda y no hace nada es peor que un 422.
 *
 * **En la lectura no se lanza.** Una fila con una clave que este binario no
 * conoce solo puede venir de una version posterior o de una edicion a mano, y no
 * puede alterar el comportamiento de nadie porque nadie la consulta: la cascada
 * la ignora y la anota en `ResolvedSettings::$unknownKeys` para que
 * `product:doctor` la enseñe. Hacerla fatal dejaria sin fichar a un centro por
 * una fila sobrante.
 *
 * **El mensaje es tecnico y en ingles**; el texto que ve una persona sale de
 * {@see self::$translationKey} y lo resuelve el borde HTTP en el idioma
 * negociado, igual que en {@see InvalidSettingValue}.
 */
final class UnknownSettingKey extends ProductDomainException
{
    public const string TRANSLATION_KEY = 'settings.unknown_key';

    public readonly string $translationKey;

    /** @var array<string, string|int> */
    public readonly array $parameters;

    public function __construct(string $key)
    {
        $this->translationKey = self::TRANSLATION_KEY;
        $this->parameters = ['key' => $key];

        parent::__construct(
            'Setting key "'.$key.'" is not in the installation catalogue. '
            .'The catalogue is SettingKey: a key that is not there is read by nobody.',
        );
    }
}
