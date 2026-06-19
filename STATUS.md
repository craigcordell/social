# Status

Last updated: June 19, 2026

## Working

- Laravel 13 Livewire starter app is installed in `/Users/craigcordell/Herd/social`.
- Production site is live at `https://social.clayton.house`.
- Public registration is disabled; `/register` is not available and the root path is not intended as a public landing page.
- Sanctum API authentication is installed and working.
- The Ayrshare-compatible API publishes and deletes synchronously for now; queue job classes still exist for a later queue-backed path.
- Internal owner model exists; current seeded owner is `Internal`.
- Production OAuth callbacks are:
  - Facebook: `https://social.clayton.house/oauth/facebook/callback`
  - Instagram: `https://social.clayton.house/oauth/instagram/callback`
  - Google Business Profile: `https://social.clayton.house/oauth/google-business/callback`
- Facebook, Instagram, and Google Business are all connected in production.
- Facebook OAuth can connect the Clayton House Marketplace Page.
- Connected Facebook Page tokens are stored encrypted.
- API-created Facebook image posts publish synchronously through the Ayrshare-compatible API.
- Facebook `post_id` is stored locally in `social_post_targets.provider_post_id`.
- API deletes run synchronously and delete Facebook posts by stored `provider_post_id`.
- OAuth debug callback records are saved and shown on the connections page.
- Admin UI exists for dashboard, owners, connections, API tokens, and posts.
- API tokens are assigned to an owner; Ayrshare-compatible calls use that owner as the source of truth for platform targets.
- Ayrshare-compatible endpoints exist for publish, delete, comments, post analytics, account analytics, and post lookup.
- Synchronous Ayrshare-compatible publishing/deleting returns per-platform `postIds`, durable `provider_post_url` values, and partial failures for unsupported, unsupported-action, or unlinked platforms.
- The public API is now only the Ayrshare-shaped contract; the older `/api/posts` owner/target-id syntax has been removed.
- Instagram OAuth redirect/callback routes are implemented for Instagram Login.
- Instagram connected accounts can be saved with encrypted access tokens.
- Instagram feed-image publishing adapter is implemented for the two-step media container and media publish flow.
- Instagram publish now waits for the media container `status_code` to be `FINISHED` before calling `media_publish`.
- Instagram app credentials are configured locally and Meta accepted the HTTPS callback `https://social.test/oauth/instagram/callback`.
- Herd is serving `https://social.test` with PHP 8.4 for this project.
- Instagram account `claytonhousemarketplace` connected successfully as `connected_accounts.id = 3`.
- Google Business Profile OAuth redirect/callback routes are implemented at `/oauth/google-business/redirect` and `/oauth/google-business/callback`.
- Google Cloud rejects `.test` redirect URIs; the local Google Business OAuth client uses `http://localhost:8000/oauth/google-business/callback`.
- Google approved Business Profile API access for project `gmb-api-1966` under case `0-7599000041303` on June 19, 2026.
- The required Google services are enabled: `mybusiness.googleapis.com`, `mybusinessaccountmanagement.googleapis.com`, `mybusinessbusinessinformation.googleapis.com`, and `businessprofileperformance.googleapis.com`.
- Google Business OAuth saves every returned location as an active `gmb` connected account using the existing `connected_accounts` table.
- Google Business location `Clayton House` connected successfully as `connected_accounts.id = 4`.
- Google Business local post publish, delete, post analytics, and account analytics are implemented in `GoogleBusinessProfileAdapter`.
- The Google adapter qualifies Business Information v1 `locations/{locationId}` names with the saved account name before calling Local Posts v4.
- `POST /api/post` accepts `gmb`, `google_business`, and `google_business_profile` and publishes synchronously through connected Google locations.
- `POST /api/analytics/social` aggregates all active Google Business locations into one `gmb.analytics` block with per-location detail preserved.

## Verified Manually

Production onboarding status:

- Forge deployment for `social.clayton.house` completed successfully.
- Production app config now uses HTTPS callbacks for Facebook, Instagram, and Google Business Profile.
- Facebook connected successfully in production after adding the exact production callback URI to Meta's Facebook Login settings.
- Instagram connected successfully in production after adding the exact production callback URI to the Instagram Business Login settings.
- Google Business Profile connected successfully in production after adding the exact production callback URI to the Google OAuth client.

Facebook Page connected successfully during early testing. The current target scope set intentionally excludes `business_management`; see `PERMISSIONS.md`.

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

Both were confirmed visible on Facebook before deletion. Both were deleted through the API delete path used at the time.

Instagram publish smoke test:

- `social_posts.id = 7`
  - Published through the API with a real Clayton House shop image from `https://clayton.house/shop`.
  - Instagram media id: `18082816811438704`
  - Instagram container id: `18473686879099933`
  - Final local status after delete test: `published`
  - Delete attempt through the API was recorded locally, but Meta rejected `DELETE /18082816811438704` with `Unsupported delete request`.
  - The app now treats Instagram deletes as terminal `manual_delete_required`, not retryable failures.
  - Local target `social_post_targets.id = 6` has `delete_status = manual_delete_required` and `delete_attempts = 1`.

Google Business Profile publish/delete smoke test:

- `social_posts.id = 9`, `social_post_targets.id = 9`
  - Published to the connected `Clayton House` location through `PublishSocialPostTarget`.
  - Google local post id: `accounts/104922938822827779112/locations/10127574727985402861/localPosts/7803388860251743833`.
  - Google returned the post as `LIVE` after initial processing.
  - Deleted through `DeleteSocialPostTarget`.
  - Final local post and target delete status: `deleted`.
  - A read-after-delete request to Google returned `404`, confirming removal.

## Important Finding

The first Facebook publish attempt returned a Graph API 500:

```text
Please reduce the amount of data you're asking for, then retry your request
```

The post still appeared on Facebook. That means a provider call can complete its side effect but fail before the app receives the success response. The local retry created a second post.

Mitigation already applied:

- Facebook photo publish requests now use form-encoded parameters instead of JSON.
- The duplicate was recovered by reading the Page `posts` edge and adding a local record with the real Facebook `post_id`.
- Facebook photo publish now attempts automatic reconciliation after a 5xx Graph API response by checking the Page's recent `/posts` feed for an exact message match before the caller retries.

Still needed:

- Revisit reconciliation once multiple providers exist; current automatic recovery only covers Facebook Page photo publishes.

## Current Facebook Notes

- Local callback URL: `https://social.test/oauth/facebook/callback`
- Graph version: `v25.0`
- Scopes used for the current narrow local OAuth target:
  - `pages_show_list`
  - `pages_read_engagement`
  - `pages_manage_posts`
  - `pages_manage_engagement`
  - `pages_read_user_content`

The `business_management` permission produced stronger Meta consent warnings during early testing. It should be treated as a previous discovery workaround, not a round-one default. See `PERMISSIONS.md` for the permission minimization plan.

The local `.env` scope request should be:

```dotenv
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,pages_read_user_content
```

Meta's broad Page use case still showed `business_management` as ready for testing and did not expose a remove action from the permission table. Keep it out of our OAuth request unless Page discovery/token minting proves it is required.

The callback now discovers Facebook Pages only through `/me/accounts`. It no longer calls `/me/assigned_pages`, `/me/businesses`, `owned_pages`, or `client_pages` during the normal connection path.

## Permission Decisions

- Facebook round one is Page-only:
  - user chooses a Page,
  - app publishes image posts,
  - app deletes only posts created or recovered by this app,
  - app adds sold-item comments to posts it created,
  - app reads organic post status/engagement.
- Vendors later connect their own accounts.
- Instagram feed-image publishing uses Instagram Login with Instagram API scopes.
- Catalog API is phase 2 or 3.
- Boosting/ads are phase 2.
- Paid stats come with the ads phase; organic status/engagement is round one.
- Meta dashboard previously had `instagram_business_basic` and `instagram_business_content_publish` ready for testing for the Instagram API use case.
- Ayrshare-compatible Instagram analytics/comments require adding `instagram_business_manage_insights` and, if Instagram sold-item comments stay enabled, `instagram_business_manage_comments`.
- Instagram messages, shopping, and legacy Page-linked Instagram publishing permissions are intentionally not enabled for round one.
- Instagram delete is not implemented with the current permission set.
- Instagram local connection testing should start from `https://social.test/connected-accounts`, not `localhost:8000`, so the OAuth state cookie and callback host match.
- Local callback host tracking: Facebook and Instagram use `https://social.test`; Google Business Profile uses `http://localhost:8000` because Google rejects `.test` redirect URIs.

Target Facebook scopes to test next:

```dotenv
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,pages_read_user_content
```

Avoid round-one requests for:

- `business_management`, unless Page selection/token minting still requires it.
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

These routes use the owner assigned to the bearer token. Request payloads do not select an owner or target account directly. Unsupported platforms such as `twitter` and `pinterest` currently return partial failures. Google Business comments also return a clear partial failure because Google local posts do not support comments through this adapter.

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

After removing the legacy `/api/posts` public API syntax:

```bash
php artisan test --compact tests/Feature/ApiTokensControllerTest.php tests/Feature/Api/AyrshareCompatibilityApiTest.php tests/Feature/Api/SocialPublishingApiTest.php tests/Feature/Social/FacebookPageAdapterTest.php tests/Feature/Social/InstagramBusinessAdapterTest.php tests/Feature/Social/InstagramOAuthControllerTest.php tests/Feature/Social/FacebookOAuthControllerTest.php tests/Feature/Social/SocialPostJobsTest.php
```

Last targeted result:

```text
35 tests passed, 159 assertions
```

Full suite after the API cleanup:

```bash
php artisan test --compact
```

After adding Google Business Profile OAuth, publishing, delete, comments failure, and analytics:

```bash
php artisan test --compact tests/Feature/Social/GoogleBusinessOAuthControllerTest.php tests/Feature/Social/GoogleBusinessProfileAdapterTest.php tests/Feature/Api/AyrshareCompatibilityApiTest.php
```

Last targeted result:

```text
16 tests passed, 86 assertions
```

After normalizing Business Information v1 location names for Local Posts v4:

```bash
php artisan test --compact tests/Feature/Social/GoogleBusinessProfileAdapterTest.php --filter='publishes a standard google business local post'
```

Last targeted result:

```text
1 test passed, 3 assertions
```

Last full result:

```text
77 tests passed, 291 assertions
```

## Next Steps

1. Retest Facebook OAuth with `pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,pages_read_user_content`.
2. In Meta use-case settings, enable only the Page permissions needed for Page selection, Page organic read, Page post management, and sold-item comments.
3. Confirm new OAuth debug records only show the `/me/accounts` page discovery response.
4. Manually confirm `social_posts.id = 7` is visible on Instagram.
5. Manually smoke test the `/api/post` flow with the real POS2024 bearer token after creating an owner-bound token.
6. Adjust Google Business metric names if the first production Performance API response reports an unavailable metric for the connected profile.
7. Add reconciliation for ambiguous Facebook publish failures across the synchronous compatibility path if manual testing exposes duplicate risk.
8. Decide whether scheduled posts are owned entirely here or triggered by the upstream website scheduler.
9. Prepare narrow Meta App Review explanations from `PERMISSIONS.md`.
10. Add production queue monitoring later only if the API moves back to queued publish/delete work.
