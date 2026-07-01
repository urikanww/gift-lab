<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization (Reverb)
|--------------------------------------------------------------------------
| Tenancy is enforced here: a buyer may only subscribe to their own company's
| channel; staff channels require an internal staff role. These guards mirror
| QuotePolicy so realtime access can never exceed HTTP access.
*/

// Buyer's company channel: quote + proof status pushes.
Broadcast::channel('company.{companyId}', function (User $user, int $companyId): bool {
    return $user->isStaff() || $user->company_id === $companyId;
});

// Floor operators: shared production queue.
Broadcast::channel('staff.queue', function (User $user): bool {
    return $user->isStaff();
});

// Procurement desk: awaiting-reconfirm alerts.
Broadcast::channel('staff.procurement', function (User $user): bool {
    return $user->isStaff();
});
