import type { CognitivePhase, CognitivePhaseSnapshot, Item } from "@/types";

/** Fires the banner when the next peak is within this many minutes. */
export const DEEP_WORK_LEAD_TIME_MIN = 15;

/**
 * Compute whole minutes until the next cognitive peak, or null when the
 * Deep Work banner should stay hidden. Returns a number (0–15) when the
 * next peak is within {@link DEEP_WORK_LEAD_TIME_MIN} minutes of `now`.
 *
 * No phase-classification gate: `next_peak_at` already handles the
 * "at apex" case (when you're sitting exactly on a peak, the next one is
 * a full cycle away and this returns null). Spec scenario: login at
 * 2:00 PM, peak at 2:15 PM → must return 15.
 */
export function minutesUntilDeepWork(
  snapshot: CognitivePhaseSnapshot | null,
  now: Date = new Date(),
): number | null {
  if (!snapshot?.has_profile || !snapshot.next_peak_at) return null;
  const deltaMs = new Date(snapshot.next_peak_at).getTime() - now.getTime();
  if (deltaMs <= 0) return null;
  const mins = Math.round(deltaMs / 60_000);
  return mins <= DEEP_WORK_LEAD_TIME_MIN ? mins : null;
}

/**
 * Re-order items by cognitive load to match the user's current ultradian
 * phase. During a **peak** heavy tasks (high cognitive_load) float to the
 * top; during a **trough** light tasks float up; during **recovery** or
 * when phase is unknown the list is returned unchanged.
 *
 * Items lacking a cognitive_load score are treated as neutral (5) so they
 * neither sink nor rise disproportionately. The sort is stable: within the
 * same load tier, original ordering (e.g. urgency) is preserved — this is
 * essential so the composed urgency→phase pipeline still surfaces overdue
 * items within each cognitive-load band.
 */
export function sortByCognitivePhase(
  items: Item[],
  phase: CognitivePhase | undefined,
): Item[] {
  if (!phase || phase === "recovery") return items;

  // peak  → high-load first (descending) → direction = -1
  // trough → low-load first  (ascending)  → direction = +1
  const direction = phase === "peak" ? -1 : 1;

  // Tag with original index so we can keep the sort stable.
  return items
    .map((item, idx) => ({ item, idx }))
    .sort((a, b) => {
      const la = a.item.cognitive_load ?? 5;
      const lb = b.item.cognitive_load ?? 5;
      if (la !== lb) return (la - lb) * direction;
      return a.idx - b.idx; // stable: preserve prior (urgency) ordering
    })
    .map(({ item }) => item);
}
