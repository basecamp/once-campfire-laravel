<?php

namespace App\Support;

use App\Models\Blob;
use App\Models\User;

final class RichTextRenderer
{
    private \HTMLPurifier $purifier;

    private \HTMLPurifier $storagePurifier;

    /** Purifiers are stateless between calls and expensive to build, so each worker keeps one pair. */
    private static ?\HTMLPurifier $sharedPurifier = null;

    private static ?\HTMLPurifier $sharedStoragePurifier = null;

    /** Attachment records resolved during this request or job. */
    private array $records = [];

    public function __construct()
    {
        $this->purifier = self::$sharedPurifier ??= self::purifier(false);
        $this->storagePurifier = self::$sharedStoragePurifier ??= self::purifier(true);
    }

    private static function purifier(bool $storage): \HTMLPurifier
    {
        $make = function (bool $storage) {
            $c = \HTMLPurifier_Config::createDefault();
            $allowed = 'div,p,br,strong,b,em,i,u,s,del,mark,blockquote,pre,code[data-language],ul,ol,li,a[href|title|target],span[class|sgid],h1,h2,h3,h4,h5,h6,table,thead,tbody,tfoot,tr,th,td,hr,img[src|alt|width|height]';
            if ($storage) {
                $allowed .= ',action-text-attachment[sgid|content-type|url|href|filename|filesize|width|height|previewable|presentation|caption|description]';
            }
            $c->set('HTML.Allowed', $allowed);
            $c->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $c->set('Cache.SerializerPath', storage_path('framework/cache'));
            $c->set('HTML.DefinitionID', $storage ? 'campfire-storage' : 'campfire-display');
            $c->set('HTML.DefinitionRev', 3);
            if ($def = $c->maybeGetRawHTMLDefinition()) {
                $def->addAttribute('span', 'sgid', 'Text');
                $def->addAttribute('code', 'data-language', 'Text');
                $def->addElement('mark', 'Inline', 'Inline', 'Common');
                if ($storage) {
                    $def->addElement('action-text-attachment', 'Inline', 'Empty', 'Common', ['sgid' => 'Text', 'content-type' => 'Text', 'url' => 'URI', 'href' => 'URI', 'filename' => 'Text', 'filesize' => 'Text', 'width' => 'Text', 'height' => 'Text', 'previewable' => 'Text', 'presentation' => 'Text', 'caption' => 'Text', 'description' => 'Text']);
                }
            }

            return new \HTMLPurifier($c);
        };

        return $make($storage);
    }

    public function storage(string $body): string
    {
        return $this->storagePurifier->purify($body);
    }

    public function html(string $body): string
    {
        return $this->purifier->purify($this->attachments($body));
    }

    public function mentions(string $body): array
    {
        $ids = [];
        $this->attachments($body, $ids);

        return array_values(array_unique($ids));
    }

    private function attachments(string $body, ?array &$mentions = null, bool $plain = false): string
    {
        return preg_replace_callback('~<action-text-attachment\b([^>]*)>(?:</action-text-attachment>)?~i', function ($m) use (&$mentions, $plain) {
            $doc = new \DOMDocument;
            @$doc->loadHTML('<?xml encoding="UTF-8"><body>'.$m[0].'</body>', LIBXML_NONET);
            $node = $doc->getElementsByTagName('action-text-attachment')->item(0);
            if (! $node) {
                return '';
            }
            $sgid = $node->getAttribute('sgid');
            $gid = app(RailsCrypto::class)->verifySgid($sgid);
            if ($gid && $gid['model'] === 'User') {
                $u = $this->find(User::class, $gid['id']);
                if (! $u) {
                    return '';
                }
                if ($mentions !== null) {
                    $mentions[] = $u->id;
                }
                if ($plain) {
                    return e('@'.$u->name);
                }

                return '<span class="mention" sgid="'.e($sgid).'">'.e($u->name).'</span>';
            }
            if ($gid && $gid['model'] === 'ActiveStorage::Blob') {
                $b = $this->find(Blob::class, $gid['id']);
                if (! $b) {
                    return '';
                }

                return '<a href="'.e(app(BlobStorage::class)->url($b)).'">'.e($b->filename).'</a>';
            }

            if ($node->getAttribute('content-type') === 'application/vnd.actiontext.opengraph-embed') {
                $title = $node->getAttribute('filename');
                $href = $node->getAttribute('href');
                $description = $node->getAttribute('description');
                $image = $node->getAttribute('url');
                if ($plain) {
                    return e($title.' '.$description);
                }

                return '<div class="og-embed attachment attachment--og"><a href="'.e($href).'" target="_blank" rel="noreferrer">'.e(mb_substr($title, 0, 280)).'</a><div class="og-embed__description">'.e(mb_substr($description, 0, 560)).'</div>'.($image ? '<img src="'.e($image).'" alt="">' : '').'</div>';
            }

            return e($node->getAttribute('caption') ?: $node->getAttribute('filename'));
        }, $body);
    }

    /**
     * @template T of User|Blob
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    private function find(string $model, int $id): User|Blob|null
    {
        if (! array_key_exists($key = $model.':'.$id, $this->records)) {
            $this->records[$key] = $model::find($id);
        }

        return $this->records[$key];
    }

    public function plain(string $body): string
    {
        $unused = null;

        return trim(html_entity_decode(strip_tags(preg_replace('~</(?:div|p|li|blockquote)>|<br\s*/?>~i', "\n", $this->attachments($body, $unused, true))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
