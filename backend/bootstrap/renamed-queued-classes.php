<?php

declare(strict_types=1);

use App\Modules\Attendance\Infrastructure\Queue\RenamedQueuedClasses;

/*
 * Cargado por `autoload.files` de Composer, en todos los procesos y antes de que
 * un worker deserialice nada: los nombres de antes de la 2.3.0 de las clases
 * que viajan en la cola. Se retira en la 2.4.0 junto con su entrada en
 * composer.json (vease RenamedQueuedClasses).
 */
RenamedQueuedClasses::register();
