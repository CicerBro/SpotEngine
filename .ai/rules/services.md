---
paths:
  - 'app/Services/{ListingCacheService,SpotMutationService}.php'
---

# Services

## Rotate cache generation after data and index changes
ListingCacheService::flush must rotate the shared generation even when listing caching is disabled because unread counts use it. Invalidate after database mutations and after successful external index synchronization. Keep cached paginator paths and query strings neutral; clone and apply the current request before rendering.
