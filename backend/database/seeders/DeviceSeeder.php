<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Dos quioscos por centro (RF-ID-04).
 *
 * Dos, y no uno, por RN-16: el transito minimo entre dispositivos solo se puede
 * probar con dos dispositivos, y el caso de «dos tablets contiguas en la entrada
 * de personal» es real, no un borde (doc 01 §4).
 *
 * `token_hash` queda a NULL: el emparejamiento y la emision del token son de la
 * tarea 1.5, y `Device` **no valida su propio token** (doc 01 §5.2). Sembrarlo
 * aqui seria inventar el formato de otro modulo.
 */
final class DeviceSeeder extends Seeder
{
    /** @var list<string> */
    private const array DEVICE_NAMES = ['Entrada de personal', 'Office de cocina'];

    public function run(): void
    {
        $now = now();

        /** @var list<object{id: int}> $sites */
        $sites = DB::table('sites')->select('id')->orderBy('id')->get()->all();

        foreach ($sites as $site) {
            foreach (self::DEVICE_NAMES as $name) {
                // `insertOrIgnore` y no `updateOrInsert`: el identificador
                // publico de un dispositivo ya emparejado no puede cambiar
                // porque alguien repita la semilla.
                DB::table('devices')->insertOrIgnore([
                    'uuid' => Str::uuid7()->toString(),
                    'site_id' => $site->id,
                    'name' => $name,
                    'app_version' => null,
                    'last_seen_at' => null,
                    'pending_queue_size' => 0,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
}
