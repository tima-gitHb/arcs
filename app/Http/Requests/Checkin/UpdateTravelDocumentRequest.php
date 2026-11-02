<?php

namespace App\Http\Requests\Checkin;

/**
 * The "Edit document" form on the check-in screen: same rules as adding one.
 */
class UpdateTravelDocumentRequest extends StoreTravelDocumentRequest
{
    protected $errorBag = 'editDocument';
}
