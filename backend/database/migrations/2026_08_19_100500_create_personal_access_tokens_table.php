<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `personal_access_tokens` — tokens de Sanctum (doc 01 §5.5).
 *
 * Estructura la del paquete (`vendor/laravel/sanctum/database/migrations/`),
 * escrita aqui para que el esquema del producto este completo en un solo sitio
 * y para poder fijar los tipos que este proyecto exige: `TIMESTAMPTZ` en las
 * cuatro marcas, que es la regla dura 3, en lugar de los `timestamp` sin zona
 * del paquete.
 *
 * Aqui viven los **tokens de dispositivo** de RF-ID-04: el quiosco se autentica
 * con uno de ambito restringido —solo fichaje y sincronizacion—, revocable
 * individualmente y rotable. El ambito viaja en `abilities` y se comprueba
 * ademas del rol (doc 02 §7.3); la emision y la revocacion son de la tarea 1.5.
 *
 * `expires_at` esta indexada porque la purga de tokens caducados y la
 * comprobacion de vigencia consultan por ella.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name', 190);

            // El token nunca se guarda en claro: esta columna es su hash.
            $table->string('token', 64)->unique('personal_access_tokens_token_unique');

            $table->text('abilities')->nullable();
            $table->timestampTz('last_used_at', 6)->nullable();
            $table->timestampTz('expires_at', 6)->nullable()->index('personal_access_tokens_expires_at_index');
            $table->timestampsTz(6);
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('personal_access_tokens');
    }
};
