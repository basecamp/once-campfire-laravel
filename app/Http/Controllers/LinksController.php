<?php

namespace App\Http\Controllers;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class LinksController extends Controller
{
    public function unfurl(Request $r)
    {
        $url = $r->validate(['url' => 'required|url:http,https'])['url'];
        $doc = null;
        for ($redirects = 0; $redirects < 5; $redirects++) {
            $u = parse_url($url);
            if (isset($u['user']) || isset($u['pass'])) {
                return response('', 204);
            }
            $host = $u['host'];
            $ips = gethostbynamel($host);
            if (! $ips) {
                return response('', 204);
            }
            foreach ($ips as $ip) {
                if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return response('', 204);
                }
            }
            try {
                $reply = Http::timeout(7)->connectTimeout(7)->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':'.($u['port'] ?? ($u['scheme'] === 'https' ? 443 : 80)).':'.$ips[0]]]])->get($url);
            } catch (\Throwable) {
                return response('', 204);
            }
            if ($reply->redirect()) {
                $location = $reply->header('Location');
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                continue;
            }
            if (! $reply->successful() || strlen($reply->body()) > 2 * 1024 * 1024) {
                return response('', 204);
            }
            $doc = new \DOMDocument;
            @$doc->loadHTML($reply->body(), LIBXML_NONET);
            break;
        }
        if (! $doc) {
            return response('', 204);
        }
        $values = [];
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            $key = $meta->getAttribute('property');
            if (str_starts_with($key, 'og:')) {
                $values[substr($key, 3)] = trim(strip_tags($meta->getAttribute('content')));
            }
        }
        if (empty($values['title']) || empty($values['description'])) {
            return response('', 204);
        }

        return response()->json(['title' => $values['title'], 'url' => $url, 'description' => $values['description'], 'image' => $values['image'] ?? null]);
    }

    public function manifest()
    {
        return response()->json(['name' => 'Campfire', 'short_name' => 'Campfire', 'start_url' => '/', 'display' => 'standalone', 'icons' => [['src' => '/account/logo?size=large', 'sizes' => '512x512', 'type' => 'image/png']]]);
    }

    public function worker()
    {
        return response(file_get_contents(resource_path('service-worker.js')))->header('Content-Type', 'application/javascript')->header('Service-Worker-Allowed', '/');
    }
}
