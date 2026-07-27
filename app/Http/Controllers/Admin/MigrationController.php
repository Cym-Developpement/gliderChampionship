<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Exécution des migrations depuis l'administration.
 *
 * Sur un hébergement sans accès SSH, une migration livrée par FTP resterait
 * sinon inappliquée : les écrans qui dépendent de la nouvelle table échouent
 * sans que rien n'indique pourquoi.
 */
class MigrationController extends Controller
{
    public function index()
    {
        return view('admin.migrations.index', [
            'pending'    => $this->pending(),
            'applied'    => $this->applied(),
            'connection' => config('database.default'),
            'backupPath' => $this->sqlitePath() !== null ? 'storage/app/private/backups' : null,
        ]);
    }

    public function run(Request $request)
    {
        $pending = $this->pending();

        if ($pending === []) {
            return redirect()->route('admin.migrations.index')
                ->with('success', 'Aucune migration en attente.');
        }

        $notes = [];

        // Sauvegarde préalable : une migration ne se rejoue pas à l'envers sans
        // risque, et l'utilisateur n'a pas d'accès shell pour restaurer.
        $backup = $this->backup();
        if ($backup !== null) {
            $notes[] = 'Sauvegarde : ' . $backup;
        }

        try {
            Artisan::call('migrate', ['--force' => true]);
            $output = trim(Artisan::output());
        } catch (\Throwable $ex) {
            return redirect()->route('admin.migrations.index')
                ->with('error', 'Échec des migrations : ' . $ex->getMessage()
                    . ($backup ? ' — sauvegarde disponible : ' . $backup : ''));
        }

        $count = count($pending);
        $notes[] = $count . ' migration(s) appliquée(s)';

        return redirect()->route('admin.migrations.index')
            ->with('success', implode(' · ', $notes))
            ->with('output', $output);
    }

    // ─── Interne ─────────────────────────────────────────────────────────────

    /**
     * Migrations présentes sur le disque mais absentes de la table migrations.
     * Comparaison directe plutôt que l'analyse de la sortie de migrate:status,
     * dont le format n'est pas un contrat stable.
     *
     * @return list<string>
     */
    private function pending(): array
    {
        $files = $this->files();

        if (!Schema::hasTable('migrations')) {
            return $files;   // base vierge : tout reste à appliquer
        }

        $ran = DB::table('migrations')->pluck('migration')->all();

        return array_values(array_diff($files, $ran));
    }

    /** @return list<array{name: string, batch: int}> */
    private function applied(): array
    {
        if (!Schema::hasTable('migrations')) {
            return [];
        }

        return DB::table('migrations')
            ->orderByDesc('batch')
            ->orderByDesc('id')
            ->limit(10)
            ->get(['migration', 'batch'])
            ->map(fn ($row) => ['name' => $row->migration, 'batch' => (int) $row->batch])
            ->all();
    }

    /** @return list<string> */
    private function files(): array
    {
        $paths = glob(database_path('migrations/*.php')) ?: [];
        $names = array_map(fn ($path) => basename($path, '.php'), $paths);
        sort($names);

        return $names;
    }

    /** Chemin du fichier SQLite, ou null pour les autres connexions. */
    private function sqlitePath(): ?string
    {
        if (config('database.default') !== 'sqlite') {
            return null;
        }

        $path = config('database.connections.sqlite.database');

        return is_string($path) && $path !== ':memory:' && is_file($path) ? $path : null;
    }

    /** Copie horodatée de la base SQLite avant migration. */
    private function backup(): ?string
    {
        $source = $this->sqlitePath();
        if ($source === null) {
            return null;   // MySQL : la sauvegarde relève de l'hébergeur
        }

        $dir = storage_path('app/private/backups');
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return null;
        }

        $name = 'database-' . date('Ymd-His') . '.sqlite';
        if (!@copy($source, $dir . '/' . $name)) {
            return null;
        }

        return $name;
    }
}
