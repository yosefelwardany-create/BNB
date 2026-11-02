<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Domain\Websites\Exceptions\ShopifyInstallRefused;
use App\Domain\Websites\Services\ShopifyInstall;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Where Shopify sends someone back after they approve the platform's app on
 * a store: the store is connected, and they land on the site's editor
 * saying whether it worked.
 */
class ShopifyCallbackController extends Controller
{
    public function __invoke(Request $request, ShopifyInstall $install): RedirectResponse
    {
        try {
            $site = $install->complete($request->query());

            return redirect()->away($this->editor($site->getKey(), 'connected'));
        } catch (ShopifyInstallRefused $refused) {
            Log::notice('A Shopify install was refused.', ['reason' => $refused->getMessage()]);

            return redirect()->away($refused->websiteId === null
                ? $this->admin('/websites?shopify=failed')
                : $this->editor($refused->websiteId, 'failed'));
        }
    }

    private function editor(string $websiteId, string $outcome): string
    {
        return $this->admin('/websites/'.$websiteId.'/edit?shopify='.$outcome);
    }

    /**
     * An address in the admin application: under /app when it is served by
     * this same server, as in production.
     */
    private function admin(string $path): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        if ($base === rtrim((string) config('app.url'), '/')) {
            $base .= '/app';
        }

        return $base.$path;
    }
}
