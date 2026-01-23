<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaCertificateRecord extends Model
{

    protected $fillable = ["ca_owner", "certificate_name", "ccadb_record_id"];
    public function caCertificateRecordDetails(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CaCertificateRecordDetail::class, 'ca_certificate_record_id');
    }

    public function detailsMap(): array
    {
        if (empty($this->detailsCache)) {
            // Ensure relation is loaded once; key by `field` and take `value`
            $this->loadMissing('details');
            $this->detailsCache = $this->details
                ->mapWithKeys(fn ($d) => [$d->field => $d->value])
                ->all();
        }
        return $this->detailsCache;
    }

    public function detail(string $field, ?string $default = null): ?string
    {
        $map = $this->detailsMap();
        return array_key_exists($field, $map) ? $map[$field] : $default;
    }

    public function getAttribute($key)
    {
        // First, try normal Eloquent attributes/accessors
        $value = parent::getAttribute($key);
        if ($value !== null || $this->hasGetMutator($key) || $this->getCasts()[$key] ?? false) {
            return $value;
        }
        // Fallback to dynamic details
        return $this->detail($key);
    }

}
