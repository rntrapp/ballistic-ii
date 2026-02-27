import type {
  Item,
  ItemScope,
  Project,
  Status,
  User,
  UserLookup,
  NotificationsResponse,
  CognitivePhaseResponse,
} from "@/types";
import { getAuthHeaders, clearToken } from "./auth";

const API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL || "";

type ListParams = {
  project_id?: string;
  status?: Status | "all";
  scope?: ItemScope;
  assigned_to_me?: boolean;
  delegated?: boolean;
  include_completed?: boolean;
};

/**
 * Handle API response and check for auth errors
 */
async function handleResponse<T>(response: Response): Promise<T> {
  if (response.status === 401) {
    // Token expired or invalid - clear it and redirect to login
    clearToken();
    if (typeof window !== "undefined") {
      window.location.href = "/login";
    }
    throw new Error("Unauthorised");
  }

  if (!response.ok) {
    const error = await response
      .json()
      .catch(() => ({ message: "Request failed" }));
    throw new Error(error.message || "Request failed");
  }

  // Handle 204 No Content
  if (response.status === 204) {
    return {} as T;
  }

  return response.json();
}

/**
 * Some endpoints wrap the payload in a { data } envelope.
 * Normalise responses so callers always receive the entity directly.
 */
function extractData<T>(payload: T | { data?: T }): T {
  if (payload && typeof payload === "object" && "data" in payload) {
    const unwrapped = (payload as { data?: T }).data;
    if (unwrapped !== undefined && unwrapped !== null) {
      return unwrapped;
    }
  }
  return payload as T;
}

/**
 * Build a URL for API requests
 */
function buildUrl(
  path: string,
  params?: Record<string, string | undefined>,
): string {
  const baseUrl =
    API_BASE ||
    (typeof window !== "undefined"
      ? window.location.origin
      : "http://localhost:3000");
  const url = new URL(path, baseUrl);

  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value) url.searchParams.set(key, value);
    });
  }

  return url.toString();
}

// ─────────────────────────────────────────────────────────────────────────────
// User Profile
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch the authenticated user's profile.
 */
export async function fetchUser(): Promise<User> {
  const response = await fetch(buildUrl("/api/user"), {
    method: "GET",
    headers: getAuthHeaders(),
    cache: "no-store",
  });

  const payload = await handleResponse<User | { data?: User }>(response);
  return extractData(payload);
}

/**
 * Update the authenticated user's profile.
 */
export async function updateUser(
  data: Partial<
    Pick<User, "name" | "email" | "phone" | "notes" | "feature_flags">
  >,
): Promise<User> {
  const response = await fetch(buildUrl("/api/user"), {
    method: "PATCH",
    headers: getAuthHeaders(),
    body: JSON.stringify(data),
  });

  const payload = await handleResponse<User | { data?: User }>(response);
  return extractData(payload);
}

/**
 * Fetch all items for the authenticated user
 *
 * By default, returns items owned by the user that are NOT assigned to anyone else.
 * Completed/cancelled items are excluded by default (use include_completed=true to include).
 * Use assigned_to_me=true to get items assigned to you by other users.
 * Use delegated=true to get items you own that are assigned to others.
 * Use scope to filter by scheduled date (active/planned/all).
 */
export async function fetchItems(params?: ListParams): Promise<Item[]> {
  const url = buildUrl("/api/items", {
    project_id: params?.project_id,
    status: params?.status !== "all" ? params?.status : undefined,
    scope: params?.scope,
    assigned_to_me: params?.assigned_to_me ? "true" : undefined,
    delegated: params?.delegated ? "true" : undefined,
    include_completed: params?.include_completed ? "true" : undefined,
  });

  const response = await fetch(url, {
    method: "GET",
    headers: getAuthHeaders(),
    cache: "no-store",
  });

  const payload = await handleResponse<Item[] | { data?: Item[] }>(response);
  const items = extractData(payload);
  return Array.isArray(items) ? items : [];
}

/**
 * Update an item's status
 */
export async function updateStatus(id: string, status: Status): Promise<Item> {
  const response = await fetch(buildUrl(`/api/items/${id}`), {
    method: "PATCH",
    headers: getAuthHeaders(),
    body: JSON.stringify({ status }),
  });

  const payload = await handleResponse<Item | { data?: Item }>(response);
  return extractData(payload);
}

/**
 * Create a new item
 */
export async function createItem(payload: {
  title: string;
  description?: string;
  status: Status;
  project_id?: string | null;
  position?: number;
  scheduled_date?: string | null;
  due_date?: string | null;
  recurrence_rule?: string | null;
  recurrence_strategy?: string | null;
  assignee_id?: string | null;
  cognitive_load_score?: number | null;
}): Promise<Item> {
  const response = await fetch(buildUrl("/api/items"), {
    method: "POST",
    headers: getAuthHeaders(),
    body: JSON.stringify({
      title: payload.title,
      description: payload.description || null,
      status: payload.status,
      project_id: payload.project_id || null,
      position: payload.position ?? 0,
      scheduled_date: payload.scheduled_date || null,
      due_date: payload.due_date || null,
      recurrence_rule: payload.recurrence_rule || null,
      recurrence_strategy: payload.recurrence_strategy || null,
      assignee_id: payload.assignee_id || null,
      cognitive_load_score: payload.cognitive_load_score ?? null,
    }),
  });

  const data = await handleResponse<Item | { data?: Item }>(response);
  return extractData(data);
}

/**
 * Bulk-reorder items via the dedicated reorder endpoint.
 * Sends a single POST instead of N individual PATCH requests.
 */
export async function reorderItems(
  orderedItems: { id: string; position: number }[],
): Promise<void> {
  if (orderedItems.length === 0) return;

  const response = await fetch(buildUrl("/api/items/reorder"), {
    method: "POST",
    headers: getAuthHeaders(),
    body: JSON.stringify({ items: orderedItems }),
  });

  await handleResponse<{ message: string }>(response);
}

/**
 * Update an item's fields
 */
export async function updateItem(
  id: string,
  fields: Partial<
    Pick<
      Item,
      | "title"
      | "description"
      | "assignee_notes"
      | "project_id"
      | "position"
      | "scheduled_date"
      | "due_date"
      | "recurrence_rule"
      | "recurrence_strategy"
      | "assignee_id"
      | "cognitive_load_score"
    >
  >,
): Promise<Item> {
  const response = await fetch(buildUrl(`/api/items/${id}`), {
    method: "PATCH",
    headers: getAuthHeaders(),
    body: JSON.stringify(fields),
  });

  const payload = await handleResponse<Item | { data?: Item }>(response);
  return extractData(payload);
}

/**
 * Delete an item
 */
export async function deleteItem(id: string): Promise<{ ok: true }> {
  const response = await fetch(buildUrl(`/api/items/${id}`), {
    method: "DELETE",
    headers: getAuthHeaders(),
  });

  await handleResponse<void>(response);
  return { ok: true };
}

// ─────────────────────────────────────────────────────────────────────────────
// Cognitive Phase
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch the authenticated user's current cognitive phase analysis.
 */
export async function fetchCognitivePhase(): Promise<CognitivePhaseResponse> {
  const response = await fetch(buildUrl("/api/user/cognitive-phase"), {
    method: "GET",
    headers: getAuthHeaders(),
    cache: "no-store",
  });

  const payload = await handleResponse<
    CognitivePhaseResponse | { data?: CognitivePhaseResponse }
  >(response);
  return extractData(payload);
}

// ─────────────────────────────────────────────────────────────────────────────
// Projects
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch all projects for the authenticated user
 */
export async function fetchProjects(): Promise<Project[]> {
  const response = await fetch(buildUrl("/api/projects"), {
    method: "GET",
    headers: getAuthHeaders(),
    cache: "no-store",
  });

  const payload = await handleResponse<Project[] | { data?: Project[] }>(
    response,
  );
  const projects = extractData(payload);
  return Array.isArray(projects) ? projects : [];
}

/**
 * Create a new project
 */
export async function createProject(payload: {
  name: string;
  color?: string | null;
}): Promise<Project> {
  const response = await fetch(buildUrl("/api/projects"), {
    method: "POST",
    headers: getAuthHeaders(),
    body: JSON.stringify({
      name: payload.name,
      color: payload.color || null,
    }),
  });

  const data = await handleResponse<Project | { data?: Project }>(response);
  return extractData(data);
}

// ─────────────────────────────────────────────────────────────────────────────
// User Lookup
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Search for users by email or phone number suffix (last 9 digits).
 * Excludes the current authenticated user from results.
 */
export async function lookupUsers(query: string): Promise<UserLookup[]> {
  if (!query || query.length < 3) {
    return [];
  }

  const response = await fetch(buildUrl("/api/users/lookup", { q: query }), {
    method: "GET",
    headers: getAuthHeaders(),
    cache: "no-store",
  });

  const payload = await handleResponse<UserLookup[] | { data?: UserLookup[] }>(
    response,
  );
  const users = extractData(payload);
  return Array.isArray(users) ? users : [];
}

/**
 * Discover a user by exact email or phone number.
 * Searches all users (not just connected ones).
 * Used to find users before connecting with them.
 */
export async function discoverUser(
  query: string,
): Promise<{ found: boolean; user?: UserLookup }> {
  // Determine if query looks like an email or phone
  const isEmail = query.includes("@");
  const body: Record<string, string> = isEmail
    ? { email: query }
    : { phone: query };

  const response = await fetch(buildUrl("/api/users/discover"), {
    method: "POST",
    headers: getAuthHeaders(),
    body: JSON.stringify(body),
  });

  return handleResponse<{ found: boolean; user?: UserLookup }>(response);
}

/**
 * Assign an item to a user
 */
export async function assignItem(
  itemId: string,
  userId: string | null,
): Promise<Item> {
  return updateItem(itemId, { assignee_id: userId });
}

/**
 * Toggle a user as a favourite contact.
 * Returns the updated favourite status and full favourites list.
 */
export async function toggleFavourite(
  userId: string,
): Promise<{ is_favourite: boolean; favourites: { data: UserLookup[] } }> {
  const response = await fetch(buildUrl(`/api/favourites/${userId}`), {
    method: "POST",
    headers: getAuthHeaders(),
  });

  return handleResponse<{
    is_favourite: boolean;
    favourites: { data: UserLookup[] };
  }>(response);
}

// ─────────────────────────────────────────────────────────────────────────────
// Notifications (poll-based)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetch notifications for the authenticated user.
 * Use unreadOnly=true to get only unread notifications.
 */
export async function fetchNotifications(
  unreadOnly?: boolean,
): Promise<NotificationsResponse> {
  const response = await fetch(
    buildUrl("/api/notifications", {
      unread_only: unreadOnly ? "true" : undefined,
    }),
    {
      method: "GET",
      headers: getAuthHeaders(),
      cache: "no-store",
    },
  );

  return handleResponse<NotificationsResponse>(response);
}

/**
 * Mark a specific notification as read.
 */
export async function markNotificationAsRead(
  notificationId: string,
): Promise<{ message: string; unread_count: number }> {
  const response = await fetch(
    buildUrl(`/api/notifications/${notificationId}/read`),
    {
      method: "POST",
      headers: getAuthHeaders(),
    },
  );

  return handleResponse<{ message: string; unread_count: number }>(response);
}

/**
 * Mark all notifications as read for the authenticated user.
 */
export async function markAllNotificationsAsRead(): Promise<{
  message: string;
  marked_count: number;
}> {
  const response = await fetch(buildUrl("/api/notifications/read-all"), {
    method: "POST",
    headers: getAuthHeaders(),
  });

  return handleResponse<{ message: string; marked_count: number }>(response);
}

// ─────────────────────────────────────────────────────────────────────────────
// Push Notifications (Web Push)
// ─────────────────────────────────────────────────────────────────────────────

export interface PushSubscriptionInfo {
  id: string;
  endpoint_domain: string;
  user_agent: string | null;
  last_used_at: string | null;
  created_at: string;
}

export interface PushSubscriptionsResponse {
  subscriptions: PushSubscriptionInfo[];
  count: number;
}

/**
 * Get the VAPID public key for subscribing to push notifications.
 * Returns null if push notifications are not configured on the server.
 */
export async function getVapidPublicKey(): Promise<string | null> {
  try {
    const response = await fetch(buildUrl("/api/push/vapid-key"), {
      method: "GET",
      headers: getAuthHeaders(),
    });

    if (response.status === 503) {
      // Push notifications not configured
      return null;
    }

    const data = await handleResponse<{ public_key: string }>(response);
    return data.public_key;
  } catch {
    return null;
  }
}

/**
 * Subscribe to push notifications.
 * Sends the browser's push subscription to the server.
 */
export async function subscribeToPush(subscription: PushSubscription): Promise<{
  message: string;
  subscription_id: string;
}> {
  const response = await fetch(buildUrl("/api/push/subscribe"), {
    method: "POST",
    headers: getAuthHeaders(),
    body: JSON.stringify({
      endpoint: subscription.endpoint,
      keys: {
        p256dh: arrayBufferToBase64(subscription.getKey("p256dh")),
        auth: arrayBufferToBase64(subscription.getKey("auth")),
      },
    }),
  });

  return handleResponse<{ message: string; subscription_id: string }>(response);
}

/**
 * Unsubscribe from push notifications by endpoint.
 */
export async function unsubscribeFromPush(
  endpoint: string,
): Promise<{ message: string }> {
  const response = await fetch(buildUrl("/api/push/unsubscribe"), {
    method: "POST",
    headers: getAuthHeaders(),
    body: JSON.stringify({ endpoint }),
  });

  return handleResponse<{ message: string }>(response);
}

/**
 * List all push subscriptions for the current user.
 */
export async function listPushSubscriptions(): Promise<PushSubscriptionsResponse> {
  const response = await fetch(buildUrl("/api/push/subscriptions"), {
    method: "GET",
    headers: getAuthHeaders(),
  });

  return handleResponse<PushSubscriptionsResponse>(response);
}

/**
 * Delete a specific push subscription by ID.
 */
export async function deletePushSubscription(
  subscriptionId: string,
): Promise<{ message: string }> {
  const response = await fetch(
    buildUrl(`/api/push/subscriptions/${subscriptionId}`),
    {
      method: "DELETE",
      headers: getAuthHeaders(),
    },
  );

  return handleResponse<{ message: string }>(response);
}

/**
 * Convert ArrayBuffer to base64 string for push subscription keys.
 */
function arrayBufferToBase64(buffer: ArrayBuffer | null): string {
  if (!buffer) return "";
  const bytes = new Uint8Array(buffer);
  let binary = "";
  for (let i = 0; i < bytes.byteLength; i++) {
    binary += String.fromCharCode(bytes[i]);
  }
  return btoa(binary)
    .replace(/\+/g, "-")
    .replace(/\//g, "_")
    .replace(/=+$/, "");
}
