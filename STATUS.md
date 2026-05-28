# Status

Last updated: May 28, 2026

## Working

- Laravel 13 Livewire starter app is installed in `/Users/craigcordell/Herd/social`.
- Sanctum API authentication is installed and working.
- Database queues are configured and used for social publish/delete work.
- Internal owner model exists; current seeded owner is `Internal`.
- Facebook OAuth can connect the Clayton House Marketplace Page.
- Connected Facebook Page tokens are stored encrypted.
- API-created Facebook image posts publish through the `social-publish` queue.
- Facebook `post_id` is stored locally in `social_post_targets.provider_post_id`.
- API deletes queue through `social-delete` and delete Facebook posts by stored `provider_post_id`.
- OAuth debug callback records are saved and shown on the connections page.
- Admin UI exists for dashboard, owners, connections, API tokens, and posts.

## Verified Manually

Facebook Page connected successfully after adding `business_management`.

Two sample posts were created during testing:

- `social_posts.id = 1`
  - Facebook post id: `358179240887925_1400359785449194`
  - Facebook media id: `1400359752115864`
  - Final local status: `deleted`

- `social_posts.id = 2`
  - Recovered Facebook post id: `358179240887925_1400358648782641`
  - Facebook media id: `1400358605449312`
  - Final local status: `deleted`

Both were confirmed visible on Facebook before deletion. Both were deleted through the API delete path and queue worker.

## Important Finding

The first Facebook publish attempt returned a Graph API 500:

```text
Please reduce the amount of data you're asking for, then retry your request
```

The post still appeared on Facebook. That means a provider call can complete its side effect but fail before the app receives the success response. The local retry created a second post.

Mitigation already applied:

- Facebook photo publish requests now use form-encoded parameters instead of JSON.
- The duplicate was recovered by reading the Page `posts` edge and adding a local record with the real Facebook `post_id`.

Still needed:

- Add first-class recovery/reconciliation behavior for ambiguous provider failures before this handles production volume.

## Current Facebook Notes

- Local callback URL: `http://localhost:8000/oauth/facebook/callback`
- Graph version: `v25.0`
- Working scopes:
  - `pages_show_list`
  - `pages_read_engagement`
  - `pages_manage_posts`
  - `business_management`

The `business_management` permission produced stronger Meta consent warnings. It is acceptable for internal CHM testing, but vendor-facing rollout should revisit Business Login configuration and App Review scope strategy.

## Public API

Implemented routes:

- `GET /api/connected-accounts`
- `POST /api/posts`
- `GET /api/posts/{post}`
- `DELETE /api/posts/{post}`

The create endpoint supports:

- `owner_id`
- `target_ids`
- `caption`
- `image_url`
- `link_url`
- `scheduled_at`
- `external_id`
- `idempotency_key`

## Tests

Passing targeted suites after the Facebook publish/delete tests:

```bash
php artisan test tests/Feature/Social/FacebookPageAdapterTest.php tests/Feature/Social/SocialPostJobsTest.php tests/Feature/Api/SocialPublishingApiTest.php
```

Last targeted result:

```text
9 tests passed, 29 assertions
```

After both deletes:

```bash
php artisan test tests/Feature/Social/SocialPostJobsTest.php tests/Feature/Api/SocialPublishingApiTest.php
```

Last targeted result:

```text
8 tests passed, 26 assertions
```

## Next Steps

1. Add reconciliation for ambiguous Facebook publish failures.
2. Add a stable API-token workflow for the real calling app instead of throwaway test tokens.
3. Decide whether scheduled posts are owned entirely here or triggered by the upstream website scheduler.
4. Add Instagram Business adapter after Facebook Page flow is stable.
5. Revisit Meta App Review requirements before vendor-facing OAuth.
6. Add production queue monitoring later if database queues become hard to inspect.
