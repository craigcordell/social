<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading size="xl">Social publishing</flux:heading>
            <flux:text>Queued API posting for connected social accounts.</flux:text>
        </div>

        <div class="grid auto-rows-min gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:text>Owners</flux:text>
                <div class="mt-2 text-3xl font-semibold">{{ $ownersCount }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:text>Active connections</flux:text>
                <div class="mt-2 text-3xl font-semibold">{{ $connectedAccountsCount }}</div>
            </div>
            <div class="rounded-lg border border-zinc-200 p-5 dark:border-zinc-700">
                <flux:text>Queued or running posts</flux:text>
                <div class="mt-2 text-3xl font-semibold">{{ $queuedTargetsCount }}</div>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <div class="border-b border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading>Recent posts</flux:heading>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                    <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-3 font-medium">ID</th>
                            <th class="px-4 py-3 font-medium">Owner</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Targets</th>
                            <th class="px-4 py-3 font-medium">Scheduled</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @forelse ($recentPosts as $post)
                            <tr>
                                <td class="px-4 py-3">{{ $post->id }}</td>
                                <td class="px-4 py-3">{{ $post->owner->name }}</td>
                                <td class="px-4 py-3">{{ $post->status }}</td>
                                <td class="px-4 py-3">{{ $post->targets->count() }}</td>
                                <td class="px-4 py-3">{{ $post->scheduled_at?->toDayDateTimeString() ?? 'Immediate' }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-4 py-6 text-center text-zinc-500">No posts yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts::app>
