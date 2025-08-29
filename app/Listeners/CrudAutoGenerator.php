<?php

namespace App\Listeners;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use App\Services\Crud\CrudScaffolder;

class CrudAutoGenerator
{
    public function handle(CommandFinished $event): void
    {
        if ($event->command !== 'make:model' || $event->exitCode !== 0) {
            return;
        }

        $nameArg = $event->input?->getArgument('name');   // e.g. App/Models/Product atau Product
        if (!$nameArg) return;

        // Normalisasi model name & table name
        $class = Str::studly(class_basename($nameArg));     // Product
        $table = Str::snake(Str::pluralStudly($class));     // products

        // Pastikan tabel ada (migrasi sudah dijalankan)
        if (! Schema::hasTable($table)) {
            $this->warn("Tabel '{$table}' tidak ditemukan. Jalankan migrasi terlebih dahulu.");
            return;
        }

        // Jalankan scaffolder
        app(CrudScaffolder::class)->generate($class, $table);
    }

    protected function warn(string $msg): void
    {
        // biar muncul di console artisan
        fwrite(STDERR, "\n[CRUD Generator] " . $msg . "\n");
    }
}
