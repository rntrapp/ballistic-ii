export type Status = "todo" | "doing" | "done" | "wontdo";

/**
 * Fibonacci-scale effort scoring.
 * Mirrors App\Enums\EffortScore on the backend — keep the two in lockstep.
 */
export const EFFORT_SCORES = [1, 2, 3, 5, 8] as const;
export type EffortScore = (typeof EFFORT_SCORES)[number];
export const DEFAULT_EFFORT_SCORE: EffortScore = 1;

export const EFFORT_SCORE_LABELS: Record<EffortScore, string> = {
  1: "Trivial",
  2: "Minor",
  3: "Moderate",
  5: "Major",
  8: "Epic",
};

export interface User {
  id: string;
  name: string;
  email: string;
  phone: string | null;
  notes: string | null;
  feature_flags?: {
    dates: boolean;
    delegation: boolean;
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
  effort_score: EffortScore;
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

/**
 * Payload shape returned by GET /api/velocity.
 * Numeric fields that the backend computes with BCMath arrive as fixed-
 * precision strings (4 dp) to avoid IEEE-754 drift crossing the wire.
 */
export interface VelocityForecast {
  weekly_velocity_ema: string;
  upcoming_effort: number;
  success_probability: string;
  burnout_risk: boolean;
  alpha: string;
  history_weeks: number;
  weekly_history: { week_start: string; effort: number }[];
}

export interface AuthResponse {
  message: string;
  user: User;
  token: string;
}

export interface ValidationError {
  message: string;
  errors: Record<string, string[]>;
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
