import { useCallback } from 'react';
import { Share2, Download } from 'lucide-react';
import { toast } from 'sonner';

interface ClientBreadcrumbsProps {
  brandName?: string;
  campaignName?: string;
  shareUrl?: string;
  onDownloadAll?: () => void;
}

export function ClientBreadcrumbs({
  brandName = 'Client Board',
  campaignName = 'Creative Review',
  shareUrl,
  onDownloadAll
}: ClientBreadcrumbsProps) {

  const handleShare = useCallback(() => {
    const url = shareUrl || window.location.href;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url)
        .then(() => toast.success('Sharing link copied to clipboard!'))
        .catch(() => {
          // Fallback
          const input = document.createElement('input');
          input.value = url;
          document.body.appendChild(input);
          input.select();
          document.execCommand('copy');
          document.body.removeChild(input);
          toast.success('Sharing link copied!');
        });
    } else {
      toast.error('Unable to copy automatically. Please copy the URL from your address bar.');
    }
  }, [shareUrl]);

  const handleDownload = useCallback(() => {
    if (onDownloadAll) {
      onDownloadAll();
    } else {
      toast.success('Preparing assets. All approved creative items package is downloading...');
    }
  }, [onDownloadAll]);

  return (
    <header className="w-full h-12 flex items-center px-4 md:px-6 bg-white/70 backdrop-blur border-b border-border/50 sticky top-0 z-30 transition-all duration-200">
      {/* Notion-style mark */}
      <span 
        className="w-5.5 h-5.5 rounded-md bg-gradient-to-br from-orange-400 to-purple-500 flex items-center justify-center text-[10px] font-bold text-white shadow-sm mr-2.5 shrink-0"
        aria-hidden="true"
      >
        N
      </span>

      {/* Breadcrumb path */}
      <div className="flex items-center gap-1 text-[11px] md:text-[13px] font-medium text-muted-foreground truncate max-w-[65%]">
        <span className="hover:bg-muted/55 hover:text-foreground px-1.5 py-0.5 rounded cursor-pointer transition-colors">
          Northwind Studio
        </span>
        <span className="text-muted-foreground/50">/</span>
        <span className="hover:bg-muted/55 hover:text-foreground px-1.5 py-0.5 rounded cursor-pointer transition-colors">
          Clients
        </span>
        <span className="text-muted-foreground/50">/</span>
        <span className="hover:bg-muted/55 hover:text-foreground px-1.5 py-0.5 rounded cursor-pointer transition-colors max-w-[120px] truncate">
          {brandName}
        </span>
        <span className="text-muted-foreground/50">/</span>
        <span className="text-foreground font-semibold px-1.5 py-0.5 rounded truncate">
          {campaignName}
        </span>
      </div>

      <div className="flex-1" />

      {/* Actions */}
      <div className="flex items-center gap-1.5 shrink-0">
        <button
          type="button"
          onClick={handleShare}
          className="w-8 h-8 rounded-lg border border-border bg-white hover:bg-muted hover:text-foreground text-muted-foreground transition-all flex items-center justify-center cursor-pointer"
          title="Share Board"
          aria-label="Share Board"
        >
          <Share2 className="w-3.5 h-3.5" />
        </button>
        <button
          type="button"
          onClick={handleDownload}
          className="w-8 h-8 rounded-lg border border-border bg-white hover:bg-muted hover:text-foreground text-muted-foreground transition-all flex items-center justify-center cursor-pointer"
          title="Download All Approved Assets"
          aria-label="Download All Approved Assets"
        >
          <Download className="w-3.5 h-3.5" />
        </button>
      </div>
    </header>
  );
}
