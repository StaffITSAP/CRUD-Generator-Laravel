<?php

namespace App\Services\Crud;

use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CRUD Scaffolder yang kompatibel dengan beberapa versi Doctrine DBAL.
 * - Memakai createSchemaManager() bila ada (DBAL >= 3.2), fallback ke getDoctrineSchemaManager()
 * - Mengambil info foreign key via helper agar lolos deprecation (getLocalColumns/getForeignTableName)
 */
class CrudScaffolder
{
    public function __construct(
        protected Filesystem $files
    ) {}

    public function generate(string $model, string $table): void
    {
        $schema   = $this->getSchemaManager();
        $columns  = $this->describeTable($schema, $table);
        $relations = $this->detectRelations($schema, $table);

        // 1) Patch Model (fillable, casts, soft delete, relasi)
        $this->patchModel($model, $table, $columns, $relations);

        // 2) Buat file2 lain dari stub
        $ctx = $this->context($model, $table, $columns, $relations);
        $this->putFromStub('resource.stub',      "app/Http/Resources/{$model}Resource.php", $ctx);
        $this->putFromStub('request.store.stub', "app/Http/Requests/{$model}/Simpan{$model}Request.php", $ctx);
        $this->putFromStub('request.update.stub', "app/Http/Requests/{$model}/Ubah{$model}Request.php", $ctx);
        $this->putFromStub('repository.stub',    "app/Repositories/{$model}Repository.php", $ctx, fn($s) => $this->replaceJson($s, 'relations', $relations));
        $this->putFromStub('service.stub',       "app/Services/{$model}Layanan.php", $ctx);
        $this->putFromStub('service.custom.stub', "app/Services/{$model}LayananKustom.php", $ctx, overwrite: false);
        $this->putFromStub('policy.stub',        "app/Policies/{$model}Policy.php", $ctx);
        $this->putFromStub('controller.stub',    "app/Http/Controllers/Api/V1/{$model}Controller.php", $ctx);
        $this->putFromStub('trait.query.stub',   "app/Http/Controllers/Api/V1/Concerns/MenyusunQueryDinamis.php", $ctx, overwrite: false);
        $this->putFromStub('export.excel.stub',  "app/Exports/{$model}Export.php", $ctx);
        $this->putFromStub('export.pdf.view.stub', "resources/views/exports/table.blade.php", $ctx, overwrite: false);
        $this->putFromStub('tests.feature.stub', "tests/Feature/Api/V1/{$model}ControllerTest.php", $ctx);

        // 3) Tambah routes
        $this->appendRoutes($model);
    }

    /**
     * Ambil SchemaManager dengan API modern (DBAL 3/4) atau fallback.
     */
    protected function getSchemaManager(): ?object
    {
        /** @var \Illuminate\Database\Connection $conn */
        $conn = DB::connection();

        // Jika doctrine tersedia, gunakan itu
        if (method_exists($conn, 'getDoctrineConnection')) {
            $doctrine = $conn->getDoctrineConnection();
            if (method_exists($doctrine, 'createSchemaManager')) {
                return $doctrine->createSchemaManager(); // DBAL 3/4
            }
            if (method_exists($conn, 'getDoctrineSchemaManager')) {
                return $conn->getDoctrineSchemaManager(); // fallback lama
            }
        }

        // Tidak ada doctrine → pakai fallback (return null)
        return null;
    }

    /**
     * Deskripsi kolom tabel.
     */
    protected function describeTable($schemaManager, string $table): array
    {
        // Jika doctrine ada, gunakan listTableColumns
        if ($schemaManager !== null && method_exists($schemaManager, 'listTableColumns')) {
            $cols = [];
            foreach ($schemaManager->listTableColumns($table) as $col) {
                $cols[] = [
                    'name'    => $col->getName(),
                    'type'    => (string) $col->getType(),
                    'notnull' => $col->getNotnull(),
                    'default' => $col->getDefault(),
                ];
            }
            return $cols;
        }

        // Fallback MySQL: pakai INFORMATION_SCHEMA
        return $this->mysqlColumns($table);
    }

    /**
     * Ambil deskripsi kolom dari INFORMATION_SCHEMA (MySQL).
     */
    protected function mysqlColumns(string $table): array
    {
        $database = DB::getDatabaseName();

        $rows = DB::select("
        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION
    ", [$database, $table]);

        $cols = [];
        foreach ($rows as $r) {
            $cols[] = [
                'name'    => $r->COLUMN_NAME,
                'type'    => (string) $r->DATA_TYPE,
                'notnull' => strtoupper($r->IS_NULLABLE) === 'NO',
                'default' => $r->COLUMN_DEFAULT,
            ];
        }
        return $cols;
    }

    /**
     * Ambil foreign key dari INFORMATION_SCHEMA (MySQL) dan mapping ke belongsTo.
     */
    protected function mysqlForeignKeys(string $table): array
    {
        $database = DB::getDatabaseName();

        $rows = DB::select("
        SELECT
            kcu.COLUMN_NAME          AS local_column,
            kcu.REFERENCED_TABLE_NAME AS referenced_table
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
        WHERE
            kcu.TABLE_SCHEMA = ?
            AND kcu.TABLE_NAME = ?
            AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY kcu.ORDINAL_POSITION
    ", [$database, $table]);

        $rels = [];
        foreach ($rows as $r) {
            $col  = $r->local_column;
            $fTbl = $r->referenced_table;

            $base    = \Illuminate\Support\Str::before($col, '_id');
            $relName = \Illuminate\Support\Str::camel($base ?: $col);
            $model   = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($fTbl));

            $rels[] = [
                'type'          => 'belongsTo',
                'name'          => $relName,
                'model'         => $model,
                'foreign_table' => $fTbl,
                'local_key'     => $col,
            ];
        }

        return $rels;
    }

    /**
     * Deteksi relasi belongsTo dari foreign key secara aman (tanpa method deprecated).
     */
    protected function detectRelations($schemaManager, string $table): array
    {
        // Doctrine tersedia → gunakan listTableForeignKeys
        if ($schemaManager !== null && method_exists($schemaManager, 'listTableForeignKeys')) {
            $rels = [];
            foreach ($schemaManager->listTableForeignKeys($table) as $fk) {
                $localCols = method_exists($fk, 'getUnquotedLocalColumns')
                    ? $fk->getUnquotedLocalColumns()
                    : (method_exists($fk, 'getLocalColumns') ? $fk->getLocalColumns() : []);

                $foreignTable = '';
                if (method_exists($fk, 'getUnqualifiedForeignTableName')) {
                    $foreignTable = $fk->getUnqualifiedForeignTableName();
                } elseif (method_exists($fk, 'getForeignTableName')) {
                    $foreignTable = $fk->getForeignTableName();
                } elseif (method_exists($fk, 'getForeignTable')) {
                    $tblObj = $fk->getForeignTable();
                    if (is_object($tblObj)) {
                        $foreignTable = method_exists($tblObj, 'getName') ? $tblObj->getName() : (string) $tblObj;
                    }
                }

                foreach ($localCols as $col) {
                    $base    = \Illuminate\Support\Str::before($col, '_id');
                    $relName = \Illuminate\Support\Str::camel($base ?: $col);
                    $model   = \Illuminate\Support\Str::studly(\Illuminate\Support\Str::singular($foreignTable));

                    $rels[] = [
                        'type'          => 'belongsTo',
                        'name'          => $relName,
                        'model'         => $model,
                        'foreign_table' => $foreignTable,
                        'local_key'     => $col,
                    ];
                }
            }
            return $rels;
        }

        // Fallback MySQL
        return $this->mysqlForeignKeys($table);
    }


    /**
     * Helper kompatibel: ambil kolom lokal FK tanpa trigger deprecation.
     */
    protected function fkGetLocalColumns(object $fk): array
    {
        // Prioritas: unquoted (stabil di DBAL 3/4) -> getLocalColumns() -> getColumns()
        if (method_exists($fk, 'getUnquotedLocalColumns')) {
            return (array) $fk->getUnquotedLocalColumns();
        }
        if (method_exists($fk, 'getLocalColumns')) {
            return (array) $fk->getLocalColumns();
        }
        if (method_exists($fk, 'getColumns')) { // lama
            return (array) $fk->getColumns();
        }
        // fallback aman
        return [];
    }

    /**
     * Helper kompatibel: ambil nama tabel referensi FK tanpa trigger deprecation.
     */
    protected function fkGetForeignTableName(object $fk): string
    {
        // DBAL 3/4 biasanya punya salah satu dari ini:
        if (method_exists($fk, 'getUnqualifiedForeignTableName')) {
            return (string) $fk->getUnqualifiedForeignTableName();
        }
        if (method_exists($fk, 'getForeignTableName')) {
            return (string) $fk->getForeignTableName();
        }
        // Beberapa versi expose Table object:
        if (method_exists($fk, 'getForeignTable')) {
            $tbl = $fk->getForeignTable(); // Table|Identifier
            if (is_object($tbl)) {
                // coba property/name umum
                if (method_exists($tbl, 'getName')) return (string) $tbl->getName();
                if (property_exists($tbl, 'name'))   return (string) $tbl->name;
                if (method_exists($tbl, '__toString')) return (string) $tbl;
            }
        }
        return '';
    }

    protected function patchModel(string $model, string $table, array $columns, array $relations): void
    {
        $path = app_path("Models/{$model}.php");
        if (! $this->files->exists($path)) return;

        $content = $this->files->get($path);

        // SoftDeletes jika ada deleted_at
        $usesSoftDeletes = collect($columns)->firstWhere('name', 'deleted_at') !== null;
        if ($usesSoftDeletes && ! str_contains($content, 'SoftDeletes')) {
            if (! str_contains($content, 'use Illuminate\\Database\\Eloquent\\SoftDeletes;')) {
                $content = preg_replace(
                    '/^<\?php\s+namespace\s+App\\\Models;$/m',
                    "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;",
                    $content,
                    1
                );
            }
            $content = preg_replace('/\{\s*$/m', "{\n    use SoftDeletes;\n", $content, 1);
        }

        // Fillable otomatis (kecualikan field sensitif & timestamps & PK)
        $sensitive = config('generator.sensitive', []);
        $fillable = collect($columns)->pluck('name')
            ->reject(fn($n) => in_array($n, array_merge(['id', 'created_at', 'updated_at', 'deleted_at'], $sensitive)))
            ->values()->all();

        // Cast sederhana
        $casts = [];
        foreach ($columns as $c) {
            $t = $c['type'];
            $n = $c['name'];
            if (str_contains($t, 'int'))        $casts[$n] = 'integer';
            elseif (str_contains($t, 'bool'))   $casts[$n] = 'boolean';
            elseif (str_contains($t, 'datetime') || $n === 'email_verified_at') $casts[$n] = 'datetime';
            elseif (str_contains($t, 'json'))   $casts[$n] = 'array';
            elseif (str_contains($t, 'decimal') || str_contains($t, 'float') || str_contains($t, 'real') || str_contains($t, 'double')) $casts[$n] = 'float';
        }

        if (! str_contains($content, 'protected $fillable')) {
            $content = preg_replace('/\{\s*$/m', "{\n    protected \$fillable = " . var_export($fillable, true) . ";\n", $content, 1);
        }
        if (! str_contains($content, 'protected $casts')) {
            $content = preg_replace('/\{\s*$/m', "{\n    protected \$casts = " . var_export($casts, true) . ";\n", $content, 1);
        }

        // Tambah relasi belongsTo bila belum ada
        foreach ($relations as $r) {
            $method = $r['name'];
            if (! str_contains($content, "function {$method}(")) {
                $rel = <<<PHP

    public function {$method}()
    {
        return \$this->belongsTo(\\App\\Models\\{$r['model']}::class, '{$r['local_key']}');
    }

PHP;
                $content = preg_replace('/}\s*$/', $rel . "}\n", $content);
            }
        }

        $this->files->put($path, $content);
    }

    protected function context(string $model, string $table, array $columns, array $relations): array
    {
        return [
            'model'     => $model,
            'var'       => Str::camel($model),
            'table'     => $table,
            'route'     => Str::kebab(Str::pluralStudly($model)),
            'version'   => config('generator.api_version', 'v1'),
            'columns'   => array_map(fn($c) => $c['name'], $columns),
            'relations' => $relations,
            'sensitive' => config('generator.sensitive', []),
        ];
    }

    /**
     * Tulis file dari stub.
     * @param  null|callable(string):string  $mutator  Opsional: memodifikasi konten stub sebelum render
     */
    protected function putFromStub(string $stub, string $target, array $ctx, ?callable $mutator = null, bool $overwrite = true): void
    {
        $stubPath = base_path("stubs/dynamic/{$stub}");
        if (! $this->files->exists($stubPath)) return;
        if (! $overwrite && $this->files->exists(base_path($target))) return;

        $tpl = $this->files->get($stubPath);
        if ($mutator !== null) {
            $tpl = $mutator($tpl);
        }

        $rendered = $this->render($tpl, $ctx);
        $this->ensureDir($target);
        $this->files->put(base_path($target), $rendered);
    }

    protected function replaceJson(string $tpl, string $key, mixed $value): string
    {
        // Ganti token {{key|json}} di stub menjadi JSON string
        return str_replace('{{' . $key . '|json}}', json_encode($value, JSON_UNESCAPED_SLASHES), $tpl);
    }

    protected function ensureDir(string $target): void
    {
        $dir = dirname(base_path($target));
        if (! $this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }
    }

    protected function render(string $tpl, array $ctx): string
    {
        return preg_replace_callback('/\{\{\s*([\w\|]+)\s*\}\}/', function ($m) use ($ctx) {
            $key = $m[1];
            if (str_contains($key, '|')) {
                // token khusus sudah ditangani sebelumnya (|json), biarkan jika masih ada
                return $m[0];
            }
            return $ctx[$key] ?? '';
        }, $tpl);
    }

    protected function appendRoutes(string $model): void
    {
        $routeFile = base_path('routes/api.php');
        $marker    = config('generator.route_marker', '// [crud-generator] tambahkan-di-bawah');

        $resource = Str::kebab(Str::pluralStudly($model));
        $line =
            "    Route::apiResource('{$resource}', \\App\\Http\\Controllers\\Api\\V1\\{$model}Controller::class)\n" .
            "        ->middleware(['auth:sanctum','throttle:api-dinamis']);\n" .
            "    Route::get('{$resource}/export', [\\App\\Http\\Controllers\\Api\\V1\\{$model}Controller::class,'export'])\n" .
            "        ->middleware(['auth:sanctum','throttle:api-dinamis']);\n" .
            "    Route::put('{$resource}/{id}/restore', [\\App\\Http\\Controllers\\Api\\V1\\{$model}Controller::class,'pulihkan'])\n" .
            "        ->middleware(['auth:sanctum','throttle:api-dinamis']);\n";
        // Tambahan opsional di appendRoutes (jika diinginkan)
        $line .= "    Route::get('" . Str::kebab(Str::pluralStudly($model)) . "/export', [\\App\\Http\\Controllers\\Api\\V1\\{$model}Controller::class,'export'])"
            . "->middleware(['auth:sanctum','throttle:api-dinamis']);\n";
        $line .= "    Route::put('" . Str::kebab(Str::pluralStudly($model)) . "/{id}/restore', [\\App\\Http\\Controllers\\Api\\V1\\{$model}Controller::class,'pulihkan'])"
            . "->middleware(['auth:sanctum','throttle:api-dinamis']);\n";


        $content = file_get_contents($routeFile);

        if (! str_contains($content, $marker)) {
            $ver = config('generator.api_version', 'v1');
            $content .= "\n\nRoute::prefix('{$ver}')->group(function () {\n{$marker}\n});\n";
        }

        if (! str_contains($content, "\\App\\Http\\Controllers\\Api\\V1\\{$model}Controller")) {
            $content = str_replace($marker, $line . $marker, $content);
            file_put_contents($routeFile, $content);
        }
    }
}
