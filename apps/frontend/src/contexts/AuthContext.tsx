"use client";

import {
  createContext,
  useContext,
  useEffect,
  useState,
  useCallback,
  type ReactNode,
} from "react";
import type { User } from "@/types";
import {
  getToken,
  setStoredUser,
  login as authLogin,
  register as authRegister,
  logout as authLogout,
  AuthError,
} from "@/lib/auth";
import {
  fetchUser,
  updateUser as apiUpdateUser,
  type UserUpdatePayload,
} from "@/lib/api";

interface AuthContextType {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string,
  ) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
  updateUser: (data: UserUpdatePayload) => Promise<void>;
}

export const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Fetch fresh user data from backend on mount
  useEffect(() => {
    const token = getToken();
    if (token) {
      fetchUser()
        .then((fetchedUser) => {
          setUser(fetchedUser);
          setStoredUser(fetchedUser);
        })
        .catch((error) => {
          console.error("Failed to fetch user on mount:", error);
        })
        .finally(() => {
          setIsLoading(false);
        });
    } else {
      setIsLoading(false);
    }
  }, []);

  const login = useCallback(async (email: string, password: string) => {
    const response = await authLogin(email, password);
    setUser(response.user);
    setStoredUser(response.user);
  }, []);

  const register = useCallback(
    async (
      name: string,
      email: string,
      password: string,
      passwordConfirmation: string,
    ) => {
      const response = await authRegister(
        name,
        email,
        password,
        passwordConfirmation,
      );
      setUser(response.user);
      setStoredUser(response.user);
    },
    [],
  );

  const logout = useCallback(async () => {
    await authLogout();
    setUser(null);
  }, []);

  const refreshUser = useCallback(async () => {
    const fetchedUser = await fetchUser();
    setUser(fetchedUser);
    setStoredUser(fetchedUser);
  }, []);

  const updateUser = useCallback(async (data: UserUpdatePayload) => {
    const updatedUser = await apiUpdateUser(data);
    setUser(updatedUser);
    setStoredUser(updatedUser);
  }, []);

  const value: AuthContextType = {
    user,
    isAuthenticated: user !== null,
    isLoading,
    login,
    register,
    logout,
    refreshUser,
    updateUser,
  };

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextType {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error("useAuth must be used within an AuthProvider");
  }
  return context;
}

export { AuthError };
