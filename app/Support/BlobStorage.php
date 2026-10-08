<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Message;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Process\Process;

final class BlobStorage
{
    private array $pendingFiles = [];

    /**
     * Registered once by AppServiceProvider (not per instance: in a worker the event dispatcher
     * outlives every request, so per-instance listeners would pile up and keep old instances).
     */
    public static function listen(): void
    {
        Event::listen(TransactionRolledBack::class, function ($event) {
            if (app()->resolved(self::class)) {
                app(self::class)->rolledBack($event->connection->transactionLevel());
            }
        });
        Event::listen(TransactionCommitted::class, function ($event) {
            if ($event->connection->transactionLevel() === 0 && app()->resolved(self::class)) {
                app(self::class)->pendingFiles = [];
            }
        });
    }

    private function rolledBack(int $level): void
    {
        foreach ($this->pendingFiles as $id => $pending) {
            if ($pending['level'] > $level) {
                $this->deleteFiles($pending['blob']);
                unset($this->pendingFiles[$id]);
            }
        }
    }

    public function path(Blob $blob): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{8,}$/', $blob->key)) {
            throw new \RuntimeException('Invalid storage key');
        }

        return config('campfire.files').'/'.substr($blob->key, 0, 2).'/'.substr($blob->key, 2, 2).'/'.$blob->key;
    }

    public function url(Blob $blob): string
    {
        return '/rails/active_storage/blobs/redirect/'.app(RailsCrypto::class)->signedId($blob->id, 'ActiveStorage::Blob', 'blob_id').'/'.rawurlencode($blob->filename);
    }

    /**
     * Everything an attachment needs before the database: the upload written, analyzed and
     * thumbnailed (or the signed blob found and thumbnailed), so the write transaction that
     * attach() runs in holds the lock for rows alone. An upload's blob is returned unsaved; if
     * any step fails, its files are removed.
     */
    public function prepare(UploadedFile|string $source): Blob
    {
        if (is_string($source)) {
            $blob = Blob::findOrFail(app(RailsCrypto::class)->verifyId($source, 'ActiveStorage::Blob', 'blob_id'));
            $this->thumbnail($blob);

            return $blob;
        }
        $blob = $this->write($source);
        try {
            $this->thumbnail($blob);
        } catch (\Throwable $error) {
            $this->deleteFiles($blob);
            throw $error;
        }

        return $blob;
    }

    /** Attaches a prepare()d blob: inserts an upload's row, or checks a signed blob still exists. */
    public function attach(Message $message, Blob $blob): Blob
    {
        if ($blob->exists) {
            Blob::findOrFail($blob->id);
        } else {
            $this->insert($blob);
        }
        $message->attachment()->delete();
        Attachment::create(['name' => 'attachment', 'record_type' => 'Message', 'record_id' => $message->id, 'blob_id' => $blob->id, 'created_at' => now()]);

        return $blob;
    }

    private function thumbnail(Blob $blob): void
    {
        if (in_array($blob->content_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf', 'video/mp4', 'video/webm'])) {
            app(Media::class)->variant($blob, ['resize_to_limit' => [1200, 800], 'format' => $this->thumbnailFormat($blob)]);
        }
    }

    public function deleteFiles(Blob $blob): void
    {
        $path = $this->path($blob);
        if (is_file($path)) {
            unlink($path);
        }
        $directory = config('campfire.files').'/variants/'.$blob->key;
        if (is_dir($directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($directory);
        }
    }

    public function purgeUnreferenced(Blob $blob): void
    {
        if (! Attachment::where('blob_id', $blob->id)->exists()) {
            DB::table('active_storage_variant_records')->where('blob_id', $blob->id)->delete();
            $blob->delete();
            $this->deleteFiles($blob);
        }
    }

    public function store(UploadedFile $source): Blob
    {
        $blob = $this->write($source);
        $this->insert($blob);

        return $blob;
    }

    /** Writes and analyzes an upload into an unsaved blob; on failure its file is removed. */
    private function write(UploadedFile $source): Blob
    {
        $data = file_get_contents($source->getRealPath());
        $blob = new Blob(['key' => bin2hex(random_bytes(14)), 'filename' => basename($source->getClientOriginalName()), 'content_type' => $source->getMimeType(), 'metadata' => '{}', 'service_name' => 'local', 'byte_size' => strlen($data), 'checksum' => base64_encode(md5($data, true)), 'created_at' => now()]);
        try {
            $blob->metadata = json_encode($this->analyze($blob, $data));
        } catch (\Throwable $error) {
            $this->deleteFiles($blob);
            throw $error;
        }

        return $blob;
    }

    /** Writes the upload's file and returns the metadata Active Storage's analyzers record. */
    private function analyze(Blob $blob, string $data): array
    {
        $path = $this->path($blob);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (file_put_contents($path, $data) !== strlen($data)) {
            throw new \RuntimeException('Upload write failed');
        }
        $metadata = ['identified' => true, 'analyzed' => true];
        if (str_starts_with($blob->content_type ?? '', 'image/')) {
            $size = getimagesize($path);
            if ($size) {
                $metadata['width'] = $size[0];
                $metadata['height'] = $size[1];
            }
        }
        if (str_starts_with($blob->content_type ?? '', 'video/') || str_starts_with($blob->content_type ?? '', 'audio/')) {
            $probe = new Process(['ffprobe', '-v', 'error', '-protocol_whitelist', 'file,pipe', '-show_streams', '-show_format', '-of', 'json', $path]);
            $probe->setTimeout(20);
            $probe->mustRun();
            $information = json_decode($probe->getOutput(), true);
            $metadata['duration'] = (float) ($information['format']['duration'] ?? 0);
            foreach ($information['streams'] ?? [] as $stream) {
                if (($stream['codec_type'] ?? '') === 'video') {
                    $metadata['width'] = $stream['width'] ?? null;
                    $metadata['height'] = $stream['height'] ?? null;
                    $metadata['video'] = true;
                } elseif (($stream['codec_type'] ?? '') === 'audio') {
                    $metadata['audio'] = true;
                }
            }
        }

        return $metadata;
    }

    /** Inserts a written blob's row; inside a transaction, a rollback removes its files. */
    private function insert(Blob $blob): void
    {
        $blob->created_at = now();
        try {
            $blob->save();
        } catch (\Throwable $error) {
            $this->deleteFiles($blob);
            throw $error;
        }
        if (DB::transactionLevel() > 0) {
            $this->pendingFiles[$blob->id] = ['blob' => $blob, 'level' => DB::transactionLevel()];
        }
    }

    public function attachTo(string $type, int $id, string $name, UploadedFile $source): Blob
    {
        $blob = $this->store($source);
        Attachment::where(['record_type' => $type, 'record_id' => $id, 'name' => $name])->delete();
        Attachment::create(['record_type' => $type, 'record_id' => $id, 'name' => $name, 'blob_id' => $blob->id, 'created_at' => now()]);
        $table = match ($type) {
            'User' => 'users', 'Account' => 'accounts', default => null
        };
        if ($table) {
            DB::table($table)->where('id', $id)->update(['updated_at' => now()]);
        }

        return $blob;
    }

    public function thumbnailFormat(Blob $blob): string
    {
        return match ($blob->content_type) {
            'image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/gif' => 'png', default => 'webp'
        };
    }

    public function representationUrl(Blob $blob, array $variation): string
    {
        return '/rails/active_storage/representations/redirect/'.app(RailsCrypto::class)->signedId($blob->id, 'ActiveStorage::Blob', 'blob_id').'/'.app(RailsCrypto::class)->appSign($variation, 'variation').'/'.rawurlencode($blob->filename);
    }

    public function attached(string $type, int $id, string $name): ?Blob
    {
        return Attachment::where(['record_type' => $type, 'record_id' => $id, 'name' => $name])->with('blob')->first()?->blob;
    }
}
