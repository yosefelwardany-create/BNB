<?php

declare(strict_types=1);

namespace App\Domain\Websites\Jobs;

use App\Domain\Websites\Models\WebsiteShopifyOrder;
use App\Domain\Websites\Services\ShopifyCheckouts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes back the Shopify order of a hold nobody paid for, so its checkout
 * link stops working. Retried while the store cannot be reached; a payment
 * that still slips through is handled when Shopify reports it.
 */
class DeleteShopifyDraftOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $orderId)
    {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(ShopifyCheckouts $shopify): void
    {
        $order = WebsiteShopifyOrder::query()->find($this->orderId);

        if ($order !== null && $order->status === WebsiteShopifyOrder::STATUS_OPEN) {
            $shopify->delete($order);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::warning('A lapsed hold\'s Shopify order could not be deleted; its checkout link may still work.', [
            'website_shopify_order_id' => $this->orderId,
            'error' => $e->getMessage(),
        ]);
    }
}
