<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaCertificateRecordDetail extends Model
{

    protected $fillable = ["field", "value", "ca_certificate_record_id"];
    public function caCertificateRecord(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CaCertificateRecord::class, 'ca_certificate_record_id');
    }
}
