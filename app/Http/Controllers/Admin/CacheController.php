<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Purge des caches Laravel depuis l'administration.
 *
 * Indispensable sur un hébergement sans accès SSH : après un déploiement par
 * FTP, un cache de routes obsolète masque les nouvelles routes et les vues
 * compilées conservent les anciennes URL.
 */
class CacheController extends Controller
{
    /**
     * Commandes proposées, dans l'ordre d'exécution.
     *
     * config:cache est volontairement absent de la reconstruction : plusieurs
     * env() sont lus hors des fichiers de configuration et deviendraient nuls
     * (voir DEPLOIEMENT.md §6).
     */
    private const CLEAR_COMMANDS = [
        'view:clear'   => 'Vues compilées',
        'route:clear'  => 'Cache des routes',
        'config:clear' => 'Cache de configuration',
        'cache:clear'  => 'Cache applicatif',
    ];

    private const REBUILD_COMMANDS = [
        'view:cache'  => 'Vues compilées',
        'route:cache' => 'Cache des routes',
    ];

    public function index()
    {
        return view('admin.cache.index', ['status' => $this->status()]);
    }

    public function clear(Request $request)
    {
        return $this->run(self::CLEAR_COMMANDS, 'Caches vidés');
    }

    public function rebuild(Request $request)
    {
        return $this->run(self::REBUILD_COMMANDS, 'Caches regénérés');
    }

    /**
     * @param array<string, string> $commands
     */
    private function run(array $commands, string $title)
    {
        $report = [];

        foreach ($commands as $command => $label) {
            try {
                Artisan::call($command);
                $report[] = $label . ' : ok';
            } catch (\Throwable $ex) {
                $report[] = $label . ' : échec — ' . $ex->getMessage();
            }
        }

        return redirect()
            ->route('admin.cache.index')
            ->with('success', $title . ' — ' . implode(' · ', $report));
    }

    /**
     * État courant des caches, pour comprendre un comportement inattendu
     * sans avoir à fouiller le serveur en FTP.
     *
     * @return array<string, array{label: string, active: bool, detail: string}>
     */
    private function status(): array
    {
        $compiled = glob(storage_path('framework/views/*.php')) ?: [];

        return [
            'routes' => [
                'label'  => 'Cache des routes',
                'active' => file_exists(base_path('bootstrap/cache/routes-v7.php')),
                'detail' => 'Tant qu\'il est présent, une route ajoutée reste invisible (erreur 404).',
            ],
            'config' => [
                'label'  => 'Cache de configuration',
                'active' => file_exists(base_path('bootstrap/cache/config.php')),
                'detail' => 'À laisser absent : certaines variables sont lues via env() hors configuration.',
            ],
            'views' => [
                'label'  => 'Vues compilées',
                'active' => $compiled !== [],
                'detail' => count($compiled) . ' fichier(s). Une vue modifiée mais non recompilée garde son ancien contenu.',
            ],
        ];
    }
}
