<?php

namespace App\Http\Controllers;

use App\Models\ConnectedAccount;
use App\Models\Owner;
use App\Models\SocialPost;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('dashboard', [
            'ownersCount' => Owner::query()->count(),
            'connectedAccountsCount' => ConnectedAccount::query()->where('status', ConnectedAccount::STATUS_ACTIVE)->count(),
            'queuedTargetsCount' => SocialPost::query()->whereIn('status', [
                SocialPost::STATUS_QUEUED,
                SocialPost::STATUS_SCHEDULED,
                SocialPost::STATUS_PUBLISHING,
                SocialPost::STATUS_DELETE_QUEUED,
            ])->count(),
            'recentPosts' => SocialPost::query()
                ->with(['owner', 'targets.connectedAccount'])
                ->latest()
                ->limit(10)
                ->get(),
        ]);
    }

    public function posts(): View
    {
        return view('social.posts', [
            'posts' => SocialPost::query()
                ->with(['owner', 'targets.connectedAccount'])
                ->latest()
                ->paginate(25),
        ]);
    }
}
