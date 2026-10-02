<?php

declare(strict_types=1);

namespace App\Domain\Channels\Services;

use App\Domain\Agents\DataObjects\AgentBrief;
use App\Domain\Channels\Models\ChannelAccount;
use App\Domain\Channels\Models\ChannelListing;
use App\Domain\Integrations\Registries\AIProviderRegistry;
use App\Domain\Integrations\Support\HostexData;
use App\Domain\Pricing\Models\PricingRule;

/** Read cached sync evidence without credentials, image links or guest data. */
final class HostexDiagnostics
{
    private const MONEY = ['base_rate', 'cleaning_fee', 'security_deposit', 'extra_guest_fee'];

    private int $shapeBudget = 200;

    public function forAccount(ChannelAccount $account): array
    {
        $mappings = $account->listings()->with('property')->orderBy('id')->limit(25)->get();

        return [
            'version' => 1,
            'generated_at' => now()->toIso8601String(),
            'account_id' => $account->id,
            'last_pull_status' => $account->last_pull_result['status'] ?? null,
            'last_pull_stage' => $account->last_pull_result['current_stage'] ?? null,
            'mappings' => $mappings->map(fn (ChannelListing $mapping) => $this->mapping($mapping))->all(),
            'mappings_truncated' => $account->listings()->count() > 25,
        ];
    }

    private function mapping(ChannelListing $mapping): array
    {
        $this->shapeBudget = 200;
        $source = $mapping->metadata['hostex'] ?? [];
        $pictures = $source['listing_metadata']['house_picture_list'] ?? null;
        $property = $mapping->property;
        $keys = array_merge(['currency'], self::MONEY);
        $rules = $source['price_rules'] ?? [];
        $sourcePrices = ['listing_currency' => HostexData::currency($rules['listing_currency'] ?? null)];
        foreach (['base_price', 'cleaning_fee', 'security_deposit', 'extra_guest_fee'] as $key) {
            $value = $rules[$key] ?? null;
            $sourcePrices[$key] = is_scalar($value) && strlen((string) $value) <= 40 && is_numeric($value) ? $value : null;
        }
        $pricing = [
            'local' => $property?->only($keys),
            'source' => $sourcePrices,
            'overrides' => array_values(array_intersect($property?->settings['hostex_overrides'] ?? [], $keys)),
            'previously_applied' => array_intersect_key($property?->settings['hostex']['applied'] ?? [], array_flip($keys)),
        ];
        if ($property !== null) {
            $pricing['listings'] = $property->listings()->get(['id', 'currency', 'base_rate', 'cleaning_fee', 'extra_guest_fee'])->toArray();
            $pricing['units'] = $property->units()->where('base_rate', '!=', 0)->get(['id', 'base_rate'])->toArray();
            $pricing['unit_types'] = $property->unitTypes()->where('base_rate', '!=', 0)->get(['id', 'base_rate'])->toArray();
            $pricing['scoped_rules'] = PricingRule::query()->active()
                ->where(fn ($q) => $q->where('property_id', $property->id)
                    ->orWhereIn('listing_id', $property->listings()->select('id'))
                    ->orWhereIn('unit_type_id', $property->unitTypes()->select('id')))
                ->get(['id', 'adjustment_type', 'adjustment_value', 'floor_rate', 'ceiling_rate'])->toArray();
        }
        $agent = null;
        if ($property !== null) {
            $brief = AgentBrief::fromSettings($property->settings);
            $provider = app(AIProviderRegistry::class)->forProperty($property);
            $agent = [
                'enabled' => $brief->enabled,
                'selected_provider' => $brief->provider,
                'effective_provider' => $provider->key(),
                'has_bot_url' => $brief->botUrl !== null,
                'has_webhook_url' => $brief->webhookUrl !== null,
                'provider_configured' => $provider->key() === 'bot' ? $brief->botUrl !== null : $provider->isLive(),
            ];
        }

        return [
            'mapping_id' => $mapping->id,
            'property_id' => $mapping->property_id,
            'pricing' => $pricing,
            'agent' => $agent,
            'images' => [
                'cover' => $this->shape($source['cover'] ?? null),
                'gallery_count' => is_array($pictures) ? count($pictures) : null,
                'gallery' => $this->shape($pictures),
            ],
        ];
    }

    /** Preserve field names and URL characteristics, never string contents. */
    private function shape(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 6 || --$this->shapeBudget < 0) {
            return ['type' => get_debug_type($value), 'truncated' => true];
        }
        if (is_array($value)) {
            $result = ['type' => array_is_list($value) ? 'list' : 'object', 'count' => count($value), 'fields' => []];
            foreach (array_slice($value, 0, array_is_list($value) ? 2 : 20, true) as $key => $entry) {
                $key = is_int($key) || preg_match('/^[a-z_][a-z0-9_]{0,63}$/iD', (string) $key) ? $key : '[redacted key]';
                $result['fields'][$key] = $this->shape($entry, $depth + 1);
                if ($this->shapeBudget <= 0) {
                    $result['truncated'] = true;
                    break;
                }
            }

            return $result;
        }
        if (! is_string($value)) {
            return ['type' => get_debug_type($value)];
        }
        $trimmed = trim($value);
        $result = [
            'type' => 'string', 'length' => strlen($value), 'trimmed_length' => strlen($trimmed),
            'accepted_image_url' => HostexData::pictureUrl($value) !== null,
            'has_whitespace' => preg_match('/\s/', $trimmed) === 1,
            'html_encoded' => str_contains($value, '&amp;') || str_contains($value, '&#'),
            'contains_escaped_slashes' => str_contains($value, '\\/'),
            'starts_with_slash' => str_starts_with($trimmed, '/'),
        ];
        if (preg_match('~^https?://~i', $trimmed) || str_starts_with($trimmed, '//')) {
            $normalized = str_starts_with($trimmed, '//') ? 'https:'.$trimmed : $trimmed;
            $parts = parse_url($normalized);
            $result['format'] = 'url';
            $result['scheme'] = $parts['scheme'] ?? null;
            $result['valid_url_syntax'] = filter_var($normalized, FILTER_VALIDATE_URL) !== false;
            $result['host_has_dot'] = str_contains($parts['host'] ?? '', '.');
            $result['has_credentials'] = isset($parts['user']) || isset($parts['pass']);
            $result['has_query'] = isset($parts['query']);
            $result['has_fragment'] = isset($parts['fragment']);
            $result['path_length'] = strlen($parts['path'] ?? '');
            // No host, path, query value, signature or caption is exported.
        } elseif ($trimmed !== '' && in_array($trimmed[0], ['[', '{', '"'], true)) {
            $decoded = json_decode($trimmed, true);
            $result['format'] = json_last_error() === JSON_ERROR_NONE ? 'json' : 'text';
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['decoded'] = $this->shape($decoded, $depth + 1);
            }
        } else {
            $result['format'] = $trimmed === '' ? 'empty' : 'text';
        }

        return $result;
    }
}
