<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segundo factor de las cuentas de gestion (**RS-06**, RF-ID-01, tarea 2.1).
 *
 * La migracion que creo `users` ya anticipaba esto por escrito: *«el 2FA
 * obligatorio de RF-ID-01 y el ambito por departamento de RF-ID-03 llegan en la
 * tarea 2.1; sus columnas se anaden alli como expand»*. Esto es esa mitad. El
 * alcance por departamento no necesita columna: se resuelve por
 * `departments.manager_user_id`, que existe desde el esquema inicial.
 *
 * **Tres columnas y ninguna obligatoria.**
 *
 * - `two_factor_secret` — el secreto TOTP, **cifrado por la aplicacion** con el
 *   cast `encrypted` del modelo (`APP_KEY`). Es `text` y no `varchar(n)` porque
 *   lo que se almacena no es el secreto sino su criptograma, cuyo tamaño depende
 *   del cifrador: fijar una longitud aqui es como se acaba truncando una
 *   credencial el dia que se rota el algoritmo.
 * - `two_factor_confirmed_at` — nulo mientras el alta esta a medias. Un secreto
 *   sin confirmar **no autoriza nada**: es lo que permite repetir el alta cuando
 *   alguien cierra la pantalla del QR sin escanearlo, sin dejar la cuenta rota.
 * - `two_factor_last_slice` — la franja temporal del ultimo codigo aceptado. Es
 *   la unica proteccion contra reenvio que tiene TOTP: sin ella, un codigo visto
 *   por encima del hombro sirve durante el minuto siguiente. Se guarda la franja,
 *   nunca el codigo.
 *
 * **Expand puro y sin fase contract** (doc 02 §10.4): tres columnas nullable
 * sobre una tabla de decenas de filas. Ninguna restriccion nueva, asi que no hay
 * `ADD CONSTRAINT` que validar ni `ACCESS EXCLUSIVE` que dure mas que el propio
 * `ALTER`. Una version anterior de la aplicacion sigue funcionando con estas
 * columnas presentes —las ignora—, que es lo que hace segura la ventana de
 * despliegue.
 *
 * **`down()` verificado**: suelta las tres columnas y devuelve la tabla al
 * esquema anterior. Retirar la migracion **desactiva el segundo factor de todo el
 * mundo**, asi que solo tiene sentido como marcha atras inmediata de un
 * despliegue fallido y nunca como operacion rutinaria.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_secret')->nullable();
            $table->timestampTz('two_factor_confirmed_at', 6)->nullable();
            $table->bigInteger('two_factor_last_slice')->nullable();
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'two_factor_secret',
                'two_factor_confirmed_at',
                'two_factor_last_slice',
            ]);
        });
    }
};
