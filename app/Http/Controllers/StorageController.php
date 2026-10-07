<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Blob;
use App\Models\User;
use App\Support\Assets;
use App\Support\BlobStorage;
use App\Support\Media;
use App\Support\RailsCrypto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StorageController extends Controller
{
    public function blob(Request $r, string $signed, string $filename)
    {
        $id = app(RailsCrypto::class)->verifyId($signed, 'ActiveStorage::Blob', 'blob_id');
        $blob = Blob::findOrFail($id);
        $path = app(BlobStorage::class)->path($blob);
        abort_unless(is_file($path), 404);
        // Installed ActiveStorage::Blob::Servable determines both MIME and disposition.
        $binary = in_array($blob->content_type, ['text/html', 'image/svg+xml', 'application/postscript', 'application/x-shockwave-flash', 'text/xml', 'application/xml', 'application/xhtml+xml', 'application/mathml+xml', 'text/cache-manifest']);
        $inline = in_array($blob->content_type, ['image/webp', 'image/avif', 'image/png', 'image/gif', 'image/jpeg', 'image/tiff', 'image/bmp', 'image/vnd.adobe.photoshop', 'image/vnd.microsoft.icon', 'application/pdf']);

        return response()->file($path, ['Content-Type' => $binary ? 'application/octet-stream' : ($blob->content_type ?? 'application/octet-stream'), 'Content-Disposition' => (! $binary && $inline && $r->input('disposition') !== 'attachment' ? 'inline' : 'attachment').'; filename="'.str_replace(['"', "\r", "\n"], '_', $blob->filename).'"', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function avatar(Request $r, string $user)
    {
        $id = app(RailsCrypto::class)->verifyId($user, 'User', 'avatar');
        $u = User::findOrFail($id);
        $blob = app(BlobStorage::class)->attached('User', $u->id, 'avatar');
        if ($blob && in_array($blob->content_type, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
            $path = app(Media::class)->variant($blob, ['resize_to_limit' => [512, 512], 'format' => 'webp']);

            return $this->cachedAvatar(response()->file($path, ['Content-Type' => 'image/webp', 'Content-Disposition' => 'inline']), $u, $r);
        }
        if ($u->role === 2) {
            return $this->cachedAvatar(response()->file(public_path(ltrim(app(Assets::class)->path('default-bot-avatar.svg'), '/')), ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'inline']), $u, $r);
        }
        $initials = implode('', array_map(fn ($s) => mb_substr($s, 0, 1), preg_split('/\s+/u', trim($u->name))));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"><rect width="100%" height="100%" rx="256" fill="#ddd"/><text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" font-size="180">'.htmlspecialchars($initials, ENT_QUOTES | ENT_XML1).'</text></svg>';

        return $this->cachedAvatar(response($svg)->header('Content-Type', 'image/svg+xml'), $u, $r);
    }

    private function cachedAvatar($response, User $user, Request $request)
    {
        $response->headers->set('Cache-Control', 'public, max-age=1800, stale-while-revalidate=604800');
        $response->setEtag(hash('sha256', $user->id.'-'.$user->getRawOriginal('updated_at')));
        $response->isNotModified($request);

        return $response;
    }

    public function representation(Request $r, string $signed, string $variation, string $filename)
    {
        $b = Blob::findOrFail(app(RailsCrypto::class)->verifyId($signed, 'ActiveStorage::Blob', 'blob_id'));
        $v = app(RailsCrypto::class)->appVerify($variation, 'variation');
        abort_unless(is_array($v), 404);
        $path = app(Media::class)->variant($b, $v);

        return response()->file($path, ['Content-Type' => 'image/'.($v['format'] ?? 'webp'), 'Cache-Control' => 'public, max-age=31536000', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function deleteAvatar(Request $r, string $user)
    {
        $r->user()->id;
        Attachment::where(['record_type' => 'User', 'record_id' => $r->user()->id, 'name' => 'avatar'])->delete();
        $r->user()->touch();

        return redirect('/users/me/profile');
    }

    public function deleteLogo(Request $r)
    {
        abort_unless($r->user()->role === 1, 403);
        Attachment::where('record_type', 'Account')->where('name', 'logo')->delete();

        return redirect('/account/edit');
    }

    public function directUpload(Request $r)
    {
        $a = $r->validate(['blob.filename' => 'required|string', 'blob.byte_size' => 'required|integer|min:0|max:104857600', 'blob.checksum' => 'required|string', 'blob.content_type' => 'nullable|string']);
        $b = Blob::create($a['blob'] + ['key' => bin2hex(random_bytes(14)), 'metadata' => '{}', 'service_name' => 'local', 'created_at' => now()]);
        $signed = app(RailsCrypto::class)->signedId($b->id, 'ActiveStorage::Blob', 'blob_id');
        $upload = app(RailsCrypto::class)->appSign(['key' => $b->key, 'content_type' => $b->content_type, 'content_length' => $b->byte_size, 'checksum' => $b->checksum, 'service_name' => 'local'], 'blob_token', now()->addMinutes(5)->format('Y-m-d\\TH:i:s.v\\Z'));

        return response()->json($b->toArray() + ['signed_id' => $signed, 'direct_upload' => ['url' => url('/rails/active_storage/disk/'.$upload), 'headers' => ['Content-Type' => $b->content_type, 'Content-MD5' => $b->checksum]]]);
    }

    public function disk(Request $r, string $signed)
    {
        $token = app(RailsCrypto::class)->appVerify($signed, 'blob_token');
        abort_unless(is_array($token) && ($token['service_name'] ?? '') === 'local', 404);
        $b = Blob::where('key', $token['key'] ?? null)->firstOrFail();
        $data = $r->getContent();
        abort_unless(strlen($data) === $token['content_length'] && hash_equals($token['checksum'], base64_encode(md5($data, true))), 422);
        $path = app(BlobStorage::class)->path($b);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        if (file_put_contents($path, $data) !== strlen($data)) {
            throw new \RuntimeException('Direct upload failed');
        }

        return response('', 204);
    }

    public function diskDownload(Request $r, string $signed, string $filename)
    {
        $token = app(RailsCrypto::class)->appVerify($signed, 'blob_key');
        abort_unless(is_array($token) && ($token['service_name'] ?? '') === 'local', 404);
        $b = Blob::where('key', $token['key'] ?? null)->firstOrFail();

        return $this->blob($r, app(RailsCrypto::class)->signedId($b->id, 'ActiveStorage::Blob', 'blob_id'), $filename);
    }

    public function logo()
    {
        $b = app(BlobStorage::class)->attached('Account', (int) DB::table('accounts')->value('id'), 'logo');
        if ($b) {
            return redirect(app(BlobStorage::class)->url($b));
        }

        return response()->file(public_path(ltrim(app(Assets::class)->path('campfire-icon.png'), '/')));
    }
}
