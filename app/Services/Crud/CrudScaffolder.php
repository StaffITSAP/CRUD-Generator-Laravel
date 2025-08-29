<?php

namespace App\Services\Crud;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CRUD Scaffolder kompatibel Doctrine DBAL (jika ada) + fallback MySQL INFORMATION_SCHEMA.
 * - Tanpa named args (compatible PHP 8.x)
 * - Repository menulis relasi sebagai array PHP (bukan JSON)
 * - Request rules otomatis dari tipe kolom migrasi
 */
class CrudScaffolder
{
    public function __construct(protected Filesystem $files) {}

    public function generate(string $model, string $table): void
    {
        $schema    = $this->getSchemaManager();             // null jika doctrine tidak ada
        $columns   = $this->describeTable($schema, $table);
        $relations = $this->detectRelations($schema, $table);

        // Patch Model (fillable, casts, SoftDeletes, HasFactory, relations)
        $this->patchModel($model, $table, $columns, $relations);

        // Context untuk stub
        $ctx = $this->context($model, $table, $columns, $relations);

        // ===== Tulis file dari stub =====
        $this->putFromStub('resource.stub',       "app/Http/Resources/{$model}Resource.php", $ctx);

        // Request (auto rules)
        $this->putFromStub('request.store.stub',  "app/Http/Requests/{$model}/Simpan{$model}Request.php", $ctx, function ($tpl) use ($columns) {
            return str_replace('{{rules_store}}', $this->buildRulesStore($columns), $tpl);
        });
        $this->putFromStub('request.update.stub', "app/Http/Requests/{$model}/Ubah{$model}Request.php",   $ctx, function ($tpl) use ($columns) {
            return str_replace('{{rules_update}}', $this->buildRulesUpdate($columns), $tpl);
        });

        // Repository (embed array PHP)
        $this->putFromStub('repository.stub', "app/Repositories/{$model}Repository.php", $ctx, function ($tpl) use ($relations, $table) {
            $tpl = str_replace('{{relations_array}}', var_export($relations, true), $tpl);
            return str_replace('{{table}}', $table, $tpl);
        });

        $this->putFromStub('service.stub',        "app/Services/{$model}Layanan.php", $ctx);
        $this->putFromStub('service.custom.stub', "app/Services/{$model}LayananKustom.php", $ctx, null, false);
        $this->putFromStub('policy.stub',         "app/Policies/{$model}Policy.php", $ctx);
        $this->putFromStub('controller.stub',     "app/Http/Controllers/Api/V1/{$model}Controller.php", $ctx);
        $this->putFromStub('trait.query.stub',    "app/Http/Controllers/Api/V1/Concerns/MenyusunQueryDinamis.php", $ctx, null, false);
        $this->putFromStub('export.excel.stub',   "app/Exports/{$model}Export.php", $ctx);
        $this->putFromStub('export.pdf.view.stub', "resources/views/exports/table.blade.php", $ctx, null, false);
        $this->putFromStub('tests.feature.stub',  "tests/Feature/Api/V1/{$model}ControllerTest.php", $ctx);

        // Tambah routes
        $this->appendRoutes($model);
    }

    /** Ambil SchemaManager doctrine bila ada, null kalau tidak tersedia. */
    protected function getSchemaManager(): ?object
    {
        $conn = DB::connection();

        if (method_exists($conn, 'getDoctrineConnection')) {
            $doctrine = $conn->getDoctrineConnection();
            if (method_exists($doctrine, 'createSchemaManager')) {
                return $doctrine->createSchemaManager(); // DBAL 3/4
            }
            if (method_exists($conn, 'getDoctrineSchemaManager')) {
                return $conn->getDoctrineSchemaManager(); // lama
            }
        }
        return null;
    }

    /** Deskripsi kolom. */
    protected function describeTable($schemaManager, string $table): array
    {
        if ($schemaManager && method_exists($schemaManager, 'listTableColumns')) {
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
        return $this->mysqlColumns($table);
    }

    /** Fallback MySQL: kolom dari INFORMATION_SCHEMA. */
    protected function mysqlColumns(string $table): array
    {
        $db = DB::getDatabaseName();
        $rows = DB::select("
            SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION
        ", [$db, $table]);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'name'    => $r->COLUMN_NAME,
                'type'    => (string) $r->DATA_TYPE,
                'notnull' => strtoupper($r->IS_NULLABLE) === 'NO',
                'default' => $r->COLUMN_DEFAULT,
            ];
        }
        return $out;
    }

    /** Deteksi relasi belongsTo. */
    protected function detectRelations($schemaManager, string $table): array
    {
        if ($schemaManager && method_exists($schemaManager, 'listTableForeignKeys')) {
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
                    $tbl = $fk->getForeignTable();
                    if (is_object($tbl)) {
                        $foreignTable = method_exists($tbl, 'getName') ? $tbl->getName() : (string) $tbl;
                    }
                }

                foreach ($localCols as $col) {
                    $base    = Str::before($col, '_id');
                    $relName = Str::camel($base ?: $col);
                    $model   = Str::studly(Str::singular($foreignTable));

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

        return $this->mysqlForeignKeys($table);
    }

    /** Fallback MySQL: FK → belongsTo. */
    protected function mysqlForeignKeys(string $table): array
    {
        $db = DB::getDatabaseName();
        $rows = DB::select("
            SELECT COLUMN_NAME AS local_column, REFERENCED_TABLE_NAME AS referenced_table
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY ORDINAL_POSITION
        ", [$db, $table]);

        $rels = [];
        foreach ($rows as $r) {
            $col  = $r->local_column;
            $fTbl = $r->referenced_table;
            $base = Str::before($col, '_id');
            $rels[] = [
                'type'          => 'belongsTo',
                'name'          => Str::camel($base ?: $col),
                'model'         => Str::studly(Str::singular($fTbl)),
                'foreign_table' => $fTbl,
                'local_key'     => $col,
            ];
        }
        return $rels;
    }

    /** Patch Model: HasFactory, SoftDeletes (jika deleted_at), fillable, casts, belongsTo. */
    protected function patchModel(string $model, string $table, array $columns, array $relations): void
    {
        $path = app_path("Models/{$model}.php");
        if (!$this->files->exists($path)) return;

        $content = $this->files->get($path);

        // HasFactory
        if (!str_contains($content, 'use Illuminate\\Database\\Eloquent\\Factories\\HasFactory;')) {
            $content = preg_replace(
                '/^<\?php\s+namespace\s+App\\\Models;$/m',
                "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;",
                $content,
                1
            );
        }
        if (!str_contains($content, 'HasFactory')) {
            $content = preg_replace('/\{\s*$/m', "{\n    use HasFactory;\n", $content, 1);
        }

        // SoftDeletes jika ada kolom deleted_at
        $hasDeletedAt = collect($columns)->firstWhere('name', 'deleted_at') !== null;
        if ($hasDeletedAt && !str_contains($content, 'use Illuminate\\Database\\Eloquent\\SoftDeletes;')) {
            $content = preg_replace(
                '/^<\?php\s+namespace\s+App\\\Models;$/m',
                "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;",
                $content,
                1
            );
        }
        if ($hasDeletedAt && !str_contains($content, 'SoftDeletes')) {
            $content = preg_replace('/\{\s*$/m', "{\n    use SoftDeletes;\n", $content, 1);
        }

        // Fillable & Casts
        $sensitive = config('generator.sensitive', []);
        $fillable = collect($columns)->pluck('name')
            ->reject(function ($n) use ($sensitive) {
                return in_array($n, array_merge(['id', 'created_at', 'updated_at', 'deleted_at'], $sensitive));
            })->values()->all();

        $casts = [];
        foreach ($columns as $c) {
            $t = $c['type'];
            $n = $c['name'];
            if (str_contains($t, 'int')) $casts[$n] = 'integer';
            elseif (str_contains($t, 'bool')) $casts[$n] = 'boolean';
            elseif (str_contains($t, 'datetime') || $n === 'email_verified_at' || $t === 'timestamp' || $t === 'date') $casts[$n] = 'datetime';
            elseif (str_contains($t, 'json')) $casts[$n] = 'array';
            elseif (str_contains($t, 'decimal') || str_contains($t, 'float') || str_contains($t, 'double') || str_contains($t, 'real')) $casts[$n] = 'float';
        }

        if (!str_contains($content, 'protected $fillable')) {
            $content = preg_replace('/\{\s*$/m', "{\n    protected \$fillable = " . var_export($fillable, true) . ";\n", $content, 1);
        }
        if (!str_contains($content, 'protected $casts')) {
            $content = preg_replace('/\{\s*$/m', "{\n    protected \$casts = " . var_export($casts, true) . ";\n", $content, 1);
        }

        // Tambah relasi belongsTo jika belum ada
        foreach ($relations as $r) {
            $method = $r['name'];
            if (!str_contains($content, "function {$method}(")) {
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
            'param'     => Str::camel($model), // untuk authorizeResource
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
     * @param null|callable(string):string $mutator
     */
    protected function putFromStub(string $stub, string $target, array $ctx, ?callable $mutator = null, bool $overwrite = true): void
    {
        $stubPath = base_path("stubs/dynamic/{$stub}");
        if (!$this->files->exists($stubPath)) return;
        if (!$overwrite && $this->files->exists(base_path($target))) return;

        $tpl = $this->files->get($stubPath);
        if ($mutator !== null) {
            $tpl = $mutator($tpl);
        }

        $rendered = $this->render($tpl, $ctx);
        $this->ensureDir($target);
        $this->files->put(base_path($target), $rendered);
    }

    protected function ensureDir(string $target): void
    {
        $dir = dirname(base_path($target));
        if (!$this->files->isDirectory($dir)) {
            $this->files->makeDirectory($dir, 0755, true);
        }
    }

    protected function render(string $tpl, array $ctx): string
    {
        // render {{key}} saja (token rules/relations sudah diganti via mutator)
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($m) use ($ctx) {
            return $ctx[$m[1]] ?? '';
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

        $content = file_get_contents($routeFile);
        if (!str_contains($content, $marker)) {
            $ver = config('generator.api_version', 'v1');
            $content .= "\n\nRoute::prefix('{$ver}')->group(function () {\n{$marker}\n});\n";
        }
        if (!str_contains($content, "\\App\\Http\\Controllers\\Api\\V1\\{$model}Controller")) {
            $content = str_replace($marker, $line . $marker, $content);
            file_put_contents($routeFile, $content);
        }
    }

    // ===================== Helpers: Validation Rules =====================

    protected function buildRulesStore(array $columns): string
    {
        $rules = $this->makeRules($columns, true);
        return $this->rulesToPhpArray($rules);
        // menghasilkan string seperti: "'name' => ['required','string','max:255'],\n 'price' => ['required','numeric']"
    }

    protected function buildRulesUpdate(array $columns): string
    {
        $rules = $this->makeRules($columns, false);
        return $this->rulesToPhpArray($rules);
    }

    protected function makeRules(array $columns, bool $isStore): array
    {
        $excluded = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        $out = [];
        foreach ($columns as $c) {
            $name = $c['name'];
            if (in_array($name, $excluded)) continue;

            $type = strtolower((string) $c['type']);
            $notnull = (bool) $c['notnull'];
            $hasDefault = !is_null($c['default']);

            $r = [];

            if ($isStore) {
                if ($notnull && !$hasDefault) {
                    $r[] = 'required';
                } else {
                    $r[] = 'nullable';
                }
            } else {
                $r[] = 'sometimes';
            }

            // tipe dasar
            if (str_contains($type, 'int')) {
                $r[] = 'integer';
            } elseif ($type === 'boolean' || $type === 'tinyint' || $type === 'bool') {
                $r[] = 'boolean';
            } elseif (in_array($type, ['decimal', 'float', 'double', 'real', 'numeric'])) {
                $r[] = 'numeric';
            } elseif (in_array($type, ['date', 'datetime', 'timestamp'])) {
                $r[] = 'date';
            } elseif ($type === 'json') {
                $r[] = 'array';
            } else {
                $r[] = 'string';
                // opsional: max 255 utk nama umum
                if (preg_match('/name|title|slug|email|username/i', $name)) {
                    $r[] = 'max:255';
                }
            }

            $out[$name] = $r;
        }
        return $out;
    }

    protected function rulesToPhpArray(array $rules): string
    {
        // Ubah ke string PHP array untuk disuntikkan ke stub
        $lines = [];
        foreach ($rules as $field => $arr) {
            $arrPhp = implode("','", array_map(fn($s) => str_replace("'", "\\'", $s), $arr));
            $lines[] = "            '{$field}' => ['{$arrPhp}'],";
        }
        return implode("\n", $lines);
    }
}
