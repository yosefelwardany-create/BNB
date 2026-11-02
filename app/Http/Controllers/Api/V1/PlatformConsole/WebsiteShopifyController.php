<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

use App\Domain\Integrations\Shopify\ShopifyClient;
use App\Domain\Websites\Models\Website;
use App\Domain\Websites\Services\ShopifyInstall;
use App\Domain\Websites\Services\WebsiteAdministration;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebsiteResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A booking site's client Shopify store: the link that installs the
 * platform's app on it, and forgetting it.
 */
class WebsiteShopifyController extends Controller
{
    public function __construct(
        private readonly ShopifyInstall $install,
        private readonly ShopifyClient $shopify,
        private readonly WebsiteAdministration $sites,
    ) {}

    public function installLink(Request $request, Website $website): JsonResponse
    {
        abort_unless($this->shopify->configured(), 422, 'Add SHOPIFY_CLIENT_ID and SHOPIFY_CLIENT_SECRET to connect a store.');

        $request->merge(['shop' => strtolower(trim((string) $request->input('shop')))]);

        $data = $request->validate([
            'shop' => ['required', 'string', 'max:255', 'regex:'.ShopifyInstall::SHOP_PATTERN],
        ], [
            'shop.regex' => 'Use the store\'s Shopify address, like your-store.myshopify.com.',
        ]);

        return response()->json(['url' => $this->install->link($website, $data['shop'])]);
    }

    public function disconnect(Website $website): JsonResponse
    {
        $this->install->disconnect($website);

        return (new WebsiteResource($this->sites->withDetails($website->fresh() ?? $website)))->response();
    }
}
