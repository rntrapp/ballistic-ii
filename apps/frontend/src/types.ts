export type Status = "todo" | "doing" | "done" | "wontdo";

export interface User {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  notes: string | null;
  feature_flags?: {
    dates: boolean;
    delegation: boolean;
    chronobiology: boolean;
  } | null;
  email_verified_at: string | null;
  created_at: string;
  updated_at: string;
  favourites?: UserLookup[];
}

export interface UserLookup {
  id: string;
  name: string;
  email_masked: string;
}

export interface Project {
  id: string;
  user_id: string;
  name: string;
  color: string | null;
  archived_at: string | null;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

export interface Tag {
  id: string;
  user_id: string;
  name: string;
  color: string | null;
  created_at: string;
  updated_at: string;
}

export interface Item {
  id: string;
  user_id: string;
  assignee_id: string | null;
  project_id: string | null;
  title: string;
  description: string | null;
  assignee_notes: string | null;
  status: Status;
  position: number;
  cognitive_load: number | null;
  scheduled_date: string | null;
  due_date: string | null;
  completed_at: string | null;
  recurrence_rule: string | null;
  recurrence_parent_id: string | null;
  recurrence_strategy: "expires" | "carry_over" | null;
  is_recurring_template: boolean;
  is_recurring_instance: boolean;
  is_assigned: boolean;
  is_delegated: boolean;
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
  project?: Project | null;
  tags?: Tag[];
  assignee?: UserLookup | null;
  owner?: UserLookup | null;
}

export interface Notification {
  id: string;
  user_id: string;
  type: string;
  title: string;
  message: string;
  data: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface NotificationsResponse {
  data: Notification[];
  unread_count: number;
}

export type ItemScope = "active" | "planned" | "all";

export interface AuthResponse {
  message: string;
  user: User;
  token: string;
}

export interface ValidationError {
  message: string;
  errors: Record<string, string[]>;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cognitive Phase Tracking (chronobiology feature)
// ─────────────────────────────────────────────────────────────────────────────

export type CognitivePhase = "peak" | "trough" | "recovery";

/**
 * Snapshot of the user's current cognitive phase, projected from their
 * cached ultradian-rhythm profile. When has_profile=false the remaining
 * fields are absent and message explains why.
 */
export interface CognitivePhaseSnapshot {
  has_profile: boolean;
  phase?: CognitivePhase;
  dominant_cycle_minutes?: number;
  next_peak_at?: string; // ISO-8601
  confidence?: number; // 0..1
  amplitude_fraction?: number; // -1..1, where on the wave we sit now
  sample_count?: number;
  message?: string; // present when has_profile=false
}

/**
 * A single completed-task point for plotting on the wave.
 */
export interface CognitiveEventPoint {
  occurred_at: string; // ISO-8601 with microseconds
  cognitive_load_score: number; // 1..10
  item_id: string | null;
}

export type RecurrencePreset =
  | "none"
  | "daily"
  | "weekdays"
  | "weekly"
  | "monthly";

export const RECURRENCE_PRESET_RULES: Record<RecurrencePreset, string | null> =
  {
    none: null,
    daily: "FREQ=DAILY",
    weekdays: "FREQ=WEEKLY;BYDAY=MO,TU,WE,TH,FR",
    weekly: "FREQ=WEEKLY",
    monthly: "FREQ=MONTHLY",
  };
