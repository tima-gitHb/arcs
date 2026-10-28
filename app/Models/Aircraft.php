<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Aircraft extends Model
{
    protected $table = 'aircraft';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'registration',
        'model',
    ];

    public function seats(): HasMany
    {
        return $this->hasMany(Seat::class);
    }

    public function flights(): HasMany
    {
        return $this->hasMany(Flight::class);
    }
}
