<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TileProxyController extends Controller
{
    private function fetchAndCache(string $cachePath, string $upstreamUrl, array $headers = [], int $ttlSeconds = 604800)
    {
        // If cached and fresh, serve from cache
        if (Storage::disk('local')->exists($cachePath)) {
            $mtime = Storage::disk('local')->lastModified($cachePath);
            if ($mtime !== false && (time() - $mtime) < $ttlSeconds) {
                $bytes = Storage::disk('local')->get($cachePath);
                return response($bytes, 200, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        try {
            $resp = Http::withHeaders(array_merge([
                'User-Agent' => 'GliderChampionship/1.0',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                'Referer' => url('/'),
            ], $headers))->timeout(10)->get($upstreamUrl);
            if (!$resp->ok()) {
                return response('Upstream error', 502);
            }
            $body = $resp->body();
            Storage::disk('local')->put($cachePath, $body);
            return response($body, 200, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=86400',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Tile proxy error: '.$e->getMessage());
            // If stale cache exists, serve it
            if (Storage::disk('local')->exists($cachePath)) {
                $bytes = Storage::disk('local')->get($cachePath);
                return response($bytes, 200, [
                    'Content-Type' => 'image/png',
                    'Cache-Control' => 'public, max-age=3600',
                ]);
            }
            return response('Tile fetch failed', 502);
        }
    }

    public function osm(Request $request, int $z, int $x, int $y)
    {
        $ttl = (int) env('TILE_CACHE_TTL_SECONDS', 604800);
        // Use a single host to avoid subdomain logic
        $upstream = strtr('https://tile.openstreetmap.org/{z}/{x}/{y}.png', [
            '{z}' => $z,
            '{x}' => $x,
            '{y}' => $y,
        ]);
        $cachePath = sprintf('tiles/osm/%d/%d/%d.png', $z, $x, $y);
        return $this->fetchAndCache($cachePath, $upstream, [], $ttl);
    }

    public function openaip(Request $request, int $z, int $x, int $y)
    {
        $ttl = (int) env('TILE_CACHE_TTL_SECONDS', 604800);
        $apiKey = env('OPENAIP_API_KEY');
        $template = env('OPENAIP_TILES_URL', 'https://tiles.openaip.net/tiles/{z}/{x}/{y}.png?apiKey={API_KEY}');
        $upstream = strtr($template, [
            '{z}' => $z,
            '{x}' => $x,
            '{y}' => $y,
            '{API_KEY}' => urlencode((string) $apiKey),
        ]);
        if (!str_contains($template, '{API_KEY}')) {
            // Append if missing
            $upstream .= (str_contains($upstream, '?') ? '&' : '?') . 'apiKey=' . urlencode((string) $apiKey);
        }
        $cachePath = sprintf('tiles/openaip/%d/%d/%d.png', $z, $x, $y);
        return $this->fetchAndCache($cachePath, $upstream, [], $ttl);
    }
}


