"use client";

/**
 * Lightweight PWA splash screen with animated rocket emoji.
 * Uses pure CSS animations for performance.
 */
export function SplashScreen() {
  return (
    <div className="fixed inset-0 z-50 flex flex-col items-center justify-center bg-gradient-to-b from-sky-500 to-indigo-600">
      {/* Animated rocket */}
      <div className="relative">
        <span
          className="text-7xl animate-splash-rocket select-none"
          role="img"
          aria-label="Rocket launching"
        >
          🚀
        </span>
        {/* Trail particles */}
        <div className="absolute -bottom-2 left-1/2 -translate-x-1/2 flex gap-1">
          <span className="w-2 h-2 bg-orange-300 rounded-full animate-splash-particle-1 opacity-80" />
          <span className="w-1.5 h-1.5 bg-yellow-300 rounded-full animate-splash-particle-2 opacity-70" />
          <span className="w-2 h-2 bg-orange-400 rounded-full animate-splash-particle-3 opacity-80" />
        </div>
      </div>

      {/* App name */}
      <h1 className="mt-8 text-3xl font-bold text-white tracking-wide animate-splash-fade-in-up">
        Ballistic
      </h1>
      <p className="mt-2 text-white/70 text-sm animate-splash-fade-in-up splash-delay-200">
        The Simplest Bullet Journal
      </p>

      {/* Loading dots */}
      <div className="mt-8 flex gap-1.5">
        <span className="w-2 h-2 bg-white/60 rounded-full animate-splash-bounce-dot" />
        <span className="w-2 h-2 bg-white/60 rounded-full animate-splash-bounce-dot splash-delay-100" />
        <span className="w-2 h-2 bg-white/60 rounded-full animate-splash-bounce-dot splash-delay-200" />
      </div>
    </div>
  );
}
