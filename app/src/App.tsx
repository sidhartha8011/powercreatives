/**
 * CREATIVE MACHINE - Main App Component
 * Micro Frontend Architecture with Shell + Modules
 */

import { Toaster } from "@/components/ui/sonner";
import { TooltipProvider } from "@/components/ui/tooltip";
import ErrorBoundary from "./components/ErrorBoundary";
import { ThemeProvider } from "./contexts/ThemeContext";
import { AppProvider } from "./contexts/AppContext";
import { Shell } from "./components/layout/Shell";
import { ClientReviewPage } from "./modules/Approvals/components/ClientReviewPage";

function App() {
  return (
    <ErrorBoundary>
      <ThemeProvider defaultTheme="light">
        <AppProvider>
          <TooltipProvider>
            <Toaster position="bottom-right" />
            {(() => {
              const urlParams = new URLSearchParams(window.location.search);
              const token = urlParams.get('pcm_public_token');
              if (token) {
                return <ClientReviewPage token={token} />;
              }
              return <Shell />;
            })()}
          </TooltipProvider>
        </AppProvider>
      </ThemeProvider>
    </ErrorBoundary>
  );
}

export default App;
