<?php

namespace App\Support;

use App\Models\Blob;
use Symfony\Component\Process\Process;

final class Media
{
    public function variant(Blob $blob, array $variation): string
    {
        $size = $variation['resize_to_limit'] ?? [1200, 800];
        if (! is_array($size) || count($size) !== 2) {
            abort(422);
        }[$w,$h] = array_map('intval', $size);
        abort_unless($w > 0 && $h > 0 && $w <= 4096 && $h <= 4096, 422);
        $format = $variation['format'] ?? 'webp';
        abort_unless(in_array($format, ['webp', 'png', 'jpeg']), 422);
        $source = app(BlobStorage::class)->path($blob);
        abort_unless(is_file($source), 404);
        $key = hash('sha256', json_encode([$blob->key, $w, $h, $format]));
        $directory = config('campfire.files').'/variants/'.$blob->key;
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        $path = $directory.'/'.$key.'.'.$format;
        $lock = fopen($path.'.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            if (is_file($path)) {
                return $path;
            }
            $temporary = $path.'.tmp.'.$format;
            if (str_starts_with($blob->content_type ?? '', 'video/')) {
                $preview = $path.'.source.png';
                $p = new Process(['ffmpeg', '-nostdin', '-y', '-protocol_whitelist', 'file,pipe', '-i', $source, '-vf', 'thumbnail,scale='.$w.':'.$h.':force_original_aspect_ratio=decrease', '-frames:v', '1', $preview]);
                $p->setTimeout(20);
                $p->mustRun();
                $source = $preview;
            } elseif (! in_array($blob->content_type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'application/pdf'])) {
                abort(422);
            }
            $p = new Process(['vips', 'thumbnail', $source, $temporary, (string) $w, '--height', (string) $h, '--size', 'down']);
            $p->setTimeout(20);
            $p->mustRun();
            rename($temporary, $path);
            if (isset($preview)) {
                unlink($preview);
            }

            return $path;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
