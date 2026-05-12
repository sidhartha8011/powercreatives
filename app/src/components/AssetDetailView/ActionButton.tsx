/**
 * ACTION BUTTON
 * 
 * Reusable button component for asset actions.
 * Consistent styling across all action panels.
 * PowerKeys-consistent compact design.
 */

import { Button } from "@/components/ui/button";
import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";

interface ActionButtonProps {
  onClick: () => void;
  disabled?: boolean;
  loading?: boolean;
  variant?: "default" | "secondary" | "outline" | "ghost";
  className?: string;
  children: React.ReactNode;
  icon?: React.ReactNode;
}

export function ActionButton({
  onClick,
  disabled = false,
  loading = false,
  variant = "default",
  className,
  children,
  icon,
}: ActionButtonProps) {
  return (
    <Button
      onClick={onClick}
      disabled={disabled || loading}
      variant={variant}
      size="sm"
      className={cn("w-full justify-center gap-1.5 text-sm", className)}
    >
      {loading ? (
        <Loader2 className="h-3.5 w-3.5 animate-spin" />
      ) : icon ? (
        icon
      ) : null}
      {children}
    </Button>
  );
}
