<?php

namespace App\Support;

final class Assets
{
    private array $manifest;

    private ?string $head = null;

    public function __construct()
    {
        $this->manifest = json_decode(file_get_contents(public_path('assets/.manifest.json')), true) ?: [];
    }

    public function path(string $name): string
    {
        return '/assets/'.($this->manifest[$name] ?? $name);
    }

    public function head(): string
    {
        return $this->head ??= $this->buildHead();
    }

    private function buildHead(): string
    {
        $imports = json_decode(file_get_contents(resource_path('importmap.json')), true);
        foreach ($imports['imports'] as &$path) {
            if (str_starts_with($path, 'ASSET:')) {
                $path = $this->path(substr($path, 6));
            }
        }
        $s = '';
        foreach ($this->manifest as $logical => $digest) {
            if (str_ends_with($logical, '.css')) {
                $s .= '<link rel="stylesheet" href="'.e('/assets/'.$digest).'" data-turbo-track="reload">';
            }
        }

        return $s.'<script type="importmap">'.json_encode($imports, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script><script type="module">import "application"</script>';
    }
}
