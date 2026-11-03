<?php

declare(strict_types=1);

namespace App\Domain\Integrations\Shopify;

use App\Domain\Integrations\Exceptions\ShopifyRequestException;
use App\Domain\Websites\Models\WebsiteShopifyConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Habitat talking to a client's Shopify store, through the store's own app.
 *
 * Each store has an app made for it in Shopify's Dev Dashboard; its keys and
 * the offline access token the store gives it are kept on the store's
 * connection. Shopify may hand out tokens that expire with a refresh token
 * beside them; those are refreshed here before they are used.
 */
class ShopifyClient
{
    private const TIMEOUT_SECONDS = 20;

    /**
     * Where someone with admin rights on the store approves its app.
     */
    public function authorizeUrl(string $shop, string $clientId, string $state): string
    {
        return 'https://'.$shop.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'scope' => (string) config('services.shopify.scopes'),
            'redirect_uri' => url('/integrations/shopify/callback'),
            'state' => $state,
        ]);
    }

    /**
     * The store's access token for the code Shopify sent back with the approval.
     *
     * @return array{access_token: string, scope: string, refresh_token?: string, expires_in?: int}
     *
     * @throws ShopifyRequestException
     */
    public function exchangeCode(WebsiteShopifyConnection $connection, string $code): array
    {
        return $this->token($connection->shop_domain, [
            'client_id' => (string) $connection->client_id,
            'client_secret' => (string) $connection->client_secret,
            'code' => $code,
        ]);
    }

    /**
     * Run an Admin API query or mutation on the store, answering its `data`.
     * A mutation's `userErrors` are the caller's to read.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws ShopifyRequestException
     */
    public function graphql(WebsiteShopifyConnection $connection, string $query, array $variables = []): array
    {
        $this->refreshIfExpired($connection);

        $url = sprintf('https://%s/admin/api/%s/graphql.json', $connection->shop_domain, config('services.shopify.api_version'));

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => (string) $connection->access_token])
                ->post($url, $variables === [] ? ['query' => $query] : ['query' => $query, 'variables' => $variables]);
        } catch (ConnectionException $e) {
            throw new ShopifyRequestException('Shopify could not be reached: '.$e->getMessage());
        }

        if (in_array($response->status(), [401, 403], true)) {
            $this->breakConnection($connection, $response);
        }

        if ($response->failed()) {
            throw new ShopifyRequestException($this->problem($response), $response->status());
        }

        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            throw new ShopifyRequestException((string) ($errors[0]['message'] ?? 'Shopify refused the request.'), $response->status());
        }

        $data = $response->json('data');

        return is_array($data) ? $data : [];
    }

    private function refreshIfExpired(WebsiteShopifyConnection $connection): void
    {
        $expires = $connection->access_token_expires_at;

        if ($expires === null || $connection->refresh_token === null || $expires->subMinutes(5)->isFuture()) {
            return;
        }

        $answer = $this->token($connection->shop_domain, [
            'client_id' => (string) $connection->client_id,
            'client_secret' => (string) $connection->client_secret,
            'grant_type' => 'refresh_token',
            'refresh_token' => (string) $connection->refresh_token,
        ]);

        $connection->forceFill([
            'access_token' => $answer['access_token'],
            // Each refresh hands out a new refresh token; the newest is kept.
            'refresh_token' => $answer['refresh_token'] ?? $connection->refresh_token,
            'access_token_expires_at' => isset($answer['expires_in']) ? now()->addSeconds((int) $answer['expires_in']) : null,
        ])->save();
    }

    /**
     * @param  array<string, string>  $form
     * @return array{access_token: string, scope: string, refresh_token?: string, expires_in?: int}
     */
    private function token(string $shop, array $form): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->acceptJson()->asForm()->post('https://'.$shop.'/admin/oauth/access_token', $form);
        } catch (ConnectionException $e) {
            throw new ShopifyRequestException('Shopify could not be reached: '.$e->getMessage());
        }

        $token = $response->json('access_token');

        if ($response->failed() || ! is_string($token) || $token === '') {
            throw new ShopifyRequestException($this->problem($response), $response->status());
        }

        return array_filter([
            'access_token' => $token,
            'scope' => (string) $response->json('scope', ''),
            'refresh_token' => is_string($response->json('refresh_token')) ? $response->json('refresh_token') : null,
            'expires_in' => is_numeric($response->json('expires_in')) ? (int) $response->json('expires_in') : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * The store no longer accepts the app: removed from the store, or its
     * token revoked. Bookings stop going to its checkout until it is
     * connected again.
     */
    private function breakConnection(WebsiteShopifyConnection $connection, Response $response): void
    {
        $connection->forceFill([
            'status' => WebsiteShopifyConnection::STATUS_BROKEN,
            'broken_at' => now(),
            'last_error' => mb_substr($this->problem($response), 0, 500),
        ])->save();
    }

    private function problem(Response $response): string
    {
        $errors = $response->json('errors') ?? $response->json('error_description') ?? $response->json('error');

        if (is_string($errors) && $errors !== '') {
            return $errors;
        }

        if (is_array($errors) && isset($errors[0]['message'])) {
            return (string) $errors[0]['message'];
        }

        return 'Shopify answered '.$response->status().'.';
    }
}
