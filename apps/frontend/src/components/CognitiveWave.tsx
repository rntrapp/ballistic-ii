"use client";

import type { CognitivePhaseResponse } from "@/types";

interface CognitiveWaveProps {
  phase: CognitivePhaseResponse;
}

const PHASE_CONFIG: Record<
  string,
  { label: string; hint: string; colour: string; bgClass: string }
> = {
  Peak: {
    label: "Peak",
    hint: "Deep Work Window",
    colour: "#22c55e",
    bgClass: "bg-green-50 border-green-200 text-green-800",
  },
  Trough: {
    label: "Trough",
    hint: "Light Tasks",
    colour: "#f59e0b",
    bgClass: "bg-amber-50 border-amber-200 text-amber-800",
  },
  Recovery: {
    label: "Recovery",
    hint: "Creative Tasks",
    colour: "#3b82f6",
    bgClass: "bg-blue-50 border-blue-200 text-blue-800",
  },
};

function dotColour(score: number): string {
  if (score <= 3) return "#22c55e";
  if (score <= 6) return "#f59e0b";
  return "#ef4444";
}

function formatCountdown(nextPeakAt: string): string {
  const diff = new Date(nextPeakAt).getTime() - Date.now();
  if (diff <= 0) return "now";
  const mins = Math.round(diff / 60000);
  if (mins < 60) return `${mins}m`;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

export function CognitiveWave({ phase }: CognitiveWaveProps) {
  const cfg = PHASE_CONFIG[phase.current_phase] ?? PHASE_CONFIG.Recovery;
  const SVG_W = 800;
  const SVG_H = 112;
  const PAD_Y = 20;
  const AMP = (SVG_H - PAD_Y * 2) / 2;
  const MID_Y = SVG_H / 2;

  // Build sine curve path across 24 hours
  const cycleMinutes = phase.dominant_cycle_minutes || 90;
  const freq = (2 * Math.PI) / cycleMinutes;
  const offset = phase.phase_progress * 2 * Math.PI;

  const points: string[] = [];
  for (let x = 0; x <= SVG_W; x += 2) {
    const minuteOfDay = (x / SVG_W) * 1440;
    const y = MID_Y - AMP * Math.sin(freq * minuteOfDay - offset);
    points.push(`${x},${y.toFixed(1)}`);
  }
  const curvePath = `M${points.join(" L")}`;

  // Fill regions — peak (top) and trough (bottom)
  const peakFill = `${curvePath} L${SVG_W},${PAD_Y} L0,${PAD_Y} Z`;
  const troughFill = `${curvePath} L${SVG_W},${SVG_H - PAD_Y} L0,${SVG_H - PAD_Y} Z`;

  // "Now" indicator line
  const now = new Date();
  const nowMinutes = now.getHours() * 60 + now.getMinutes();
  const nowX = (nowMinutes / 1440) * SVG_W;

  // Plot event dots
  const eventDots = phase.today_events.map((evt) => {
    const d = new Date(evt.recorded_at);
    const evtMinutes = d.getHours() * 60 + d.getMinutes();
    const ex = (evtMinutes / 1440) * SVG_W;
    const ey = MID_Y - AMP * Math.sin(freq * evtMinutes - offset);
    return { x: ex, y: ey, score: evt.cognitive_load_score };
  });

  return (
    <div className="rounded-xl border border-slate-200/60 bg-white p-3 shadow-sm">
      {/* Phase badge */}
      <div className="flex items-center justify-between mb-2">
        <span
          className={`inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium ${cfg.bgClass}`}
        >
          <span
            className="inline-block h-2 w-2 rounded-full"
            style={{ backgroundColor: cfg.colour }}
          />
          {cfg.label} &mdash; {cfg.hint}
        </span>
        <span className="text-xs text-slate-500">
          Next peak in {formatCountdown(phase.next_peak_at)}
        </span>
      </div>

      {/* SVG Waveform */}
      <svg
        viewBox={`0 0 ${SVG_W} ${SVG_H}`}
        className="h-28 w-full"
        preserveAspectRatio="none"
        aria-label="Cognitive rhythm waveform"
      >
        {/* Peak fill (green tint) */}
        <path d={peakFill} fill="#22c55e" opacity="0.07" />
        {/* Trough fill (amber tint) */}
        <path d={troughFill} fill="#f59e0b" opacity="0.07" />

        {/* Sine curve */}
        <path
          d={curvePath}
          fill="none"
          stroke={cfg.colour}
          strokeWidth="2"
          opacity="0.6"
        />

        {/* "Now" indicator line with pulse */}
        <line
          x1={nowX}
          y1={PAD_Y}
          x2={nowX}
          y2={SVG_H - PAD_Y}
          stroke={cfg.colour}
          strokeWidth="2"
          strokeDasharray="4 3"
          className="animate-pulse"
        />
        <circle
          cx={nowX}
          cy={MID_Y - AMP * Math.sin(freq * nowMinutes - offset)}
          r="5"
          fill={cfg.colour}
          className="animate-pulse"
        />

        {/* Event dots */}
        {eventDots.map((dot, i) => (
          <circle
            key={i}
            cx={dot.x}
            cy={dot.y}
            r="4"
            fill={dotColour(dot.score)}
            stroke="white"
            strokeWidth="1.5"
          />
        ))}
      </svg>
    </div>
  );
}
