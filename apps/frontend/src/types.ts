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
    cognitive_phase: boolean;
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
  cognitive_load_score: number | null;
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

export interface CognitiveEvent {
  recorded_at: string;
  cognitive_load_score: number;
  event_type: string;
}

export interface CognitivePhaseResponse {
  dominant_cycle_minutes: number;
  current_phase: "Peak" | "Trough" | "Recovery";
  phase_progress: number;
  next_peak_at: string;
  confidence_score: number;
  today_events: CognitiveEvent[];
}
