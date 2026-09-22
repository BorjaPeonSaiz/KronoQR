<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Console;

use App\Modules\Identity\Application\UseCase\ResetManagementPasswordHandler;
use Illuminate\Console\Command;

/**
 * `php artisan identity:reset-password` — sustituye la contrasena de una cuenta
 * de gestion (RS-06, OWASP A07).
 *
 * **Por que existe.** El producto no tenia ninguna forma de rotar la contrasena
 * de una cuenta ya creada: ni pantalla, ni endpoint, ni comando. Ante una
 * contrasena comprometida —o simplemente olvidada— la unica salida era crear
 * **otra cuenta**, que es lo peor que puede hacerse con un registro horario: dos
 * identidades para la misma persona parten en dos la respuesta a «¿quien
 * corrigio esta jornada?». Es la otra mitad del hallazgo H-03 de la revision
 * interna ASVS de 2026-09.
 *
 * **La contrasena la genera el comando y se enseña UNA vez.** No se pide por
 * consola como en `identity:create-user`, y la diferencia tiene motivo: alli la
 * elige su titular, que esta delante; aqui la fija otra persona para un tercero,
 * y una contrasena elegida por quien no la va a usar acaba siendo la misma en las
 * cuatro instalaciones que atiende ese tecnico. Generada, cumple la politica de
 * RF-ID-01 por construccion y nadie tiene que pensarla.
 *
 * **Y no hay recuperacion por correo** (regla dura 12, ADR-015): el producto no
 * depende del correo de nadie y la instalacion puede no tener salida a internet
 * (ADR-016). La contrasena se entrega **en mano**, igual que la tarjeta.
 *
 * **Se identifica por correo**, por lo mismo que `identity:deactivate-user`: es
 * el identificador de acceso y el que el cliente tiene en su lista. La direccion
 * no sale de la busqueda y no entra en el asiento (regla dura 21).
 *
 * **El segundo factor no se toca.** Si ademas hay que retirarlo, es
 * `identity:2fa-reset`, que es otro hecho y deja otro asiento. Juntarlos aqui
 * convertiria este comando en «devuelveme la cuenta entera de esta persona» en
 * una sola orden, que es precisamente lo que conviene que cueste dos.
 */
final class ResetManagementPasswordCommand extends Command
{
    /**
     * Longitud minima de la contrasena generada.
     *
     * Por encima del minimo de la politica (RF-ID-01, configurable) a proposito:
     * esta contrasena no la elige una persona, asi que no hay ningun coste en
     * hacerla mas larga, y la unica vez que alguien tiene que teclearla es al
     * entrar por primera vez.
     */
    private const int GENERATED_LENGTH = 20;

    /**
     * Las cuatro clases que exige la politica de RF-ID-01, **sin caracteres que
     * se confunden al leerlos de una pantalla y teclearlos a mano**: fuera `l`,
     * `I`, `O`, `0` y `1`. Se entrega en persona y de viva voz, asi que la
     * ambiguedad se paga en llamadas al servicio tecnico, no en seguridad.
     *
     * @var list<string>
     */
    private const array POOLS = [
        'abcdefghijkmnopqrstuvwxyz',
        'ABCDEFGHJKLMNPQRSTUVWXYZ',
        '23456789',
        '!#$%&*+-=?@',
    ];

    protected $signature = 'identity:reset-password
        {email : Correo de la cuenta, que es su identificador de acceso}';

    protected $description = 'Genera una contrasena nueva para una cuenta de gestion (RS-06).';

    public function handle(ResetManagementPasswordHandler $handler): int
    {
        // El argumento es obligatorio en la firma: Symfony rechaza la llamada sin
        // el antes de llegar aqui, asi que solo queda estrechar el tipo.
        $email = trim((string) $this->argument('email'));

        $password = $this->generatedPassword();

        if (! $handler->handle($email, $password)) {
            $this->components->error('No existe ninguna cuenta de gestion activa con ese correo.');

            return self::FAILURE;
        }

        $this->components->info('Contrasena restablecida. Las sesiones abiertas de esa cuenta han dejado de valer.');

        // La UNICA vez que esta contrasena se puede leer: lo que se guarda es su
        // hash, asi que no hay forma de volver a enseñarla. Va sola en su linea
        // para que se pueda copiar sin arrastrar nada mas.
        $this->newLine();
        $this->line('  '.$password);
        $this->newLine();

        $this->components->warn(
            'Anotala ahora: no se puede volver a consultar. Se entrega en mano, nunca por correo '
            .'ni por mensajeria, y quien la reciba deberia usarla solo para entrar.'
        );

        return self::SUCCESS;
    }

    /**
     * Una contrasena que cumple la politica de RF-ID-01 **por construccion**.
     *
     * Primero un caracter de cada clase y despues el relleno: un muestreo
     * uniforme sobre el alfabeto entero puede no dar ni una mayuscula en veinte
     * tiradas, y entonces la contrasena generada no pasaria la propia politica
     * que el producto exige al fijarla.
     *
     * La longitud es el mayor entre {@see self::GENERATED_LENGTH} y el minimo
     * configurado, porque ese minimo es configuracion del cliente (regla dura 13)
     * y podria ser mayor.
     */
    private function generatedPassword(): string
    {
        $length = max(self::GENERATED_LENGTH, config()->integer('identity.password.min_length'));

        $characters = array_map(
            // `random_int` y nunca `rand()` ni `mt_rand()`: Mersenne Twister es
            // predecible a partir de unas pocas salidas, y esto es una credencial.
            static fn (string $pool): string => $pool[random_int(0, \strlen($pool) - 1)],
            self::POOLS,
        );

        $alphabet = implode('', self::POOLS);

        while (\count($characters) < $length) {
            $characters[] = $alphabet[random_int(0, \strlen($alphabet) - 1)];
        }

        return self::shuffled($characters);
    }

    /**
     * Baraja con `random_int` y no con `shuffle()`.
     *
     * `shuffle()` usa el generador rapido de PHP, que no es criptografico; y sin
     * barajar, las cuatro primeras posiciones serian siempre minuscula,
     * mayuscula, digito y simbolo, que es un patron que reduce el trabajo de
     * quien intente adivinarla.
     *
     * Devuelve ya la cadena y no el array porque no hay ningun otro uso para la
     * lista barajada: sacarla de aqui solo daria una ocasion mas de que una
     * contrasena en claro acabe en una variable con nombre.
     *
     * @param  list<string>  $characters
     */
    private static function shuffled(array $characters): string
    {
        for ($i = \count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);

            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }
}
