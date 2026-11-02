<?php

declare(strict_types=1);

namespace App\Domain\Websites\Models;

use App\Support\Models\BaseModel;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-off Shopify order (a draft order) made for a stay or its balance:
 * one line at the price Habitat worked out, paid on the store's checkout.
 *
 * @property string $id
 * @property string $website_checkout_id
 * @property string $purpose
 * @property string $draft_order_id
 * @property string|null $draft_order_name
 * @property string $invoice_url
 * @property string|null $order_id
 * @property string|null $order_name
 * @property int $amount
 * @property string $currency
 * @property string $status
 */
class WebsiteShopifyOrder extends BaseModel
{
    /** The stay: in full, or its deposit. */
    public const PURPOSE_STAY = 'stay';

    public const PURPOSE_BALANCE = 'balance';

    public const STATUS_OPEN = 'open';

    public const STATUS_PAID = 'paid';

    public const STATUS_DELETED = 'deleted';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'website_checkout_id', 'purpose', 'draft_order_id', 'draft_order_name', 'invoice_url',
        'order_id', 'order_name', 'amount', 'currency', 'status',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<WebsiteCheckout, $this>
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(WebsiteCheckout::class, 'website_checkout_id');
    }

    public function money(): Money
    {
        return Money::of($this->amount, $this->currency);
    }
}
