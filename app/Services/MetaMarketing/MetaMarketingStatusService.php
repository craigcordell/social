<?php

namespace App\Services\MetaMarketing;

use App\Models\Owner;

final class MetaMarketingStatusService
{
    public function __construct(
        private readonly MetaGraphApiClient $graph,
        private readonly MetaMarketingConfiguration $configuration,
    ) {}

    /** @return array<string, mixed> */
    public function get(Owner $owner): array
    {
        $this->configuration->assertConfiguredFor($owner);
        $permissions = $this->graph->get('me/permissions');
        $permissionRows = is_array($permissions['data'] ?? null) ? $permissions['data'] : [];
        $grantedPermissions = [];

        foreach ($permissionRows as $permission) {
            if (
                is_array($permission)
                && ($permission['status'] ?? null) === 'granted'
                && is_string($permission['permission'] ?? null)
            ) {
                $grantedPermissions[] = $permission['permission'];
            }
        }

        return [
            'system_user' => $this->graph->get('me', ['fields' => 'id,name']),
            'permissions' => $grantedPermissions,
            'ad_account' => $this->graph->get($this->configuration->adAccountPath(), [
                'fields' => 'id,account_id,name,account_status,currency,timezone_name,amount_spent,balance,spend_cap',
            ]),
            'page_id' => (string) config('services.meta_marketing.page_id'),
            'instagram_account' => $this->graph->get((string) config('services.meta_marketing.instagram_account_id'), [
                'fields' => 'id,username,name',
            ]),
            'graph_version' => (string) config('services.meta_marketing.graph_version'),
            'budget_mutations_enabled' => (int) config('services.meta_marketing.account_daily_limit_minor') > 0,
            'account_daily_limit_minor' => (int) config('services.meta_marketing.account_daily_limit_minor'),
        ];
    }
}
