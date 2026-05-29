# Ayrshare API Replacement Contract

This project currently treats Ayrshare as the social posting provider. A low-change replacement should preserve the Ayrshare-shaped HTTP contract and response JSON so the existing `AyrshareService` callers can keep working with minimal app changes.

## Goal

Build a drop-in API replacement that supports:

- Publishing social posts with optional images.
- Returning per-platform native post IDs and public URLs.
- Deleting previously published social posts.
- Adding sold-item comments to existing posts.
- Capturing post-level social stats for popularity scoring.
- Capturing account-level social stats for reporting.

The smallest application-side change should be making the provider base URL configurable. The current service hard-codes `https://api.ayrshare.com/api` in `app/Services/AyrshareService.php`.

## Authentication

Current requests use:

```http
Authorization: Bearer {AYRSHARE_API_KEY}
Accept: application/json
Content-Type: application/json
```

The replacement should accept the same bearer-token style authentication so the app can continue using `config('services.ayrshare.api_key')`.

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

- `platforms` may include `facebook`, `instagram`, `twitter`, `pinterest`, and `gmb`.
- If `twitter` is included, the app adds `twitterOptions.longPost = true`.
- If `pinterest` is included for item posts, the app sends `pinterestOptions.link`.
- `mediaUrls` is always sent as an array and may contain a nullable/empty value depending on whether the local post has an image.
- Current posts are image/post text workflows. Video, reels, stories, threads, and scheduling are not used by this app.

### Success Response

The app stores the entire response in `posts.ayrshare_response`, stores `id` in `posts.ayrshare_post_id`, and stores `status` in `posts.status`.

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
      "id": "538039486758479120",
      "platform": "pinterest",
      "status": "success",
      "postUrl": "https://www.pinterest.com/pin/538039486758479120/"
    },
    {
      "id": "6502751811401751421",
      "platform": "gmb",
      "status": "success",
      "type": "localPosts",
      "mediaFormat": "photo",
      "postUrl": "https://local.google.com/place?id=..."
    }
  ],
  "errors": [],
  "validate": true
}
```

### Partial Failure Response

Ayrshare can return `status: "error"` even when some platforms succeed. The replacement should preserve this behavior because the app still displays successful `postIds`.

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

Allowed `status` values should stay compatible with `App\Enums\PostStatus`: `success`, `error`, and `pending`.

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

For no-platform requests, the replacement should either comment on every supported platform post in the group or return a partial failure response that clearly identifies unsupported platforms.

## Get Post Info

The service has a method for this endpoint:

```http
GET /api/post/{id}
```

It is not currently used by active runtime code. It appears only in a commented historical import migration. Implementing it is useful for compatibility but not required for current posting workflows.

## Post Analytics

Used by `UpdateSocialMediaLikesJob`, although that job currently returns early because Ayrshare rate limits made the path unusable.

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

The app stores the full JSON in `posts.stats_response` and reads these exact paths:

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

Used by `CaptureSocialStatsJob`, scheduled daily in production.

```http
POST /api/analytics/social
```

```json
{
  "platforms": ["facebook", "instagram", "twitter", "gmb", "pinterest"],
  "quarters": 1
}
```

The app expects a top-level key per platform, each with an `analytics` object. It stores only the nested `analytics` object in `social_stats.stats`.

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
      "businessImpressionsDesktopSearch": 0
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

## Local Data Compatibility Notes

Local database evidence showed:

- `posts.ayrshare_response` is the source for displayed social platform links.
- `posts.ayrshare_post_id` is the provider group ID used for delete, comments, and stats.
- `posts.stats_response` is the source for popularity scoring.
- `social_stats.stats` stores only each platform's account-level `analytics` object.
- A partial platform failure can still create usable social links for the successful platforms.

## Operational Recommendation

The replacement API should own platform-specific rate limiting, retries, and analytics caching. The app currently has an hourly post-stats dispatcher, but the actual stats update job is disabled because Ayrshare rate limits shut it down. If the replacement API caches stats by provider group ID and refreshes internally, this app can eventually re-enable popularity scoring without sending too many direct social API requests.

## Minimal App Change Later

When ready to point this app at the replacement API, make the base URL configurable while preserving the existing service methods and response shape:

```php
protected $baseUrl;

public function __construct()
{
    $this->apiKey = config('services.ayrshare.api_key');
    $this->baseUrl = config('services.ayrshare.base_url', 'https://api.ayrshare.com/api');
}
```

Then add `AYRSHARE_BASE_URL` to `config/services.php`. No caller should need to change if the replacement preserves the contract above.
