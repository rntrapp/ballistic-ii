"use client";

import { useMemo, useCallback, useContext } from "react";
import { AuthContext } from "@/contexts/AuthContext";

interface FeatureFlags {
  dates: boolean;
  delegation: boolean;
}

const DEFAULTS: FeatureFlags = {
  dates: false,
  delegation: false,
};

const AVAILABLE_DEFAULTS: FeatureFlags = {
  dates: true,
  delegation: true,
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

  // Get user-level flags (raw preference stored on the user)
  const userFlags = useMemo(() => {
    if (!user?.feature_flags) return DEFAULTS;
    return {
      dates: user.feature_flags.dates ?? false,
      delegation: user.feature_flags.delegation ?? false,
    };
  }, [user?.feature_flags]);

  // Get globally available flags from admin settings (defaults all true when missing)
  const available = useMemo(() => {
    if (!user?.available_feature_flags) return AVAILABLE_DEFAULTS;
    return {
      dates: user.available_feature_flags.dates ?? true,
      delegation: user.available_feature_flags.delegation ?? true,
    };
  }, [user?.available_feature_flags]);

  const setFlag = useCallback(
    async (flag: "dates" | "delegation", value: boolean) => {
      // Send only the changed key — the server merges it with the stored flags,
      // which avoids a multi-tab race condition where two concurrent updates
      // would overwrite each other's changes.
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
    // Effective state: only true when BOTH user preference AND global flag are true
    dates: userFlags.dates && available.dates,
    delegation: userFlags.delegation && available.delegation,
    // Raw user preferences (for toggle position)
    userFlags,
    // Global availability (for disabling toggles)
    available,
    setFlag,
    loaded: user !== null,
  };
}
