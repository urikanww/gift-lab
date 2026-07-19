<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Same shape as creating a saved address; the controller applies the owner
 * policy check for updates.
 */
class UpdateSavedAddressRequest extends StoreSavedAddressRequest
{
}
