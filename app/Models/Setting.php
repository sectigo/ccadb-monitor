<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{

    public function setLastCCADBRefreshNow(): void
    {

        if($this->where("key", "=", "last_ccadb_refresh")->exists()){
            $setting = $this->where("key", "=", "last_ccadb_refresh")->first();
        } else {
            $setting = new Setting();
            $setting->key = "last_ccadb_refresh";
        }
        $setting->value = now()->toDateTimeString();
        $setting->save();
    }
}
