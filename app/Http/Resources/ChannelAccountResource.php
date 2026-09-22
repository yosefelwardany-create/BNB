<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Channels\Models\ChannelAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChannelAccount
 */
class ChannelAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'name' => $this->name,
            'status' => $this->status,
            'is_connected' => $this->isConnected(),

            // Deliberately absent: credentials and the webhook secret are
            // hidden on the model and never leave the server. Whether they
            // exist is reported instead, which is all a settings screen needs.
            'has_credentials' => ! blank($this->credentials),

            'external_account_id' => $this->external_account_id,

            'sync_availability' => (bool) $this->sync_availability,
            'sync_rates' => (bool) $this->sync_rates,
            'import_reservations' => (bool) $this->import_reservations,
            'export_reservations' => (bool) $this->export_reservations,
            'sync_messages' => (bool) $this->sync_messages,

            'commission_basis_points' => (int) $this->commission_basis_points,
            'commission_percent' => $this->commissionPercent(),

            // Decides whether a booking from here produces cash we hold or a
            // receivable from the channel. Wrong, it misstates the bank
            // balance by every booking they send.
            'collects_payment' => (bool) $this->collects_payment,

            'listings_count' => $this->whenCounted('listings'),
            'connected_at' => $this->connected_at?->toIso8601String(),
            'last_verified_at' => $this->last_verified_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            'last_imported_at' => $this->last_imported_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
