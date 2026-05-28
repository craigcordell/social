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
- Facebook photo publish now attempts automatic reconciliation after a 5xx Graph API response by checking the Page's recent `/posts` feed for an exact message match before the queue retries.

Still needed:

- Revisit reconciliation once multiple providers exist; current automatic recovery only covers Facebook Page photo publishes.

## Current Facebook Notes

- Local callback URL: `http://localhost:8000/oauth/facebook/callback`
- Graph version: `v25.0`
- Scopes that worked during first local OAuth:
  - `pages_show_list`
  - `pages_read_engagement`
  - `pages_manage_posts`
  - `business_management`

The `business_management` permission produced stronger Meta consent warnings. It should be treated as a temporary discovery workaround, not a round-one default. See `PERMISSIONS.md` for the permission minimization plan.

The local `.env` scope request has been narrowed back to:

```dotenv
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts
```

Meta's broad Page use case still shows `business_management` as ready for testing and did not expose a remove action from the permission table. Keep it out of our OAuth request and retest before assuming it is truly required.

The callback now discovers Facebook Pages only through `/me/accounts`. It no longer calls `/me/assigned_pages`, `/me/businesses`, `owned_pages`, or `client_pages` during the normal connection path.

## Permission Decisions

- Facebook round one is Page-only:
  - user chooses a Page,
  - app publishes image posts,
  - app deletes only posts created or recovered by this app,
  - app reads organic post status/engagement.
- Vendors later connect their own accounts.
- Instagram feed-image publishing can be set up in round one or 1.5 if it uses the same practical Meta connection flow.
- Catalog API is phase 2 or 3.
- Boosting/ads are phase 2.
- Paid stats come with the ads phase; organic status/engagement is round one.
- Meta dashboard currently has `instagram_business_basic` and `instagram_business_content_publish` ready for testing for the Instagram API use case.
- Instagram comments, messages, insights, shopping, and legacy Page-linked Instagram publishing permissions are intentionally not enabled for round one.

Target Facebook scopes to test next:

```dotenv
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts
```

Avoid round-one requests for:

- `business_management`, unless Page selection/token minting still requires it.
- `pages_manage_engagement`.
- `pages_read_user_content`.
- `publish_video`.
- `catalog_management`.
- `ads_read`.
- `ads_management`.
- `read_insights`.

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

1. Retest Facebook OAuth with only `pages_show_list,pages_read_engagement,pages_manage_posts`.
2. In Meta use-case settings, enable only the Page permissions needed for Page selection, Page organic read, and Page post management.
3. Confirm new OAuth debug records only show the `/me/accounts` page discovery response.
4. Test the Instagram Login publishing path later with `instagram_business_basic` and `instagram_business_content_publish` only.
5. Add reconciliation for ambiguous Facebook publish failures.
6. Add a stable API-token workflow for the real calling app instead of throwaway test tokens.
7. Decide whether scheduled posts are owned entirely here or triggered by the upstream website scheduler.
8. Add Instagram Business adapter after Facebook Page flow is stable.
9. Prepare narrow Meta App Review explanations from `PERMISSIONS.md`.
10. Add production queue monitoring later if database queues become hard to inspect.
