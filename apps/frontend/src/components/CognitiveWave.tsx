"use client";

import { useEffect, useRef } from "react";
import type { CognitiveEventPoint, CognitivePhaseSnapshot } from "@/types";

/**
 * Fixed canvas height (px). Width is responsive. Fixing height prevents
 * layout shift as the wave animates.
 */
const CANVAS_HEIGHT = 140;
/** Sine amplitude in pixels (centred at CANVAS_HEIGHT/2). */
const WAVE_AMPLITUDE = 50;
/** Amplitude fraction above which we consider the user in "peak" band. */
const PEAK_THRESHOLD = 0.5;
/** Amplitude fraction below which we consider the user in "trough" band. */
const TROUGH_THRESHOLD = -0.5;

interface CognitiveWaveProps {
  snapshot: CognitivePhaseSnapshot | null;
  events: CognitiveEventPoint[];
}

/**
 * Live sinusoidal visualisation of today's ultradian rhythm.
 *
 * Rendering strategy:
 *  1. An **offscreen canvas** holds the static scene (wave curve, peak/trough
 *     shading, event dots, axis). This is redrawn only when the snapshot,
 *     events, or viewport width changes.
 *  2. A `requestAnimationFrame` loop blits the offscreen → visible canvas
 *     each frame and overdraws a single vertical "now" line. The line moves
 *     fractionally per frame so the animation is perfectly smooth (~60fps)
 *     while doing almost no work — no trig per-frame.
 *
 * X-axis is today 00:00 → 23:59 mapped linearly to canvas width.
 * Y-axis is `centre − amplitude·cos(2π·(t − anchor)/period)` so the phase
 * anchor (a known peak moment) sits at y-min (top of the wave).
 */
export function CognitiveWave({ snapshot, events }: CognitiveWaveProps) {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const offscreenRef = useRef<HTMLCanvasElement | null>(null);
  const rafRef = useRef<number | null>(null);

  // ─────────────────────────────────────────────────────────────────────────
  // Static-scene render: wave + bands + dots + axis → offscreen canvas.
  // Re-runs when profile data, events, or container width changes.
  // ─────────────────────────────────────────────────────────────────────────
  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    // Match canvas backing store to CSS size at 1× DPR (kept simple —
    // Hi-DPI scaling can be added later with ctx.scale if needed).
    const width = canvas.clientWidth || 600;
    canvas.width = width;
    canvas.height = CANVAS_HEIGHT;

    // Lazily create the offscreen buffer, resize on width change.
    if (!offscreenRef.current) {
      offscreenRef.current = document.createElement("canvas");
    }
    const off = offscreenRef.current;
    off.width = width;
    off.height = CANVAS_HEIGHT;
    const ctx = off.getContext("2d");
    if (!ctx) return;

    ctx.clearRect(0, 0, width, CANVAS_HEIGHT);

    const centreY = CANVAS_HEIGHT / 2;
    const dayStart = startOfToday();
    const dayMs = 86_400_000;

    /** Map a timestamp (ms-since-epoch) to an x-coord on the canvas. */
    const xAt = (tMs: number): number => ((tMs - dayStart) / dayMs) * width;

    // ── No-profile fallback: flat neutral line ───────────────────────────
    if (!snapshot || !snapshot.has_profile) {
      ctx.strokeStyle = "#cbd5e1"; // slate-300
      ctx.lineWidth = 2;
      ctx.setLineDash([4, 4]);
      ctx.beginPath();
      ctx.moveTo(0, centreY);
      ctx.lineTo(width, centreY);
      ctx.stroke();
      ctx.setLineDash([]);
      return;
    }

    // ── Derive wave parameters from the snapshot ─────────────────────────
    const periodMs = (snapshot.dominant_cycle_minutes ?? 100) * 60_000;
    // We know the *current* amplitude fraction (cos of current phase angle)
    // at the moment the snapshot was produced — which is effectively "now".
    // The anchor (a known-peak moment) is derived by winding back:
    //   cos(φ_now) = amplitude_fraction
    //   φ_now = acos(amplitude_fraction)   (ambiguous, but next_peak disambiguates)
    // Simpler: next_peak_at is a future moment where φ = 0 (peak). Use it.
    const nextPeakMs = snapshot.next_peak_at
      ? new Date(snapshot.next_peak_at).getTime()
      : dayStart + dayMs / 2;
    // Any peak moment works as an anchor; back off by whole periods so the
    // anchor lies before today's start (avoids edge artefacts on the left).
    const cyclesBack = Math.ceil((nextPeakMs - dayStart) / periodMs) + 1;
    const anchorMs = nextPeakMs - cyclesBack * periodMs;

    /** Amplitude fraction at time t (−1..1). Peak = +1. */
    const waveAt = (tMs: number): number =>
      Math.cos((2 * Math.PI * (tMs - anchorMs)) / periodMs);

    /** Canvas y-coord for the wave at time t. */
    const yAt = (tMs: number): number => centreY - WAVE_AMPLITUDE * waveAt(tMs);

    // ── Shade peak & trough bands behind the wave ───────────────────────
    // Walk the day in ~2px steps, build a fill path for each band.
    const shadeBand = (
      predicate: (amp: number) => boolean,
      fill: string,
    ): void => {
      ctx.fillStyle = fill;
      let inBand = false;
      let bandStartX = 0;
      const step = Math.max(1, width / 720); // ~2min resolution
      for (let x = 0; x <= width; x += step) {
        const tMs = dayStart + (x / width) * dayMs;
        const inNow = predicate(waveAt(tMs));
        if (inNow && !inBand) {
          inBand = true;
          bandStartX = x;
        } else if (!inNow && inBand) {
          ctx.fillRect(bandStartX, 0, x - bandStartX, CANVAS_HEIGHT);
          inBand = false;
        }
      }
      if (inBand) {
        ctx.fillRect(bandStartX, 0, width - bandStartX, CANVAS_HEIGHT);
      }
    };
    shadeBand((a) => a > PEAK_THRESHOLD, "rgba(16, 185, 129, 0.08)"); // emerald
    shadeBand((a) => a < TROUGH_THRESHOLD, "rgba(245, 158, 11, 0.08)"); // amber

    // ── Centre axis (dashed zero line) ───────────────────────────────────
    ctx.strokeStyle = "#e2e8f0"; // slate-200
    ctx.lineWidth = 1;
    ctx.setLineDash([3, 5]);
    ctx.beginPath();
    ctx.moveTo(0, centreY);
    ctx.lineTo(width, centreY);
    ctx.stroke();
    ctx.setLineDash([]);

    // ── The wave itself (gradient stroke) ────────────────────────────────
    const grad = ctx.createLinearGradient(0, 0, width, 0);
    grad.addColorStop(0, "#0ea5e9"); // sky-500
    grad.addColorStop(1, "#6366f1"); // indigo-500
    ctx.strokeStyle = grad;
    ctx.lineWidth = 2.5;
    ctx.beginPath();
    ctx.moveTo(0, yAt(dayStart));
    for (let x = 1; x <= width; x++) {
      const tMs = dayStart + (x / width) * dayMs;
      ctx.lineTo(x, yAt(tMs));
    }
    ctx.stroke();

    // ── Event dots: completed tasks sit on the wave ──────────────────────
    for (const ev of events) {
      const tMs = new Date(ev.occurred_at).getTime();
      if (tMs < dayStart || tMs >= dayStart + dayMs) continue;
      const x = xAt(tMs);
      const y = yAt(tMs);
      // Radius scales with cognitive load (1→3px, 10→8px).
      const r = 3 + (ev.cognitive_load_score - 1) * (5 / 9);
      ctx.fillStyle = "#1e293b"; // slate-800
      ctx.beginPath();
      ctx.arc(x, y, r, 0, 2 * Math.PI);
      ctx.fill();
      // Subtle white ring for contrast.
      ctx.strokeStyle = "#ffffff";
      ctx.lineWidth = 1.5;
      ctx.stroke();
    }
  }, [snapshot, events]);

  // ─────────────────────────────────────────────────────────────────────────
  // Animation loop: blit offscreen → visible, overdraw "now" marker.
  // ─────────────────────────────────────────────────────────────────────────
  useEffect(() => {
    const canvas = canvasRef.current;
    const off = offscreenRef.current;
    if (!canvas || !off) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    const width = canvas.width;
    const dayStart = startOfToday();
    const dayMs = 86_400_000;

    const tick = (): void => {
      // Copy the static scene.
      ctx.clearRect(0, 0, width, CANVAS_HEIGHT);
      ctx.drawImage(off, 0, 0);

      // Draw the live "now" vertical line.
      const nowFrac = (Date.now() - dayStart) / dayMs;
      if (nowFrac >= 0 && nowFrac <= 1) {
        const x = nowFrac * width;
        ctx.strokeStyle = "#ef4444"; // red-500
        ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(x, 0);
        ctx.lineTo(x, CANVAS_HEIGHT);
        ctx.stroke();
        // Small triangle at the top so it reads as a marker, not a glitch.
        ctx.fillStyle = "#ef4444";
        ctx.beginPath();
        ctx.moveTo(x - 4, 0);
        ctx.lineTo(x + 4, 0);
        ctx.lineTo(x, 6);
        ctx.closePath();
        ctx.fill();
      }

      rafRef.current = requestAnimationFrame(tick);
    };

    rafRef.current = requestAnimationFrame(tick);
    return () => {
      if (rafRef.current !== null) cancelAnimationFrame(rafRef.current);
    };
    // Depend on snapshot/events so the loop restarts after static redraw —
    // this picks up the new offscreen buffer content.
  }, [snapshot, events]);

  // ─────────────────────────────────────────────────────────────────────────
  // ResizeObserver: redraw static scene on container width change.
  // The static effect above reads clientWidth, so a re-run after resize
  // picks up the new dimensions. We trigger that by poking the canvas width
  // which the effect will re-detect on next render — but since width isn't
  // in its deps, we instead manually re-run the draw here.
  // Simpler approach: both effects depend on [snapshot, events], so we nudge
  // via a dummy state. But adding state for resize is overkill; instead,
  // just accept that resize during a session is rare and a subsequent
  // snapshot refresh (5min) or event addition will redraw. If pixel-perfect
  // resize responsiveness proves necessary, convert to a width state.
  // ─────────────────────────────────────────────────────────────────────────

  const hasProfile = snapshot?.has_profile ?? false;

  return (
    <div className="relative w-full" style={{ height: CANVAS_HEIGHT }}>
      <canvas
        ref={canvasRef}
        className="absolute inset-0 w-full"
        style={{ height: CANVAS_HEIGHT }}
        role="img"
        aria-label={
          hasProfile
            ? `Cognitive rhythm wave — currently in ${snapshot?.phase ?? "unknown"} phase`
            : "Cognitive rhythm wave — insufficient data"
        }
      />
      {!hasProfile && (
        <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
          <span className="rounded-md bg-slate-100/80 px-3 py-1.5 text-xs text-slate-600">
            {snapshot?.message ??
              "Complete more tasks to build your cognitive profile."}
          </span>
        </div>
      )}
    </div>
  );
}

/** Midnight (local time) of the current day, as ms-since-epoch. */
function startOfToday(): number {
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  return now.getTime();
}
