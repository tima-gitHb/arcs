<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the Passport Index dataset (SR-49). Read-only in the app:
 * only App\Services\EntryRuleImporter writes this table.
 */
class EntryRule extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'passport_country',
        'destination_country',
        'requirement',
    ];
}
