import { useTheme } from "@/contexts/ThemeContext";
import { Toaster as Sonner, type ToasterProps } from "sonner";

const Toaster = ({ ...props }: ToasterProps) => {
  const { theme = "system" } = useTheme();

  return (
    <Sonner
      theme={theme as ToasterProps["theme"]}
      className="toaster group"
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          // Toasts sit ABOVE every modal layer — the page editor portals
          // with a z-30 blurred backdrop and a toast must never blur
          // behind it (owner report 2026-07-19, gap e533bc5 F2).
          zIndex: 9999,
        } as React.CSSProperties
      }
      {...props}
    />
  );
};

export { Toaster };
