import { useEffect } from "react";

/**
 * Locks body scroll while a modal is open, restoring the previous value on cleanup.
 * Apply this in every modal component to prevent background scrolling.
 */
export function useModal(isOpen: boolean): void {
  useEffect(() => {
    if (!isOpen) return;

    const previous = document.body.style.overflow;
    document.body.style.overflow = "hidden";

    return () => {
      document.body.style.overflow = previous;
    };
  }, [isOpen]);
}
