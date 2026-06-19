# Ayrshare API Replacement Contract

This project implements the Ayrshare-shaped HTTP contract that the existing POS2024 `AyrshareService` callers expect. The goal is a low-change replacement: preserve request/response JSON, make the provider base URL configurable upstream, and let this app own provider-specific adapters.

## Goal

Maintain a drop-in API replacement that supports:

- Publishing social posts with optional images.
- Returning per-platform native post IDs and public URLs.
- Deleting previously published social posts.
- Adding sold-item comments to existing posts.
- Capturing post-level social stats for popularity scoring.
- Capturing account-level social stats for reporting.

The smallest POS2024-side change should be making the provider base URL configurable. The current service hard-codes `https://api.ayrshare.com/api` in `app/Services/AyrshareService.php`.

## Authentication

Current requests use:

```http
Authorization: Bearer {AYRSHARE_API_KEY}
Accept: application/json
Content-Type: application/json
```

This app accepts the same bearer-token style authentication so upstream callers can continue using `config('services.ayrshare.api_key')`.

In this Laravel app, each bearer token is assigned to exactly one local `owners` row. The token owner is the source of truth for eligible connected platform accounts; publish requests do not include `owner_id` or platform account IDs.

## Endpoints Used By This App

```http
POST   /api/post
DELETE /api/post
POST   /api/analytics/post
GET    /api/post/{id}
POST   /api/comments
POST   /api/analytics/social
```

## Publish Post

Used by:

- `app/Filament/Resources/PostResource/Pages/AddPost.php`
- `app/Filament/Resources/PostResource/Pages/AddItemPost.php`

### Request

```http
POST /api/post
```

```json
{
  "post": "message text",
  "platforms": ["facebook", "twitter", "instagram", "pinterest", "gmb"],
  "mediaUrls": ["https://www.clayton.house/path/to/image.jpg"],
  "twitterOptions": {
    "longPost": true
  },
  "pinterestOptions": {
    "link": "https://www.clayton.house/product_info/123"
  }
}
```

### Request Notes

- `platforms` may include `facebook`, `instagram`, and `gmb` today. `twitter`/`x` and `pinterest` are accepted as request values but currently return Ayrshare-shaped partial failures because adapters are not implemented.
- `google_business` and `google_business_profile` are accepted as aliases and normalize to `gmb`.
- If `twitter` is included upstream, POS2024 may add `twitterOptions.longPost = true`; this API currently returns a partial failure for Twitter.
- If `pinterest` is included upstream for item posts, POS2024 may send `pinterestOptions.link`; this API stores that link as the post `link_url` and currently returns a partial failure for Pinterest. Google uses the same link as a `LEARN_MORE` CTA when present.
- `mediaUrls` is always sent as an array and may contain a nullable/empty value depending on whether the local post has an image.
- Current posts are image/post text workflows. Video, reels, stories, threads, and scheduling are not used by this app.

### Success Response

The replacement stores the canonical post request in `social_posts` and stores each platform result in `social_post_targets`, including native provider IDs, public URLs, normalized status, and the provider response.

```json
{
  "id": "provider-group-post-id",
  "status": "success",
  "post": "message text",
  "refId": "optional-provider-reference",
  "fbId": "optional-facebook-id",
  "postIds": [
    {
      "id": "358179240887925_1388223683329471",
      "platform": "facebook",
      "status": "success",
      "postUrl": "https://www.facebook.com/358179240887925/posts/1388223683329471"
    },
    {
      "id": "18074794631665497",
      "platform": "instagram",
      "status": "success",
      "postUrl": "https://www.instagram.com/p/DYUuDJDFSQp/"
    },
    {
      "id": "accounts/123/locations/456/localPosts/789",
      "platform": "gmb",
      "status": "success",
      "postUrl": "https://local.google.com/place?id=..."
    }
  ],
  "errors": [],
  "validate": true
}
```

### Partial Failure Response

Ayrshare can return `status: "error"` even when some platforms succeed. This API preserves that behavior because the app still displays successful `postIds`.

```json
{
  "id": "provider-group-post-id",
  "status": "error",
  "post": "message text",
  "postIds": [
    {
      "id": "358179240887925_1388223683329471",
      "platform": "facebook",
      "status": "success",
      "postUrl": "https://www.facebook.com/358179240887925/posts/1388223683329471"
    }
  ],
  "errors": [
    {
      "code": 156,
      "action": "post",
      "status": "error",
      "message": "Twitter is not linked.",
      "platform": "twitter"
    }
  ],
  "validate": true
}
```

Allowed response `status` values stay compatible with the existing Ayrshare callers: `success` and `error` in the current synchronous path.

## Delete Post

Used by `app/Filament/Resources/PostResource.php` before deleting a local post.

```http
DELETE /api/post
```

```json
{
  "id": "provider-group-post-id"
}
```

The app does not currently inspect the delete response. Return JSON anyway for observability.

```json
{
  "status": "success",
  "id": "provider-group-post-id"
}
```

## Add Comment

Used when an item linked to a post sells out. The app calls this through `Post::updateSocialMediaAsSold()`.

```http
POST /api/comments
```

```json
{
  "id": "provider-group-post-id",
  "comment": "Sorry, Item Name has been sold."
}
```

The app supports an optional `platform`, but the current sold-item path does not pass one.

```json
{
  "id": "provider-group-post-id",
  "comment": "Sorry, Item Name has been sold.",
  "platform": "facebook"
}
```

For no-platform requests, this API comments on every supported published platform target in the group or returns a partial failure response that clearly identifies unsupported platforms.

### Meta Permissions For Comments

Facebook comments on app-created Page posts require:

- `pages_show_list`
- `pages_read_engagement`
- `pages_manage_posts`
- `pages_manage_engagement`
- `pages_read_user_content`

`pages_read_user_content` is included because Meta requires it when requesting Page comment management with `pages_manage_engagement`. Do not add `business_management` to the requested Facebook OAuth scopes for this workflow.

Instagram comments on app-created media require:

- `instagram_business_basic`
- `instagram_business_content_publish`
- `instagram_business_manage_insights`
- `instagram_business_manage_comments`

Google Business Profile local posts do not support sold-item comments through this adapter. The API returns an Ayrshare-shaped partial failure for `gmb` comment attempts rather than silently no-oping.

## Get Post Info

```http
GET /api/post/{id}
```

Returns the stored Ayrshare-shaped group post response for a local `social_posts.id` owned by the bearer token's owner.

## Post Analytics

Intended for the upstream popularity-scoring path that previously used Ayrshare post analytics.

### Request For Provider-Created Post

```http
POST /api/analytics/post
```

```json
{
  "id": "provider-group-post-id"
}
```

### Request For Legacy Native Post

`Post::updateOldStats()` can request stats for legacy posts that were not created by Ayrshare.

```json
{
  "platforms": ["facebook"],
  "id": "native-platform-post-id",
  "searchPlatformId": true
}
```

### Response Shape

The upstream app stores the full JSON in `posts.stats_response` and historically reads these paths. This API currently implements Facebook, Instagram, and Google Business Profile; Twitter and Pinterest remain contract placeholders until adapters exist.

- `facebook.analytics.likeCount`
- `facebook.analytics.sharesCount`
- `facebook.analytics.commentsCount`
- `facebook.analytics.reactions.total`
- `instagram.analytics.likeCount`
- `instagram.analytics.sharesCount`
- `instagram.analytics.commentsCount`
- `pinterest.analytics.save`
- `pinterest.analytics.totalComments`
- `pinterest.analytics.totalReactions`
- `twitter.analytics.publicMetrics.likeCount`
- `twitter.analytics.publicMetrics.retweetCount`
- `twitter.analytics.publicMetrics.replyCount`
- `twitter.analytics.publicMetrics.quoteCount`

Minimum compatible response:

```json
{
  "id": "provider-group-post-id",
  "status": "success",
  "facebook": {
    "id": "native-facebook-id",
    "postUrl": "https://www.facebook.com/...",
    "analytics": {
      "likeCount": 0,
      "sharesCount": 0,
      "commentsCount": 0,
      "reactions": {
        "total": 0
      }
    }
  },
  "instagram": {
    "id": "native-instagram-id",
    "postUrl": "https://www.instagram.com/p/...",
    "analytics": {
      "likeCount": 0,
      "sharesCount": 0,
      "commentsCount": 0
    }
  },
  "pinterest": {
    "id": "native-pinterest-id",
    "postUrl": "https://www.pinterest.com/pin/...",
    "analytics": {
      "save": 0,
      "totalComments": 0,
      "totalReactions": 0
    }
  },
  "twitter": {
    "id": "native-twitter-id",
    "postUrl": "https://twitter.com/TheClaytonHouse/status/...",
    "analytics": {
      "publicMetrics": {
        "likeCount": 0,
        "retweetCount": 0,
        "replyCount": 0,
        "quoteCount": 0
      }
    }
  }
}
```

## Account-Level Social Analytics

Intended for the upstream account-level stats path.

```http
POST /api/analytics/social
```

```json
{
  "platforms": ["facebook", "instagram", "twitter", "gmb", "pinterest"],
  "quarters": 1
}
```

The upstream app expects a top-level key per requested platform, each with an `analytics` object. It stores only the nested `analytics` object in `social_stats.stats`. This API currently returns implemented analytics for Facebook, Instagram, and Google Business Profile; unsupported platforms return partial failures.

Minimum compatible response:

```json
{
  "facebook": {
    "analytics": {
      "id": "page-id",
      "name": "Clayton House Marketplace",
      "followersCount": 0,
      "pagePostEngagements": 0,
      "pagePostsImpressions": 0
    }
  },
  "instagram": {
    "analytics": {
      "id": "instagram-business-id",
      "username": "claytonhousemarketplace",
      "followersCount": 0,
      "likeCount": 0,
      "commentsCount": 0,
      "reachCount": 0,
      "viewsCount": 0
    }
  },
  "twitter": {
    "analytics": {
      "id": "twitter-user-id",
      "username": "TheClaytonHouse",
      "followersCount": 0,
      "followingCount": 0,
      "tweetCount": 0,
      "likeCount": 0
    }
  },
  "gmb": {
    "analytics": {
      "callClicks": 0,
      "websiteClicks": 0,
      "businessDirectionRequests": 0,
      "businessImpressionsMobileMaps": 0,
      "businessImpressionsDesktopMaps": 0,
      "businessImpressionsMobileSearch": 0,
      "businessImpressionsDesktopSearch": 0,
      "locations": []
    }
  },
  "pinterest": {
    "analytics": {
      "save": 0,
      "pinClick": 0,
      "engagement": 0,
      "impression": 0,
      "outboundClick": 0
    }
  }
}
```

For `gmb`, the replacement aggregates all active Google Business connected locations for the token owner into the top-level `gmb.analytics` values and includes the per-location API result in `gmb.analytics.locations`.

## Local Data Compatibility Notes

Local database evidence showed:

- `posts.ayrshare_response` is the source for displayed social platform links.
- `posts.ayrshare_post_id` is the provider group ID used for delete, comments, and stats.
- `posts.stats_response` is the source for popularity scoring.
- `social_stats.stats` stores only each platform's account-level `analytics` object.
- A partial platform failure can still create usable social links for the successful platforms.

## Operational Recommendation

This API should own platform-specific rate limiting, retries, and analytics caching as those needs become real. Publishing/deleting is synchronous today; queue-backed retries can be reintroduced later using the existing job classes if provider latency or retry behavior requires it.

## Minimal App Change Later

When ready to point POS2024 at this replacement API, make the base URL configurable while preserving the existing service methods and response shape:

```php
protected $baseUrl;

public function __construct()
{
    $this->apiKey = config('services.ayrshare.api_key');
    $this->baseUrl = config('services.ayrshare.base_url', 'https://api.ayrshare.com/api');
}
```

Then add `AYRSHARE_BASE_URL` to POS2024 `config/services.php`. No caller should need to change if the replacement preserves the contract above.
