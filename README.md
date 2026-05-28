# Social Publishing API

Internal Laravel app for queued social publishing. The MVP replaces the basic Ayrshare workflow for Clayton House Marketplace: external systems call an authenticated API, the app queues provider jobs, and connected platform post ids are stored locally for later deletion.

## Stack

- Laravel 13 with the official Livewire starter kit
- Livewire/Flux admin UI
- Sanctum personal access tokens for API callers
- Socialite plus direct Graph API calls for OAuth/provider work
- Database queues for publish/delete jobs
- Pest test suite

## Current Scope

Implemented for Facebook Pages:

- Connect Facebook Pages with OAuth.
- Store Page access tokens encrypted in `connected_accounts`.
- Create queued single-image posts from public image URLs.
- Store Facebook `post_id` in `social_post_targets.provider_post_id`.
- Delete posts created or recovered through this app.
- Track aggregate post status and per-target publish/delete status.

Started for Instagram professional accounts:

- Connect Instagram accounts with the Instagram Login OAuth flow.
- Store Instagram access tokens encrypted in `connected_accounts`.
- Publish queued single-image feed posts through a media container and publish step.

Instagram delete is not implemented yet because the current round-one permission set only covers account basics and feed-image publishing. Google Business Profile support is still schema-only.

See `PERMISSIONS.md` for the current Meta permission minimization plan. Round one should aim for Page publishing/deleting and organic Page status only.

## Local Setup

```bash
composer install
npm install
php artisan migrate
npm run build
```

Run the full local stack:

```bash
composer dev
```

That starts Laravel, Vite, logs, and a database queue listener for:

- `social-publish`
- `social-delete`
- `default`

If running pieces manually:

```bash
php artisan queue:listen --queue=social-publish,social-delete,default --tries=1 --timeout=0
```

## Required Environment

```dotenv
APP_URL=https://social.test
QUEUE_CONNECTION=database

FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
FACEBOOK_REDIRECT_URI=http://localhost:8000/oauth/facebook/callback
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts
FACEBOOK_GRAPH_VERSION=v25.0
FACEBOOK_LOGIN_CONFIG_ID=

INSTAGRAM_CLIENT_ID="${FACEBOOK_CLIENT_ID}"
INSTAGRAM_CLIENT_SECRET="${FACEBOOK_CLIENT_SECRET}"
INSTAGRAM_REDIRECT_URI=https://social.test/oauth/instagram/callback
INSTAGRAM_SCOPES=instagram_business_basic,instagram_business_content_publish
INSTAGRAM_GRAPH_VERSION=v25.0
```

For local Facebook OAuth, add `http://localhost:8000/oauth/facebook/callback` to the app's valid redirect URIs. The first working local flow used `business_management` to discover the CHM Page through the Business `owned_pages` edge, but the next permissions pass should try to remove that scope.

For local Instagram OAuth, use Herd's secured local domain. Run `herd secure social` if needed, then add `https://social.test/oauth/instagram/callback` to the Instagram API with Instagram Login redirect URI settings. Start the connection flow from `https://social.test/connected-accounts` so Laravel's OAuth state session stays on the same host.

## Admin UI

- `/dashboard` - summary
- `/owners` - internal/vendor owner records
- `/connected-accounts` - Facebook OAuth, app settings, connected accounts, OAuth debug attempts
- `/api-tokens` - create/revoke Sanctum API tokens
- `/posts` - post/target history

## API

All API routes require a Sanctum bearer token.

```http
GET /api/connected-accounts
POST /api/posts
GET /api/posts/{post}
DELETE /api/posts/{post}
```

Create post payload:

```json
{
  "owner_id": 1,
  "target_ids": [1],
  "caption": "Post text",
  "image_url": "https://example.com/image.jpg",
  "link_url": "https://example.com/item",
  "scheduled_at": "2026-05-28T20:00:00Z",
  "external_id": "optional-source-id",
  "idempotency_key": "optional-idempotency-key"
}
```

`scheduled_at`, `link_url`, `external_id`, and `idempotency_key` are optional. Reusing the same `idempotency_key` for the same owner returns the existing local post instead of creating a duplicate.

## Testing

```bash
php artisan test
composer test
```

Use `php artisan optimize:clear` after config, route, or view changes while developing.
