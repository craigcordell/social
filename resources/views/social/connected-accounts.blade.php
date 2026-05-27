<x-layouts::app :title="__('Connections')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading size="xl">Connections</flux:heading>
            <flux:text>Connect Facebook Pages with OAuth and store Page tokens for queued publishing.</flux:text>
        </div>

        <form method="GET" action="{{ route('oauth.facebook.redirect') }}" class="flex flex-wrap items-end gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:select name="owner_id" label="Owner" required>
                @foreach ($owners as $owner)
                    <flux:select.option value="{{ $owner->id }}">{{ $owner->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" icon="link">Connect Facebook</flux:button>
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
    </div>
</x-layouts::app>
