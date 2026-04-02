"use client";

import { useEffect, useRef, useState } from "react";
import Image from "next/image";
import { FocusTrap } from "focus-trap-react";
import { useAuth } from "@/contexts/AuthContext";
import { useModal } from "@/hooks/useModal";
import { updateUser } from "@/lib/api";

interface ProfileModalProps {
  isOpen: boolean;
  onClose: () => void;
}

export function ProfileModal({ isOpen, onClose }: ProfileModalProps) {
  const modalRef = useRef<HTMLDivElement>(null);
  const { user, logout, refreshUser } = useAuth();
  useModal(isOpen);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [phone, setPhone] = useState("");
  const [bio, setBio] = useState("");
  const [avatarUrl, setAvatarUrl] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  // Sync form fields when the modal opens
  useEffect(() => {
    if (isOpen && user) {
      setName(user.name);
      setEmail(user.email);
      setPhone(user.phone ?? "");
      setBio(user.bio ?? "");
      setAvatarUrl(user.avatar_url ?? "");
      setError(null);
      setSuccess(false);
    }
  }, [isOpen, user]);

  // Close on escape key — blocked while saving
  useEffect(() => {
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape" && !saving) onClose();
    }

    if (isOpen) {
      document.addEventListener("keydown", handleKeyDown);
      return () => document.removeEventListener("keydown", handleKeyDown);
    }
  }, [isOpen, onClose, saving]);

  function handleBackdropClick(e: React.MouseEvent) {
    if (saving) return;
    if (modalRef.current && !modalRef.current.contains(e.target as Node)) {
      onClose();
    }
  }

  function handleCloseClick() {
    if (saving) return;
    onClose();
  }

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (saving) return;

    setSaving(true);
    setError(null);
    setSuccess(false);

    try {
      await updateUser({
        name: name.trim(),
        email: email.trim(),
        phone: phone.trim() || null,
        bio: bio.trim() || null,
        avatar_url: avatarUrl.trim() || null,
      });
      await refreshUser();
      setSuccess(true);
      setTimeout(() => setSuccess(false), 2000);
    } catch (err) {
      console.error("Failed to update profile:", err);
      const message =
        err instanceof Error && err.message
          ? err.message
          : "Failed to save profile. Please try again.";
      setError(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleLogout() {
    await logout();
  }

  if (!isOpen || !user) return null;

  const initials = user.name
    .split(" ")
    .map((w) => w[0])
    .join("")
    .toUpperCase()
    .slice(0, 2);

  return (
    <div
      role="dialog"
      aria-modal="true"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm animate-fade-in p-4"
      onClick={handleBackdropClick}
    >
      <FocusTrap focusTrapOptions={{ allowOutsideClick: true }}>
        <div
          ref={modalRef}
          className="w-full max-w-md max-h-[90vh] overflow-y-auto rounded-2xl bg-white p-6 shadow-xl animate-slide-in-up"
        >
          {/* Header */}
          <div className="flex items-center justify-between mb-6">
            <h2 className="text-lg font-semibold text-gray-900">Profile</h2>
            <button
              type="button"
              onClick={handleCloseClick}
              disabled={saving}
              className="rounded-full p-2 hover:bg-gray-100 transition-colors disabled:opacity-50"
              aria-label="Close"
            >
              <svg
                viewBox="0 0 24 24"
                width="20"
                height="20"
                fill="none"
                stroke="currentColor"
                className="text-gray-500"
              >
                <path
                  d="M18 6 6 18M6 6l12 12"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                />
              </svg>
            </button>
          </div>

          {/* Avatar */}
          <div className="flex justify-center mb-6">
            {user.avatar_url ? (
              <Image
                src={user.avatar_url}
                alt={user.name}
                width={64}
                height={64}
                unoptimized
                className="h-16 w-16 rounded-full object-cover"
              />
            ) : (
              <div className="h-16 w-16 rounded-full bg-[var(--blue)] flex items-center justify-center text-white text-xl font-semibold">
                {initials}
              </div>
            )}
          </div>

          {/* Feedback */}
          {error && (
            <div className="mb-4 p-3 bg-red-50 border border-red-200 rounded-md text-sm text-red-700">
              {error}
            </div>
          )}
          {success && (
            <div className="mb-4 p-3 bg-green-50 border border-green-200 rounded-md text-sm text-green-700">
              Profile updated.
            </div>
          )}

          {/* Form */}
          <form onSubmit={handleSave} className="space-y-4">
            <div>
              <label
                htmlFor="profile-name"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                Name
              </label>
              <input
                id="profile-name"
                type="text"
                value={name}
                onChange={(e) => setName(e.target.value)}
                required
                disabled={saving}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:opacity-50"
              />
            </div>

            <div>
              <label
                htmlFor="profile-email"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                Email
              </label>
              <input
                id="profile-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                required
                disabled={saving}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:opacity-50"
              />
            </div>

            <div>
              <label
                htmlFor="profile-phone"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                Phone
              </label>
              <input
                id="profile-phone"
                type="tel"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="Optional"
                disabled={saving}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:opacity-50"
              />
            </div>

            <div>
              <label
                htmlFor="profile-bio"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                Bio
              </label>
              <textarea
                id="profile-bio"
                value={bio}
                onChange={(e) => setBio(e.target.value)}
                placeholder="Tell us about yourself"
                maxLength={500}
                rows={3}
                disabled={saving}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:opacity-50 resize-none"
              />
              <p className="text-xs text-gray-400 mt-1 text-right">
                {bio.length}/500
              </p>
            </div>

            <div>
              <label
                htmlFor="profile-avatar"
                className="block text-sm font-medium text-gray-700 mb-1"
              >
                Avatar URL
              </label>
              <input
                id="profile-avatar"
                type="url"
                value={avatarUrl}
                onChange={(e) => setAvatarUrl(e.target.value)}
                placeholder="https://example.com/avatar.jpg"
                disabled={saving}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent disabled:opacity-50"
              />
            </div>

            <button
              type="submit"
              disabled={saving || !name.trim() || !email.trim()}
              className="w-full rounded-md bg-[var(--blue)] px-4 py-2.5 text-sm font-medium text-white hover:bg-[var(--blue-600)] disabled:opacity-50 transition-colors"
            >
              {saving ? "Saving..." : "Save changes"}
            </button>
          </form>

          {/* Logout */}
          <div className="mt-6 pt-4 border-t border-gray-100">
            <button
              type="button"
              onClick={() => void handleLogout()}
              className="w-full rounded-md border border-red-200 px-4 py-2.5 text-sm font-medium text-red-700 hover:bg-red-50 transition-colors"
            >
              Log out
            </button>
          </div>
        </div>
      </FocusTrap>
    </div>
  );
}
