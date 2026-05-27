<x-layouts::app :title="__('API Tokens')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading size="xl">API tokens</flux:heading>
            <flux:text>Use Sanctum tokens for website and POS calls.</flux:text>
        </div>

        @if (session('plainTextToken'))
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-100">
                <div class="mb-2 font-medium">New token</div>
                <code class="block overflow-x-auto rounded bg-white p-3 dark:bg-zinc-900">{{ session('plainTextToken') }}</code>
            </div>
        @endif

        <form method="POST" action="{{ route('api-tokens.store') }}" class="flex flex-wrap items-end gap-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            @csrf
            <flux:input name="name" label="Token name" required />
            <flux:button type="submit" variant="primary" icon="key">Create token</flux:button>
        </form>

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Last used</th>
                        <th class="px-4 py-3 font-medium">Created</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($tokens as $token)
                        <tr>
                            <td class="px-4 py-3">{{ $token->name }}</td>
                            <td class="px-4 py-3">{{ $token->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="px-4 py-3">{{ $token->created_at->toDayDateTimeString() }}</td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('api-tokens.destroy', $token) }}">
                                    @csrf
                                    @method('DELETE')
                                    <flux:button type="submit" variant="danger" size="sm">Revoke</flux:button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-zinc-500">No API tokens yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts::app>
