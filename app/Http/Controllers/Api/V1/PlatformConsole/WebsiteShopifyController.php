<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PlatformConsole;

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
        private readonly WebsiteAdministration $sites,
    ) {}

    public function installLink(Request $request, Website $website): JsonResponse
    {
        $request->merge(['shop' => strtolower(trim((string) $request->input('shop')))]);

        // The secret, once saved, need not be typed again for the same app.
        $existing = $website->shopifyConnection;
        $secretKept = $existing !== null && filled($existing->client_secret) && $existing->client_id === trim((string) $request->input('client_id'));

        $data = $request->validate([
            'shop' => ['required', 'string', 'max:255', 'regex:'.ShopifyInstall::SHOP_PATTERN],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [$secretKept ? 'nullable' : 'required', 'string', 'max:255'],
        ], [
            'shop.regex' => 'Use the store\'s Shopify address, like your-store.myshopify.com.',
            'client_id.required' => 'Paste the Client ID of the app made for this store.',
            'client_secret.required' => 'Paste the Client secret of the app made for this store.',
        ]);

        return response()->json(['url' => $this->install->link($website, $data['shop'], trim($data['client_id']), isset($data['client_secret']) ? trim($data['client_secret']) : null)]);
    }

    public function disconnect(Website $website): JsonResponse
    {
        $this->install->disconnect($website);

        return (new WebsiteResource($this->sites->withDetails($website->fresh() ?? $website)))->response();
    }
}
