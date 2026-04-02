"use client";

import { useCallback, useState } from "react";

interface CursorPaginationResult<T> {
  items: T[];
  isLoading: boolean;
  isLoadingMore: boolean;
  hasMore: boolean;
  error: string | null;
  loadInitial: () => Promise<void>;
  loadMore: () => Promise<void>;
  reset: () => void;
}

interface CursorPaginatedResponse<T> {
  data: T[];
  meta: {
    next_cursor: string | null;
    prev_cursor: string | null;
    per_page: number;
    path: string;
  };
}

/**
 * Reusable hook for cursor-paginated API endpoints.
 */
export function useCursorPagination<T>(
  fetchFn: (cursor?: string) => Promise<CursorPaginatedResponse<T>>,
): CursorPaginationResult<T> {
  const [items, setItems] = useState<T[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isLoadingMore, setIsLoadingMore] = useState(false);
  const [nextCursor, setNextCursor] = useState<string | null>(null);
  const [hasMore, setHasMore] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const loadInitial = useCallback(async () => {
    setIsLoading(true);
    setError(null);
    try {
      const response = await fetchFn();
      setItems(response.data);
      setNextCursor(response.meta.next_cursor);
      setHasMore(response.meta.next_cursor !== null);
    } catch (err) {
      console.error("Failed to load items:", err);
      setError("Failed to load activity log.");
    } finally {
      setIsLoading(false);
    }
  }, [fetchFn]);

  const loadMore = useCallback(async () => {
    if (!nextCursor || isLoadingMore) return;

    setIsLoadingMore(true);
    setError(null);
    try {
      const response = await fetchFn(nextCursor);
      setItems((prev) => [...prev, ...response.data]);
      setNextCursor(response.meta.next_cursor);
      setHasMore(response.meta.next_cursor !== null);
    } catch (err) {
      console.error("Failed to load more items:", err);
      setError("Failed to load more items.");
    } finally {
      setIsLoadingMore(false);
    }
  }, [fetchFn, nextCursor, isLoadingMore]);

  const reset = useCallback(() => {
    setItems([]);
    setNextCursor(null);
    setHasMore(true);
    setError(null);
  }, []);

  return {
    items,
    isLoading,
    isLoadingMore,
    hasMore,
    error,
    loadInitial,
    loadMore,
    reset,
  };
}
