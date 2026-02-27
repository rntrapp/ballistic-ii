"use client";

import type { CognitivePhaseResponse } from "@/types";

interface PhaseIndicatorProps {
  phase: CognitivePhaseResponse;
}

const BADGE_STYLES: Record<string, string> = {
  Peak: "bg-green-100 text-green-800 border-green-200",
  Trough: "bg-amber-100 text-amber-800 border-amber-200",
  Recovery: "bg-blue-100 text-blue-800 border-blue-200",
};

function formatCountdown(nextPeakAt: string): string {
  const diff = new Date(nextPeakAt).getTime() - Date.now();
  if (diff <= 0) return "now";
  const mins = Math.round(diff / 60000);
  if (mins < 60) return `${mins}m`;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

export function PhaseIndicator({ phase }: PhaseIndicatorProps) {
  const styles = BADGE_STYLES[phase.current_phase] ?? BADGE_STYLES.Recovery;

  return (
    <div
      className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium ${styles}`}
    >
      <span>{phase.current_phase}</span>
      <span className="opacity-60">&middot;</span>
      <span className="opacity-80">
        Peak in {formatCountdown(phase.next_peak_at)}
      </span>
    </div>
  );
}
