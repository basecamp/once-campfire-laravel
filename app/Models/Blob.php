<?php

namespace App\Models;

final class Blob extends Record
{
    protected $table = 'active_storage_blobs';

    public $timestamps = false;

    public function variantRecords()
    {
        return $this->hasMany(VariantRecord::class, 'blob_id');
    }
}
