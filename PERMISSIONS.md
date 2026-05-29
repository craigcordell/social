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
  - Round one is feed-image publishing only.
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
| 1 | Manage everything on your Page | 1 | Read follower/user content | `pages_read_user_content` | Do not request now |
| 1 | Manage everything on your Page | 1 | Publish videos | `publish_video` | Do not request now |
| 1 | Manage everything on your Page | 1 | Discover Pages through Business assets | `business_management` | Avoid unless selected-Page flow cannot work without it |
| 2 | Manage messaging & content on Instagram | 1 or 1.5 | Identify Instagram professional account | `instagram_business_basic` | Keep for Instagram Login path |
| 2 | Manage messaging & content on Instagram | 1 or 1.5 | Publish feed images | `instagram_business_content_publish` | Keep for Instagram Login path |
| 2 | Manage messaging & content on Instagram | 1.5 | Media insights for post analytics | `instagram_business_manage_insights` | Keep for Ayrshare compatibility |
| 2 | Manage messaging & content on Instagram | 1.5 | Add sold-item comments to app-created media | `instagram_business_manage_comments` | Keep only if Instagram comments are enabled |
| 2 | Manage messaging & content on Instagram | Later | DMs, stories, reels | Instagram manage messages/story/reels-related permissions | Do not request now |
| 3 | Manage products with Catalog API | 2 or 3 | Manage Meta product catalog | `catalog_management` | Defer |
| 4 | Measure ad performance data with Marketing API | 2 | Paid ad metrics | `ads_read`, possibly `read_insights` | Defer |
| 5 | Create & manage ads with Marketing API | 2 | Boost posts or manage campaigns | `ads_management`, related ad account/business permissions | Defer |

## Round-One Target Permission Set

Start with this for Facebook Page publishing, deleting, organic reads, and sold-item comments:

```dotenv
FACEBOOK_SCOPES=pages_show_list,pages_read_engagement,pages_manage_posts,pages_manage_engagement
```

Only add `business_management` if the selected-Page connection flow cannot discover or mint a Page token for the selected Page without it.

Do not request these in round one:

```text
pages_read_user_content
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
- `instagram_business_manage_comments`: needed only if Instagram sold-item comments are enabled.

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
