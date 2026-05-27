<x-layouts::app :title="__('Owners')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading size="xl">Owners</flux:heading>
            <flux:text>Your business now, vendor accounts later.</flux:text>
        </div>

        <form method="POST" action="{{ route('owners.store') }}" class="grid gap-4 rounded-lg border border-zinc-200 p-4 md:grid-cols-4 dark:border-zinc-700">
            @csrf
            <flux:input name="name" label="Name" required />
            <flux:select name="type" label="Type" required>
                <flux:select.option value="internal">Internal</flux:select.option>
                <flux:select.option value="vendor">Vendor</flux:select.option>
            </flux:select>
            <flux:input name="external_id" label="External ID" />
            <div class="flex items-end">
                <flux:button type="submit" variant="primary">Create owner</flux:button>
            </div>
        </form>

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Type</th>
                        <th class="px-4 py-3 font-medium">External ID</th>
                        <th class="px-4 py-3 font-medium">Connections</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($owners as $owner)
                        <tr>
                            <td class="px-4 py-3">{{ $owner->name }}</td>
                            <td class="px-4 py-3">{{ $owner->type }}</td>
                            <td class="px-4 py-3">{{ $owner->external_id ?? '-' }}</td>
                            <td class="px-4 py-3">{{ $owner->connected_accounts_count }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-6 text-center text-zinc-500">No owners yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-layouts::app>
