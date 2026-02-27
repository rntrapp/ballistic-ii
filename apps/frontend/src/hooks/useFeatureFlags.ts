"use client";

import { useMemo, useCallback, useContext } from "react";
import { AuthContext } from "@/contexts/AuthContext";

interface FeatureFlags {
  dates: boolean;
  delegation: boolean;
  cognitivePhase: boolean;
}

const DEFAULTS: FeatureFlags = {
  dates: false,
  delegation: false,
  cognitivePhase: false,
};

export function useFeatureFlags() {
  // Use useContext directly instead of useAuth to avoid throwing in tests
  const auth = useContext(AuthContext);

  // In test environment or when AuthProvider is not available, use defaults
  const user = auth?.user ?? null;
  const updateUser = useMemo(
    () => auth?.updateUser ?? (async () => {}),
    [auth?.updateUser],
  );

  // Get flags from user object (no separate fetch needed)
  const flags = useMemo(() => {
    if (!user?.feature_flags) return DEFAULTS;
    return {
      dates: user.feature_flags.dates ?? false,
      delegation: user.feature_flags.delegation ?? false,
      cognitivePhase: user.feature_flags.cognitive_phase ?? false,
    };
  }, [user?.feature_flags]);

  const setFlag = useCallback(
    async (
      flag: "dates" | "delegation" | "cognitive_phase",
      value: boolean,
    ) => {
      const next = {
        dates: flags.dates,
        delegation: flags.delegation,
        cognitive_phase: flags.cognitivePhase,
        [flag]: value,
      };

      try {
        await updateUser({ feature_flags: next });
      } catch (error) {
        console.error("Failed to save feature flags:", error);
        throw error;
      }
    },
    [flags, updateUser],
  );

  return {
    dates: flags.dates,
    delegation: flags.delegation,
    cognitivePhase: flags.cognitivePhase,
    setFlag,
    loaded: user !== null,
  };
}
