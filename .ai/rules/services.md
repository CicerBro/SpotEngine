---
paths:
  - 'app/Services/{ListingCacheService,SpotMutationService}.php'
  - app/Services/AsyncSpotRetrieverService.php
---

# Services

## Rotate cache generation after data and index changes
ListingCacheService::flush must rotate the shared generation even when listing caching is disabled because unread counts use it. Invalidate after database mutations and after successful external index synchronization. Keep cached paginator paths and query strings neutral; clone and apply the current request before rendering.

## Always collect a pending upsert child on retrieval exit
Fetching the next NNTP batch can fail while the previous batch's upsert child is still running. Drain its pipe and reap it in finally; commit moderation/checkpoint only after confirmed child success. Clear pending tracking before collection to avoid double waits. Preserve the primary failure and report secondary cleanup failures.
