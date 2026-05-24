import { useCallback } from 'react';
import { Share2, Download } from 'lucide-react';
import { toast } from 'sonner';

interface ClientBreadcrumbsProps {
  brandName?: string;
  campaignName?: string;
  studioName?: string;
  shareUrl?: string;
  onDownloadAll?: () => void;
}

export function ClientBreadcrumbs({
  brandName = 'Client Board',
  campaignName = 'Creative Review',
  studioName = 'Studio',
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

  const handleDownload = onDownloadAll ?? null;

  return (
    <header className="pcm-crumb sticky top-0 z-30 transition-all duration-200">
      {/* Notion-style mark — shows first letter of studio name */}
      <span className="pcm-crumb-mark" aria-hidden="true">
        {studioName.charAt(0).toUpperCase()}
      </span>

      {/* Breadcrumb path */}
      <div className="flex items-center gap-0.5 max-w-[65%] truncate">
        <span className="pcm-crumb-piece cursor-pointer">
          {studioName}
        </span>
        <span className="pcm-crumb-sep">/</span>
        <span className="pcm-crumb-piece cursor-pointer">
          Clients
        </span>
        <span className="pcm-crumb-sep">/</span>
        <span className="pcm-crumb-piece cursor-pointer max-w-[120px] truncate">
          {brandName}
        </span>
        <span className="pcm-crumb-sep">/</span>
        <span className="pcm-crumb-piece current truncate">
          {campaignName}
        </span>
      </div>

      <div className="flex-1" />

      {/* Actions */}
      <div className="flex items-center gap-1.5 shrink-0 select-none">
        <button
          type="button"
          onClick={handleShare}
          className="pcm-crumb-icon"
          title="Share Board"
          aria-label="Share Board"
        >
          <Share2 className="w-[15px] h-[15px]" />
        </button>
        {handleDownload && (
          <button
            type="button"
            onClick={handleDownload}
            className="pcm-crumb-icon"
            title="Download All Approved Assets"
            aria-label="Download All Approved Assets"
          >
            <Download className="w-[15px] h-[15px]" />
          </button>
        )}
      </div>
    </header>
  );
}
