<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Consortium extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'ccp_key', 'name', 'email', 'is_active', 'enable_harvesting'
    ];
    protected $casts =['id'=>'integer', 'is_active'=>'integer', 'enable_harvesting'=>'integer'];

    public function ingests()
    {
        return $this->hasMany('App\HarvestLog');
    }
}
