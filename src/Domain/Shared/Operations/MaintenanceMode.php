<?php

declare(strict_types=1);

namespace Domain\Shared\Operations;

interface MaintenanceMode
{
    /** A missing marker means up; unreadable, unsafe or malformed state pauses admission. */
    public function status(): MaintenanceState;

    public function down(int $retryAfter = 300): MaintenanceState;

    public function up(): MaintenanceState;
}
