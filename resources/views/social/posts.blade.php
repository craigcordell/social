<x-layouts::app :title="__('Posts')">
    <div class="flex h-full w-full flex-1 flex-col gap-6">
        <div>
            <flux:heading size="xl">Posts</flux:heading>
            <flux:text>API-created posts and status for each social site.</flux:text>
        </div>

        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 text-sm dark:divide-zinc-700">
                <thead class="bg-zinc-50 text-left dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 font-medium">ID</th>
                        <th class="px-4 py-3 font-medium">Owner</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium">Caption</th>
                        <th class="px-4 py-3 font-medium">Social sites</th>
                        <th class="px-4 py-3 font-medium">Scheduled</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse ($posts as $post)
                        <tr class="align-top">
                            <td class="px-4 py-3">{{ $post->id }}</td>
                            <td class="px-4 py-3">{{ $post->owner->name }}</td>
                            <td class="px-4 py-3">{{ $post->status }}</td>
                            <td class="max-w-md px-4 py-3">{{ str($post->caption)->limit(120) }}</td>
                            <td class="px-4 py-3">
                                <div class="space-y-1">
                                    @foreach ($post->targets as $target)
                                        <div>{{ config("social.platform_names.{$target->provider}", str($target->provider)->headline()) }} — {{ $target->connectedAccount->display_name }}: {{ $target->publish_status }}{{ $target->delete_status ? " / delete {$target->delete_status}" : '' }}</div>
                                    @endforeach
                                </div>
                            </td>
                            <td class="px-4 py-3">{{ $post->scheduled_at?->toDayDateTimeString() ?? 'Immediate' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-6 text-center text-zinc-500">No posts yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $posts->links() }}
    </div>
</x-layouts::app>
