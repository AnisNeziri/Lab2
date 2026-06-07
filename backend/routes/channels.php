<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('company.{companyId}', function ($user, int $companyId) {
    return (int) $user->company_id === $companyId;
});
