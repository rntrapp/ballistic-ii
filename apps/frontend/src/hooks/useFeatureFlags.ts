"use client";

import { useCallback, useContext, useMemo } from "react";
import { AuthContext } from "@/contexts/AuthContext";

interface FeatureFlags {
  dates: boolean;
  delegation: boolean;
  ai_assistant: boolean;
}

const DEFAULTS: FeatureFlags = {
  dates: false,
  delegation: false,
  ai_assistant: false,
};

const AVAILABLE_DEFAULTS: FeatureFlags = {
  dates: true,
  delegation: true,
  ai_assistant: true,
};

export function useFeatureFlags() {
  const auth = useContext(AuthContext);
  const user = auth?.user ?? null;
  const updateUser = useMemo(
    () => auth?.updateUser ?? (async () => {}),
    [auth?.updateUser],
  );

  const userFlags = useMemo(() => {
    if (!user?.feature_flags) return DEFAULTS;

    return {
      dates: user.feature_flags.dates ?? false,
      delegation: user.feature_flags.delegation ?? false,
      ai_assistant: user.feature_flags.ai_assistant ?? false,
    };
  }, [user?.feature_flags]);

  const available = useMemo(() => {
    if (!user?.available_feature_flags) return AVAILABLE_DEFAULTS;

    return {
      dates: user.available_feature_flags.dates ?? true,
      delegation: user.available_feature_flags.delegation ?? true,
      ai_assistant: user.available_feature_flags.ai_assistant ?? true,
    };
  }, [user?.available_feature_flags]);

  const setFlag = useCallback(
    async (flag: keyof FeatureFlags, value: boolean) => {
      try {
        await updateUser({ feature_flags: { [flag]: value } });
      } catch (error) {
        console.error("Failed to save feature flags:", error);
        throw error;
      }
    },
    [updateUser],
  );

  return {
    dates: userFlags.dates && available.dates,
    delegation: userFlags.delegation && available.delegation,
    aiAssistant: userFlags.ai_assistant && available.ai_assistant,
    userFlags,
    available,
    setFlag,
    loaded: user !== null,
  };
}
