<x-layouts::app :title="__('Connections')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading size="xl">Connections</flux:heading>
            <flux:text>Connect Facebook Pages and Instagram professional accounts for queued publishing.</flux:text>
        </div>

        @if (session('warning'))
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-100">
                {{ session('warning') }}
            </div>
        @endif

        @if (session('facebook_oauth_debug'))
            @php($debug = session('facebook_oauth_debug'))
            <div class="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950 dark:text-sky-100">
                <div class="mb-3 font-medium">Last Facebook OAuth callback</div>
                <dl class="grid gap-3 md:grid-cols-2">
                    <div>
                        <dt class="opacity-70">Facebook user ID</dt>
                        <dd>{{ $debug['facebook_user_id'] }}</dd>
                    </div>
                    <div>
                        <dt class="opacity-70">Pages returned</dt>
                        <dd>{{ $debug['page_count'] }}</dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="opacity-70">Granted permissions</dt>
                        <dd><code>{{ collect($debug['permissions'])->map(fn ($permission) => "{$permission['permission']}:{$permission['status']}")->implode(', ') ?: 'None returned' }}</code></dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="opacity-70">Pages</dt>
                        <dd><code>{{ collect($debug['pages'])->map(fn ($page) => "{$page['name']} ({$page['id']}), token=".($page['has_access_token'] ? 'yes' : 'no'))->implode('; ') ?: 'None returned' }}</code></dd>
                    </div>
                </dl>
            </div>
        @endif

        <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <div class="mb-3 font-medium">Facebook app settings</div>
            <dl class="grid gap-3 md:grid-cols-2">
                <div>
                    <dt class="text-zinc-500">App ID</dt>
                    <dd>{{ $facebookConfig['client_id'] ?: 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">App secret</dt>
                    <dd>{{ $facebookConfig['has_client_secret'] ? 'Configured' : 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">OAuth redirect URI</dt>
                    <dd><code>{{ $facebookConfig['redirect_uri'] }}</code></dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Graph version</dt>
                    <dd>{{ $facebookConfig['graph_version'] }}</dd>
                </div>
                <div class="md:col-span-2">
                    <dt class="text-zinc-500">Requested scopes</dt>
                    <dd><code>{{ implode(', ', $facebookConfig['scopes']) }}</code></dd>
                </div>
                <div class="md:col-span-2">
                    <dt class="text-zinc-500">Business login configuration ID</dt>
                    <dd><code>{{ $facebookConfig['login_config_id'] ?: 'Not configured' }}</code></dd>
                </div>
            </dl>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <div class="mb-3 font-medium">Instagram app settings</div>
            <dl class="grid gap-3 md:grid-cols-2">
                <div>
                    <dt class="text-zinc-500">App ID</dt>
                    <dd>{{ $instagramConfig['client_id'] ?: 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">App secret</dt>
                    <dd>{{ $instagramConfig['has_client_secret'] ? 'Configured' : 'Not configured' }}</dd>
                </div>
                <div>
                    <dt class="text-zinc-500">OAuth redirect URI</dt>
                    <dd><code>{{ $instagramConfig['redirect_uri'] }}</code></dd>
                </div>
                <div>
                    <dt class="text-zinc-500">Graph version</dt>
                    <dd>{{ $instagramConfig['graph_version'] }}</dd>
                </div>
                <div class="md:col-span-2">
                    <dt class="text-zinc-500">Requested scopes</dt>
                    <dd><code>{{ implode(', ', $instagramConfig['scopes']) }}</code></dd>
                </div>
            </dl>
        </div>

        <form method="GET" action="{{ route('oauth.facebook.redirect') }}" class="flex flex-wrap items-end gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:select name="owner_id" label="Owner" required>
                @foreach ($owners as $owner)
                    <flux:select.option value="{{ $owner->id }}">{{ $owner->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" icon="link">Connect Facebook</flux:button>
        </form>

        <form method="GET" action="{{ route('oauth.instagram.redirect') }}" class="flex flex-wrap items-end gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:select name="owner_id" label="Owner" required>
                @foreach ($owners as $owner)
                    <flux:select.option value="{{ $owner->id }}">{{ $owner->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" icon="link">Connect Instagram</flux:button>
        </form>

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 font-medium">ID</th>
                        <th class="px-4 py-3 font-medium">Owner</th>
                        <th class="px-4 py-3 font-medium">Provider</th>
                        <th class="px-4 py-3 font-medium">Account</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($accounts as $account)
                        <tr>
                            <td class="px-4 py-3">{{ $account->id }}</td>
                            <td class="px-4 py-3">{{ $account->owner->name }}</td>
                            <td class="px-4 py-3">{{ $account->provider }}</td>
                            <td class="px-4 py-3">{{ $account->display_name }}</td>
                            <td class="px-4 py-3">{{ $account->status }}</td>
                            <td class="px-4 py-3 text-right">
                                @if ($account->status === 'active')
                                    <form method="POST" action="{{ route('connected-accounts.destroy', $account) }}">
                                        @csrf
                                        @method('DELETE')
                                        <flux:button type="submit" variant="danger" size="sm">Disconnect</flux:button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-zinc-500">No connected accounts yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading>Recent OAuth debug attempts</flux:heading>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($oauthDebugAttempts as $attempt)
                    <details class="p-4">
                        <summary class="cursor-pointer text-sm font-medium">
                            #{{ $attempt->id }} {{ $attempt->status }} · {{ $attempt->created_at->toDayDateTimeString() }}
                        </summary>
                        <div class="mt-4 grid gap-4 text-xs md:grid-cols-2">
                            <div>
                                <div class="mb-1 font-medium">Callback query</div>
                                <pre class="overflow-x-auto rounded bg-zinc-950 p-3 text-zinc-100">{{ json_encode($attempt->callback_query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                            <div>
                                <div class="mb-1 font-medium">Token summary</div>
                                <pre class="overflow-x-auto rounded bg-zinc-950 p-3 text-zinc-100">{{ json_encode($attempt->token_summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                            <div>
                                <div class="mb-1 font-medium">Permissions response</div>
                                <pre class="overflow-x-auto rounded bg-zinc-950 p-3 text-zinc-100">{{ json_encode($attempt->permissions_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                            <div>
                                <div class="mb-1 font-medium">Pages response</div>
                                <pre class="overflow-x-auto rounded bg-zinc-950 p-3 text-zinc-100">{{ json_encode($attempt->pages_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                            @if ($attempt->error_message)
                                <div class="md:col-span-2">
                                    <div class="mb-1 font-medium">Error</div>
                                    <pre class="overflow-x-auto rounded bg-zinc-950 p-3 text-zinc-100">{{ $attempt->error_message }}</pre>
                                </div>
                            @endif
                        </div>
                    </details>
                @empty
                    <div class="p-6 text-center text-sm text-zinc-500">No OAuth callbacks recorded yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts::app>
