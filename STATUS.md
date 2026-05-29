# Status

Last updated: May 29, 2026

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
- API tokens are assigned to an owner; Ayrshare-compatible calls use that owner as the source of truth for platform targets.
- Ayrshare-compatible endpoints exist for publish, delete, comments, post analytics, account analytics, and post lookup.
- Synchronous Ayrshare-compatible publishing returns per-platform `postIds`, durable `provider_post_url` values, and partial failures for unsupported or unlinked platforms.
- Instagram OAuth redirect/callback routes are implemented for Instagram Login.
- Instagram connected accounts can be saved with encrypted access tokens.
- Instagram feed-image publishing adapter is implemented for the two-step media container and media publish flow.
- Instagram publish now waits for the media container `status_code` to be `FINISHED` before calling `media_publish`.
- Instagram app credentials are configured locally and Meta accepted the HTTPS callback `https://social.test/oauth/instagram/callback`.
- Herd is serving `https://social.test` with PHP 8.4 for this project.
- Instagram account `claytonhousemarketplace` connected successfully as `connected_accounts.id = 3`.

## Verified Manually

Facebook Page connected successfully after adding `business_management`.

Post/redelete smoke tests after narrowing OAuth and Page discovery:

- `social_posts.id = 5`
  - Published through the API with a public Picsum image.
  - Facebook post id: `358179240887925_1400464472105392`
  - Final local status: `deleted`

- `social_posts.id = 6`
  - Published through the API with a real Clayton House shop image from `https://clayton.house/shop`.
  - Facebook post id: `358179240887925_1400465345438638`
  - Final local status: `deleted`

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

Instagram publish smoke test:

- `social_posts.id = 7`
  - Published through the API with a real Clayton House shop image from `https://clayton.house/shop`.
  - Instagram media id: `18082816811438704`
  - Instagram container id: `18473686879099933`
  - Final local status after delete test: `published`
  - Delete attempt through `DELETE /api/posts/7` queued correctly, but Meta rejected `DELETE /18082816811438704` with `Unsupported delete request`.
  - The app now treats Instagram deletes as terminal `manual_delete_required`, not retryable failures.
  - Local target `social_post_targets.id = 6` has `delete_status = manual_delete_required` and `delete_attempts = 1`.

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

The Ayrshare-compatible API now needs Page engagement permission for sold-item comments. The local `.env` scope request should be:

```dotenv
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement
```

Meta's broad Page use case still shows `business_management` as ready for testing and did not expose a remove action from the permission table. Keep it out of our OAuth request and retest before assuming it is truly required.

The callback now discovers Facebook Pages only through `/me/accounts`. It no longer calls `/me/assigned_pages`, `/me/businesses`, `owned_pages`, or `client_pages` during the normal connection path.

## Permission Decisions

- Facebook round one is Page-only:
  - user chooses a Page,
  - app publishes image posts,
  - app deletes only posts created or recovered by this app,
  - app adds sold-item comments to posts it created,
  - app reads organic post status/engagement.
- Vendors later connect their own accounts.
- Instagram feed-image publishing can be set up in round one or 1.5 if it uses the same practical Meta connection flow.
- Catalog API is phase 2 or 3.
- Boosting/ads are phase 2.
- Paid stats come with the ads phase; organic status/engagement is round one.
- Meta dashboard previously had `instagram_business_basic` and `instagram_business_content_publish` ready for testing for the Instagram API use case.
- Ayrshare-compatible Instagram analytics/comments require adding `instagram_business_manage_insights` and, if Instagram sold-item comments stay enabled, `instagram_business_manage_comments`.
- Instagram messages, shopping, and legacy Page-linked Instagram publishing permissions are intentionally not enabled for round one.
- Instagram delete is not implemented with the current permission set.
- Instagram local connection testing should start from `https://social.test/connected-accounts`, not `localhost:8000`, so the OAuth state cookie and callback host match.

Target Facebook scopes to test next:

```dotenv
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement
```

Avoid round-one requests for:

- `business_management`, unless Page selection/token minting still requires it.
- `pages_read_user_content`.
- `publish_video`.
- `catalog_management`.
- `ads_read`.
- `ads_management`.
- `read_insights`.

## Public API

Implemented routes:

- `POST /api/post`
- `DELETE /api/post`
- `GET /api/post/{post}`
- `POST /api/comments`
- `POST /api/analytics/post`
- `POST /api/analytics/social`
- `GET /api/connected-accounts`
- `POST /api/posts`
- `GET /api/posts/{post}`
- `DELETE /api/posts/{post}`

The singular `/api/post` routes are Ayrshare-compatible and use the owner assigned to the bearer token. Unsupported platforms such as `twitter`, `pinterest`, and `gmb` currently return partial failures.

The internal create endpoint supports:

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

After starting Instagram:

```bash
php artisan test --compact tests/Feature/Social/InstagramBusinessAdapterTest.php tests/Feature/Social/InstagramOAuthControllerTest.php tests/Feature/Social/FacebookOAuthControllerTest.php tests/Feature/Social/FacebookPageAdapterTest.php tests/Feature/Social/SocialPostJobsTest.php tests/Feature/Api/SocialPublishingApiTest.php
```

Last targeted result:

```text
14 tests passed, 66 assertions
```

After Instagram publish polling fix:

```bash
php artisan test --compact tests/Feature/Social/InstagramBusinessAdapterTest.php tests/Feature/Social/InstagramOAuthControllerTest.php
```

Last targeted result:

```text
6 tests passed, 36 assertions
```

After adding the Ayrshare-compatible API:

```bash
php artisan test --compact tests/Feature/ApiTokensControllerTest.php tests/Feature/Api/AyrshareCompatibilityApiTest.php tests/Feature/Api/SocialPublishingApiTest.php tests/Feature/Social/FacebookPageAdapterTest.php tests/Feature/Social/InstagramBusinessAdapterTest.php tests/Feature/Social/InstagramOAuthControllerTest.php tests/Feature/Social/FacebookOAuthControllerTest.php tests/Feature/Social/SocialPostJobsTest.php
```

Last targeted result:

```text
34 tests passed, 166 assertions
```

## Next Steps

1. Retest Facebook OAuth with `pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement`.
2. In Meta use-case settings, enable only the Page permissions needed for Page selection, Page organic read, Page post management, and sold-item comments.
3. Confirm new OAuth debug records only show the `/me/accounts` page discovery response.
4. Manually confirm `social_posts.id = 7` is visible on Instagram.
5. Manually smoke test the Ayrshare-compatible `/api/post` flow with the real POS2024 bearer token after creating an owner-bound token.
6. Add reconciliation for ambiguous Facebook publish failures across the synchronous compatibility path if manual testing exposes duplicate risk.
7. Decide whether scheduled posts are owned entirely here or triggered by the upstream website scheduler.
8. Prepare narrow Meta App Review explanations from `PERMISSIONS.md`.
9. Add production queue monitoring later if database queues become hard to inspect.
