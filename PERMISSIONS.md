# Meta Permission Plan

Last updated: May 29, 2026

Goal: request the smallest Meta permission set that supports the product. The app should not ask for broad Business control unless a specific connection flow proves it is required.

## Product Decisions

- Facebook round one:
  - Users choose a Page during OAuth/connection.
  - The app publishes image posts to that Page.
  - The app deletes only posts created or recovered by this app.
  - The Ayrshare-compatible API can add sold-item comments to posts it created.
  - The app may read existing Page posts and organic engagement/status data.
- Vendor rollout:
  - Vendors connect their own Facebook/Instagram accounts.
  - Vendors should not grant Clayton House broad control of their Business unless there is no narrower viable path.
- Instagram:
  - Set up if it can use the same Meta/Facebook connection screens.
  - Target the most broadly compatible path for vendor Business/Creator accounts.
  - Round one is feed-image publishing, media analytics, and sold-item comments on app-created media.
  - Ayrshare-compatible post analytics requires media insights.
  - Ayrshare-compatible comments require comment management if Instagram sold-item comments are enabled.
  - Published feed media deletion is manual unless Meta adds a supported delete endpoint.
- Deferred:
  - Catalog API is phase 2 or 3.
  - Boosting/ads are phase 2.
  - Paid stats come with the ads/boosting phase.

## Use Case Matrix

| Order | Meta use case | Round | Needed capability | Candidate permissions/features | Decision |
| --- | --- | --- | --- | --- | --- |
| 1 | Manage everything on your Page | 1 | List selectable Pages | `pages_show_list` | Keep |
| 1 | Manage everything on your Page | 1 | Read Page posts and organic status/engagement | `pages_read_engagement` | Keep |
| 1 | Manage everything on your Page | 1 | Publish and delete app-created Page posts | `pages_manage_posts` | Keep |
| 1 | Manage everything on your Page | 1 | Add sold-item comments to app-created posts | `pages_manage_engagement` | Keep for Ayrshare compatibility |
| 1 | Manage everything on your Page | 1 | Required dependency for Page comment management | `pages_read_user_content` | Keep for Facebook comments |
| 1 | Manage everything on your Page | 1 | Publish videos | `publish_video` | Do not request now |
| 1 | Manage everything on your Page | 1 | Discover Pages through Business assets | `business_management` | Do not request |
| 2 | Manage messaging & content on Instagram | 1 or 1.5 | Identify Instagram professional account | `instagram_business_basic` | Keep for Instagram Login path |
| 2 | Manage messaging & content on Instagram | 1 or 1.5 | Publish feed images | `instagram_business_content_publish` | Keep for Instagram Login path |
| 2 | Manage messaging & content on Instagram | 1.5 | Media insights for post analytics | `instagram_business_manage_insights` | Keep for Ayrshare compatibility |
| 2 | Manage messaging & content on Instagram | 1.5 | Add sold-item comments to app-created media | `instagram_business_manage_comments` | Keep for Ayrshare compatibility |
| 2 | Manage messaging & content on Instagram | Later | DMs, stories, reels | Instagram manage messages/story/reels-related permissions | Do not request now |
| 3 | Manage products with Catalog API | 2 or 3 | Manage Meta product catalog | `catalog_management` | Defer |
| 4 | Measure ad performance data with Marketing API | 2 | Paid ad metrics | `ads_read`, possibly `read_insights` | Defer |
| 5 | Create & manage ads with Marketing API | 2 | Boost posts or manage campaigns | `ads_management`, related ad account/business permissions | Defer |

## Round-One Target Permission Set

Start with this for Facebook Page publishing, deleting, organic reads, and sold-item comments:

```dotenv
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement,pages_read_user_content
```

`pages_read_user_content` is included because Meta treats it as a dependency of `pages_manage_engagement` for Page comment management. Without it, Facebook OAuth can fail with an invalid-scope error for `pages_read_user_content`.

Do not add `business_management` to `FACEBOOK_SCOPES`. The current selected-Page OAuth flow can discover the connected Page and mint the Page token without requesting it. Meta may still show `business_management` as ready for testing or required inside the Pages API use-case UI, and a previously connected Facebook account may still report it in `/me/permissions`; the app should not request or persist it as part of the connected account scopes.

Do not request these in round one:

```text
publish_video
catalog_management
ads_read
ads_management
read_insights
```

## Instagram Dashboard Settings

Current Meta dashboard setting for the Instagram API use case:

- `instagram_business_basic`: ready for testing.
- `instagram_business_content_publish`: ready for testing.
- `instagram_business_manage_insights`: needed for Ayrshare-compatible media analytics.
- `instagram_business_manage_comments`: needed for Ayrshare-compatible sold-item comments.

These match the narrow Instagram Login path for identifying a professional account and publishing organic feed media.

Meta's Instagram media docs still list deleting published media as unsupported. The app records Instagram delete requests as `manual_delete_required` instead of retrying them as provider failures.

Do not add these in round one:

```text
instagram_business_manage_messages
instagram_content_publish
instagram_manage_comments
instagram_manage_contents
instagram_manage_engagement
instagram_manage_insights
instagram_manage_messages
instagram_shopping_tag_products
```

Meta's Instagram Login setup screen also presents message permissions. Those do not match the round-one product need, so leave them off unless the product expands to DMs or moderation beyond app-created post comments.

The product need is feed-image publishing plus Ayrshare-compatible comments and analytics. Do not add DMs, stories, reels, shopping, or paid insights in round one.

## App Review Framing

Use plain, narrow explanations:

- `pages_show_list`: lets a user choose which Page this app should publish to.
- `pages_read_engagement`: lets the app verify Page access and read organic post status/engagement for posts it manages.
- `pages_manage_posts`: lets the app publish scheduled image posts and delete posts it created.
- `pages_manage_engagement`: lets the app add sold-item comments to posts it created.
- `pages_read_user_content`: required by Meta when requesting Page comment management through `pages_manage_engagement`.
- `instagram_business_basic`: lets the app identify the connected professional account.
- `instagram_business_content_publish`: lets the app publish feed-image posts.
- `instagram_business_manage_insights`: lets the app return post analytics through the Ayrshare-compatible API.
- `instagram_business_manage_comments`: lets the app add sold-item comments to app-created media if Instagram comments stay enabled.

Avoid language that implies full Business control, ad management, comment moderation, or user surveillance.

## Docs To Use While Ticking Boxes

- Meta Permissions Reference: https://developers.facebook.com/docs/permissions/
- Facebook Pages API: https://developers.facebook.com/docs/pages-api/
- Pages API Manage Pages: https://developers.facebook.com/docs/pages-api/manage-pages/
- Instagram content publishing: https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/content-publishing/
- Instagram API with Instagram Login: https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/
