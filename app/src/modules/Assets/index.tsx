/**
 * CREATIVE MACHINE - Assets Module
 * Smart Asset Scraper: Paste a URL, scrape images, AI tags them,
 * then smartly selects the right ones for ad generation.
 */

import { useState, useEffect, useCallback } from 'react';
import { trpc } from '@/lib/trpc';
import { toast } from 'sonner';
import {
  Globe, Search, Loader2, FolderOpen, Trash2, Eye, Sparkles,
  ImageIcon, X, Check, Ban, RefreshCw, ChevronRight, Wand2,
  ExternalLink, Clock, AlertCircle, Tag,
} from 'lucide-react';

// ============================================
// Types
// ============================================

type CollectionStatus = 'scraping' | 'analyzing' | 'ready' | 'error';

interface CollectionWithImages {
  id: number;
  sourceUrl: string;
  title: string | null;
  status: CollectionStatus;
  imageCount: number;
  errorMessage: string | null;
  createdAt: Date;
  images: ImageItem[];
}

interface ImageItem {
  id: number;
  originalUrl: string;
  thumbnailUrl: string | null;
  aiDescription: string | null;
  aiCategory: string | null;
  aiTags: string[] | null;
  aiScore: number | null;
  isUsable: boolean;
  isExcluded: boolean;
}

/** Safely extract hostname from a URL string. */
function getDomain(url: string): string {
  try {
    return new URL(url).hostname;
  } catch {
    return url;
  }
}

// ============================================
// Sub-Components
// ============================================

function StatusBadge({ status }: { status: CollectionStatus }) {
  const config = {
    scraping: { label: 'Scraping', color: '#3b82f6', bg: '#eff6ff', icon: Loader2 },
    analyzing: { label: 'Analyzing', color: '#f59e0b', bg: '#fffbeb', icon: Eye },
    ready: { label: 'Ready', color: '#22c55e', bg: '#f0fdf4', icon: Check },
    error: { label: 'Error', color: '#ef4444', bg: '#fef2f2', icon: AlertCircle },
  }[status];

  const Icon = config.icon;

  return (
    <span
      className="inline-flex items-center gap-1"
      style={{
        padding: '2px 8px',
        borderRadius: '999px',
        background: config.bg,
        color: config.color,
        fontSize: '0.65rem',
        fontWeight: 600,
      }}
    >
      <Icon className={`w-3 h-3 ${status === 'scraping' ? 'animate-spin' : ''}`} />
      {config.label}
    </span>
  );
}

function CategoryBadge({ category }: { category: string }) {
  const colorMap: Record<string, { color: string; bg: string }> = {
    headshot: { color: '#7c3aed', bg: '#f5f3ff' },
    team: { color: '#2563eb', bg: '#eff6ff' },
    product: { color: '#059669', bg: '#ecfdf5' },
    interior: { color: '#d97706', bg: '#fffbeb' },
    exterior: { color: '#0891b2', bg: '#ecfeff' },
    food: { color: '#dc2626', bg: '#fef2f2' },
    event: { color: '#c026d3', bg: '#fdf4ff' },
    lifestyle: { color: '#ea580c', bg: '#fff7ed' },
    graphic: { color: '#4f46e5', bg: '#eef2ff' },
    nature: { color: '#16a34a', bg: '#f0fdf4' },
    abstract: { color: '#6366f1', bg: '#eef2ff' },
    other: { color: '#6b7280', bg: '#f9fafb' },
  };

  const style = colorMap[category] || colorMap.other;

  return (
    <span
      style={{
        padding: '1px 6px',
        borderRadius: '4px',
        background: style.bg,
        color: style.color,
        fontSize: '0.6rem',
        fontWeight: 600,
        textTransform: 'capitalize',
      }}
    >
      {category}
    </span>
  );
}

function ImageCard({
  image,
  onToggleExclude,
  isSelected,
}: {
  image: ImageItem;
  onToggleExclude: (id: number, excluded: boolean) => void;
  isSelected?: boolean;
}) {
  const tags = Array.isArray(image.aiTags) ? image.aiTags : [];
  const imageUrl = image.thumbnailUrl || image.originalUrl;

  return (
    <div
      className="group relative"
      style={{
        borderRadius: '10px',
        overflow: 'hidden',
        border: isSelected
          ? '2px solid #3b82f6'
          : image.isExcluded
            ? '2px solid #ef4444'
            : '1px solid #e5e7eb',
        aspectRatio: '1',
        opacity: image.isExcluded ? 0.4 : 1,
        transition: 'all 0.2s',
      }}
    >
      <img
        src={imageUrl}
        alt={image.description || 'Scraped image'}
        className="w-full h-full object-cover"
        loading="lazy"
      />

      {/* Selected overlay */}
      {isSelected && (
        <div
          className="absolute top-2 left-2"
          style={{
            width: '22px',
            height: '22px',
            borderRadius: '50%',
            background: '#3b82f6',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Check className="w-3 h-3 text-white" />
        </div>
      )}

      {/* Excluded badge */}
      {image.isExcluded && (
        <div
          className="absolute top-2 left-2"
          style={{
            width: '22px',
            height: '22px',
            borderRadius: '50%',
            background: '#ef4444',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
          }}
        >
          <Ban className="w-3 h-3 text-white" />
        </div>
      )}

      {/* Hover overlay */}
      <div
        className="absolute inset-0 opacity-0 group-hover:opacity-100 transition-opacity flex flex-col justify-between"
        style={{
          background: 'linear-gradient(rgba(0,0,0,0.1) 0%, transparent 30%, transparent 50%, rgba(0,0,0,0.75) 100%)',
        }}
      >
        {/* Top actions */}
        <div className="flex justify-end gap-1 p-2">
          <button
            onClick={(e) => {
              e.stopPropagation();
              onToggleExclude(image.id, !image.isExcluded);
            }}
            title={image.isExcluded ? 'Include image' : 'Exclude image'}
            style={{
              width: '26px',
              height: '26px',
              borderRadius: '6px',
              background: 'rgba(255,255,255,0.9)',
              border: 'none',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            {image.isExcluded ? (
              <Check className="w-3.5 h-3.5" style={{ color: '#22c55e' }} />
            ) : (
              <Ban className="w-3.5 h-3.5" style={{ color: '#ef4444' }} />
            )}
          </button>
          <a
            href={imageUrl}
            target="_blank"
            rel="noopener noreferrer"
            onClick={(e) => e.stopPropagation()}
            title="Open in new tab"
            style={{
              width: '26px',
              height: '26px',
              borderRadius: '6px',
              background: 'rgba(255,255,255,0.9)',
              border: 'none',
              cursor: 'pointer',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
            }}
          >
            <ExternalLink className="w-3.5 h-3.5" style={{ color: '#374151' }} />
          </a>
        </div>

        {/* Bottom info */}
        <div className="p-2">
            {image.aiDescription && (
              <p style={{ fontSize: '0.65rem', color: '#fff', fontWeight: 500, lineHeight: 1.3, marginBottom: '4px' }}>
                {image.aiDescription}
              </p>
            )}
            <div className="flex flex-wrap gap-1">
              {image.aiCategory && <CategoryBadge category={image.aiCategory} />}
            {tags.slice(0, 2).map((tag: string) => (
              <span
                key={tag}
                style={{
                  padding: '1px 5px',
                  borderRadius: '4px',
                  background: 'rgba(255,255,255,0.2)',
                  color: '#fff',
                  fontSize: '0.55rem',
                  fontWeight: 500,
                }}
              >
                {tag}
              </span>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

function SmartSelectPanel({
  collectionId,
  onClose,
}: {
  collectionId: number;
  onClose: () => void;
}) {
  const [prompt, setPrompt] = useState('');
  const [maxImages, setMaxImages] = useState(5);

  const smartSelectMutation = trpc.assetScraper.smartSelect.useMutation({
    onSuccess: (data) => {
      toast.success(`AI selected ${data.selectedImages.length} images`);
    },
    onError: (err) => {
      toast.error(err.message);
    },
  });

  const handleSelect = () => {
    if (!prompt.trim()) return;
    smartSelectMutation.mutate({ collectionId, prompt, maxImages });
  };

  return (
    <div
      style={{
        background: '#fff',
        borderRadius: '12px',
        border: '1px solid #e5e7eb',
        padding: '16px',
        marginBottom: '16px',
      }}
    >
      <div className="flex items-center justify-between mb-3">
        <div className="flex items-center gap-2">
          <Wand2 className="w-4 h-4" style={{ color: '#7c3aed' }} />
          <h4 style={{ fontSize: '0.85rem', fontWeight: 600, color: '#1a1a1a' }}>
            Smart Select
          </h4>
        </div>
        <button onClick={onClose} style={{ border: 'none', background: 'none', cursor: 'pointer' }}>
          <X className="w-4 h-4" style={{ color: '#999' }} />
        </button>
      </div>

      <p style={{ fontSize: '0.75rem', color: '#666', marginBottom: '10px', lineHeight: 1.4 }}>
        Describe your ad and AI will pick the best images from this collection.
      </p>

      <textarea
        value={prompt}
        onChange={(e) => setPrompt(e.target.value)}
        placeholder="e.g., Valentine's Day special — 20% off teeth whitening for couples"
        rows={3}
        style={{
          width: '100%',
          padding: '10px 12px',
          borderRadius: '8px',
          border: '1px solid #e5e7eb',
          fontSize: '0.8rem',
          resize: 'none',
          outline: 'none',
          fontFamily: 'inherit',
        }}
      />

      <div className="flex items-center justify-between mt-3">
        <div className="flex items-center gap-2">
          <label style={{ fontSize: '0.7rem', color: '#666' }}>Max images:</label>
          <select
            value={maxImages}
            onChange={(e) => setMaxImages(Number(e.target.value))}
            style={{
              padding: '3px 8px',
              borderRadius: '6px',
              border: '1px solid #e5e7eb',
              fontSize: '0.75rem',
              outline: 'none',
            }}
          >
            {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((n) => (
              <option key={n} value={n}>{n}</option>
            ))}
          </select>
        </div>

        <button
          onClick={handleSelect}
          disabled={!prompt.trim() || smartSelectMutation.isPending}
          className="flex items-center gap-1.5"
          style={{
            padding: '7px 16px',
            borderRadius: '8px',
            background: !prompt.trim() || smartSelectMutation.isPending ? '#e5e7eb' : '#7c3aed',
            color: !prompt.trim() || smartSelectMutation.isPending ? '#999' : '#fff',
            fontSize: '0.8rem',
            fontWeight: 600,
            border: 'none',
            cursor: !prompt.trim() || smartSelectMutation.isPending ? 'not-allowed' : 'pointer',
          }}
        >
          {smartSelectMutation.isPending ? (
            <>
              <Loader2 className="w-3.5 h-3.5 animate-spin" />
              Selecting...
            </>
          ) : (
            <>
              <Sparkles className="w-3.5 h-3.5" />
              Select Images
            </>
          )}
        </button>
      </div>

      {/* Results */}
      {smartSelectMutation.data && (
        <div
          className="mt-4"
          style={{
            padding: '12px',
            borderRadius: '8px',
            background: '#f5f3ff',
            border: '1px solid #e9d5ff',
          }}
        >
          <p style={{ fontSize: '0.75rem', fontWeight: 600, color: '#7c3aed', marginBottom: '6px' }}>
            AI Selection ({smartSelectMutation.data.selectedImages.length} images)
          </p>
          <p style={{ fontSize: '0.7rem', color: '#6b21a8', lineHeight: 1.4, marginBottom: '8px' }}>
            {smartSelectMutation.data.reasoning}
          </p>
          <p style={{ fontSize: '0.65rem', color: '#9333ea', fontStyle: 'italic' }}>
            Layout suggestion: {smartSelectMutation.data.suggestedLayout}
          </p>

          {/* Selected image thumbnails */}
          <div className="flex gap-2 mt-3 overflow-x-auto pb-1">
            {smartSelectMutation.data.selectedImages.map((img: any) => (
              <img
                key={img.id}
                src={img.storedUrl || img.originalUrl}
                alt={img.description || ''}
                style={{
                  width: '64px',
                  height: '64px',
                  borderRadius: '6px',
                  objectFit: 'cover',
                  border: '2px solid #7c3aed',
                  flexShrink: 0,
                }}
              />
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

// ============================================
// Main Component
// ============================================

export function AssetsModule() {
  const [url, setUrl] = useState('');
  const [selectedCollectionId, setSelectedCollectionId] = useState<number | null>(null);
  const [showSmartSelect, setShowSmartSelect] = useState(false);
  const [filterCategory, setFilterCategory] = useState<string>('all');
  const [selectedImageIds, setSelectedImageIds] = useState<Set<number>>(new Set());

  // tRPC queries
  const collectionsQuery = trpc.assetScraper.listCollections.useQuery();
  const collectionDetailQuery = trpc.assetScraper.getCollection.useQuery(
    { collectionId: selectedCollectionId! },
    { enabled: !!selectedCollectionId },
  );

  // tRPC mutations
  const scrapeMutation = trpc.assetScraper.scrapeUrl.useMutation({
    onSuccess: (data) => {
      toast.success(`Scraped ${data.imageCount} images from ${data.domain || getDomain(data.sourceUrl)}`);
      setSelectedCollectionId(data.collectionId);
      collectionsQuery.refetch();
      // Auto-trigger analysis
      if (data.status === 'analyzing') {
        analyzeMutation.mutate({ collectionId: data.collectionId });
      }
    },
    onError: (err) => {
      toast.error(err.message);
    },
  });

  const analyzeMutation = trpc.assetScraper.analyzeCollection.useMutation({
    onSuccess: (data) => {
      toast.success(`Analyzed ${data.analyzed} images`);
      collectionDetailQuery.refetch();
      collectionsQuery.refetch();
    },
    onError: (err) => {
      toast.error(`Analysis failed: ${err.message}`);
    },
  });

  const deleteMutation = trpc.assetScraper.deleteCollection.useMutation({
    onSuccess: () => {
      toast.success('Collection deleted');
      setSelectedCollectionId(null);
      collectionsQuery.refetch();
    },
    onError: (err) => {
      toast.error(err.message);
    },
  });

  const toggleExcludeMutation = trpc.assetScraper.toggleImageExclusion.useMutation({
    onSuccess: () => {
      collectionDetailQuery.refetch();
    },
  });

  const handleScrape = () => {
    if (!url.trim()) return;
    scrapeMutation.mutate({ url: url.trim() });
    setUrl('');
  };

  const handleDelete = (collectionId: number) => {
    if (confirm('Delete this collection and all its images?')) {
      deleteMutation.mutate({ collectionId });
    }
  };

  const handleToggleExclude = (imageId: number, excluded: boolean) => {
    toggleExcludeMutation.mutate({ imageId, excluded });
  };

  const collections = collectionsQuery.data || [];
  const activeCollection = collectionDetailQuery.data as CollectionWithImages | undefined;

  // Filter images
  const filteredImages = activeCollection?.images.filter((img) => {
    if (filterCategory === 'all') return true;
    if (filterCategory === 'excluded') return img.isExcluded;
    return img.aiCategory === filterCategory && !img.isExcluded;
  }) || [];

  // Get unique categories from images
  const categories = activeCollection?.images
    ? Array.from(new Set(activeCollection.images.filter(i => i.aiCategory && !i.isExcluded).map(i => i.aiCategory!)))
    : [];

  const isProcessing = scrapeMutation.isPending || analyzeMutation.isPending;

  return (
    <div className="h-full flex flex-col" style={{ maxWidth: '100%' }}>
      {/* Header */}
      <div className="flex items-center justify-between mb-5">
        <div>
          <h1 style={{ fontSize: '1.4rem', fontWeight: 700, color: '#1a1a1a' }}>
            Assets
          </h1>
          <p style={{ fontSize: '0.8rem', color: '#666', marginTop: '2px' }}>
            Scrape images from any website — AI tags and selects the best ones for your ads
          </p>
        </div>
      </div>

      {/* URL Input Bar */}
      <div
        className="flex items-center gap-2 mb-5"
        style={{
          padding: '8px 12px',
          background: '#fff',
          borderRadius: '12px',
          border: '1px solid #e0e0e0',
          boxShadow: '0 1px 3px rgba(0,0,0,0.04)',
        }}
      >
        <Globe className="w-4 h-4 shrink-0" style={{ color: '#999' }} />
        <input
          type="text"
          value={url}
          onChange={(e) => setUrl(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && handleScrape()}
          placeholder="Paste a website URL, Instagram, Facebook page..."
          className="flex-1 outline-none bg-transparent"
          style={{ fontSize: '0.85rem', color: '#1a1a1a' }}
          disabled={isProcessing}
        />
        <button
          onClick={handleScrape}
          disabled={isProcessing || !url.trim()}
          className="flex items-center gap-1.5 shrink-0"
          style={{
            padding: '6px 16px',
            borderRadius: '999px',
            background: isProcessing || !url.trim() ? '#e9ecef' : '#007bff',
            color: isProcessing || !url.trim() ? '#999' : '#fff',
            fontSize: '0.8rem',
            fontWeight: 600,
            border: 'none',
            cursor: isProcessing || !url.trim() ? 'not-allowed' : 'pointer',
            transition: 'all 0.2s',
          }}
        >
          {scrapeMutation.isPending ? (
            <>
              <Loader2 className="w-3.5 h-3.5 animate-spin" />
              Scraping...
            </>
          ) : (
            <>
              <Search className="w-3.5 h-3.5" />
              Scrape
            </>
          )}
        </button>
      </div>

      {/* Main Content */}
      {collections.length === 0 && !isProcessing ? (
        /* Empty State */
        <div
          className="flex-1 flex flex-col items-center justify-center"
          style={{
            background: '#fff',
            borderRadius: '12px',
            border: '1px solid #e0e0e0',
          }}
        >
          <div
            className="flex items-center justify-center"
            style={{
              width: '64px',
              height: '64px',
              background: '#f0f7ff',
              borderRadius: '16px',
              marginBottom: '16px',
            }}
          >
            <ImageIcon className="w-7 h-7" style={{ color: '#007bff' }} />
          </div>
          <h3 style={{ fontSize: '1rem', fontWeight: 600, color: '#1a1a1a', marginBottom: '6px' }}>
            No asset collections yet
          </h3>
          <p style={{ fontSize: '0.8rem', color: '#888', maxWidth: '360px', textAlign: 'center', lineHeight: 1.5 }}>
            Paste a website URL above to scrape all images. AI will analyze and tag each image so you can use them in your ad creatives.
          </p>
        </div>
      ) : (
        /* Collections + Gallery */
        <div className="flex-1 flex gap-4 overflow-hidden">
          {/* Left: Collections List */}
          <div
            className="flex flex-col shrink-0 overflow-y-auto"
            style={{
              width: '240px',
              background: '#fff',
              borderRadius: '12px',
              border: '1px solid #e0e0e0',
              padding: '8px',
            }}
          >
            <div
              className="flex items-center justify-between"
              style={{ padding: '6px 8px', marginBottom: '4px' }}
            >
              <span style={{ fontSize: '0.7rem', fontWeight: 600, color: '#999', letterSpacing: '0.05em' }}>
                COLLECTIONS ({collections.length})
              </span>
            </div>

            {collections.map((collection: any) => (
              <button
                key={collection.id}
                onClick={() => setSelectedCollectionId(collection.id)}
                className="flex items-start gap-2 w-full text-left"
                style={{
                  padding: '8px 10px',
                  borderRadius: '8px',
                  background: selectedCollectionId === collection.id ? '#e7f5ff' : 'transparent',
                  border: 'none',
                  cursor: 'pointer',
                  marginBottom: '2px',
                  transition: 'background 0.15s',
                }}
              >
                <FolderOpen
                  className="w-4 h-4 shrink-0 mt-0.5"
                  style={{ color: selectedCollectionId === collection.id ? '#007bff' : '#999' }}
                />
                <div className="flex-1 min-w-0">
                  <p
                    className="truncate"
                    style={{
                      fontSize: '0.8rem',
                      fontWeight: selectedCollectionId === collection.id ? 600 : 500,
                      color: selectedCollectionId === collection.id ? '#007bff' : '#555',
                    }}
                  >
                    {collection.title || getDomain(collection.sourceUrl)}
                  </p>
                  <div className="flex items-center gap-2 mt-0.5">
                    <span style={{ fontSize: '0.65rem', color: '#999' }}>
                      {collection.imageCount} images
                    </span>
                    <StatusBadge status={collection.status} />
                  </div>
                </div>
              </button>
            ))}
          </div>

          {/* Right: Gallery */}
          <div
            className="flex-1 flex flex-col overflow-hidden"
            style={{
              background: '#fff',
              borderRadius: '12px',
              border: '1px solid #e0e0e0',
            }}
          >
            {activeCollection ? (
              <>
                {/* Gallery Header */}
                <div
                  className="flex items-center justify-between shrink-0"
                  style={{ padding: '12px 16px', borderBottom: '1px solid #f0f0f0' }}
                >
                  <div>
                    <div className="flex items-center gap-2">
                      <h3 style={{ fontSize: '0.95rem', fontWeight: 600, color: '#1a1a1a' }}>
                        {activeCollection.title || getDomain(activeCollection.sourceUrl)}
                      </h3>
                      <StatusBadge status={activeCollection.status as CollectionStatus} />
                    </div>
                    <div className="flex items-center gap-3 mt-1">
                      <span style={{ fontSize: '0.7rem', color: '#888' }}>
                        {activeCollection.images.filter((i: ImageItem) => !i.isExcluded).length} usable images
                      </span>
                      <span style={{ fontSize: '0.7rem', color: '#ccc' }}>·</span>
                      <a
                        href={activeCollection.sourceUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="flex items-center gap-1"
                        style={{ fontSize: '0.7rem', color: '#007bff', textDecoration: 'none' }}
                      >
                        <ExternalLink className="w-3 h-3" />
                        {getDomain(activeCollection.sourceUrl)}
                      </a>
                    </div>
                  </div>

                  <div className="flex items-center gap-2">
                    {activeCollection.status === 'analyzing' || (activeCollection.status as string) === 'scraping' ? null : (
                      <>
                        <button
                          onClick={() => analyzeMutation.mutate({ collectionId: activeCollection.id })}
                          disabled={analyzeMutation.isPending}
                          className="flex items-center gap-1"
                          style={{
                            padding: '5px 12px',
                            borderRadius: '8px',
                            background: '#f0f7ff',
                            color: '#007bff',
                            fontSize: '0.75rem',
                            fontWeight: 600,
                            border: 'none',
                            cursor: analyzeMutation.isPending ? 'not-allowed' : 'pointer',
                          }}
                        >
                          {analyzeMutation.isPending ? (
                            <Loader2 className="w-3.5 h-3.5 animate-spin" />
                          ) : (
                            <RefreshCw className="w-3.5 h-3.5" />
                          )}
                          Re-analyze
                        </button>
                        <button
                          onClick={() => setShowSmartSelect(!showSmartSelect)}
                          className="flex items-center gap-1"
                          style={{
                            padding: '5px 12px',
                            borderRadius: '8px',
                            background: showSmartSelect ? '#7c3aed' : '#f5f3ff',
                            color: showSmartSelect ? '#fff' : '#7c3aed',
                            fontSize: '0.75rem',
                            fontWeight: 600,
                            border: 'none',
                            cursor: 'pointer',
                          }}
                        >
                          <Wand2 className="w-3.5 h-3.5" />
                          Smart Select
                        </button>
                      </>
                    )}
                    <button
                      onClick={() => handleDelete(activeCollection.id)}
                      disabled={deleteMutation.isPending}
                      className="flex items-center gap-1"
                      style={{
                        padding: '5px 10px',
                        borderRadius: '8px',
                        background: '#fef2f2',
                        color: '#dc2626',
                        fontSize: '0.75rem',
                        fontWeight: 500,
                        border: 'none',
                        cursor: 'pointer',
                      }}
                    >
                      <Trash2 className="w-3 h-3" />
                    </button>
                  </div>
                </div>

                {/* Smart Select Panel */}
                {showSmartSelect && (
                  <div style={{ padding: '16px 16px 0' }}>
                    <SmartSelectPanel
                      collectionId={activeCollection.id}
                      onClose={() => setShowSmartSelect(false)}
                    />
                  </div>
                )}

                {/* Category Filter */}
                {categories.length > 0 && (
                  <div
                    className="flex items-center gap-1.5 shrink-0 overflow-x-auto"
                    style={{ padding: '10px 16px 0' }}
                  >
                    <Tag className="w-3.5 h-3.5 shrink-0" style={{ color: '#999' }} />
                    <button
                      onClick={() => setFilterCategory('all')}
                      style={{
                        padding: '3px 10px',
                        borderRadius: '999px',
                        background: filterCategory === 'all' ? '#1a1a1a' : '#f3f4f6',
                        color: filterCategory === 'all' ? '#fff' : '#666',
                        fontSize: '0.65rem',
                        fontWeight: 600,
                        border: 'none',
                        cursor: 'pointer',
                        whiteSpace: 'nowrap',
                      }}
                    >
                      All ({activeCollection.images.filter((i: ImageItem) => !i.isExcluded).length})
                    </button>
                    {categories.map((cat) => {
                      const count = activeCollection.images.filter(
                        (i: ImageItem) => i.category === cat && !i.isExcluded,
                      ).length;
                      return (
                        <button
                          key={cat}
                          onClick={() => setFilterCategory(cat)}
                          style={{
                            padding: '3px 10px',
                            borderRadius: '999px',
                            background: filterCategory === cat ? '#1a1a1a' : '#f3f4f6',
                            color: filterCategory === cat ? '#fff' : '#666',
                            fontSize: '0.65rem',
                            fontWeight: 600,
                            border: 'none',
                            cursor: 'pointer',
                            whiteSpace: 'nowrap',
                            textTransform: 'capitalize',
                          }}
                        >
                          {cat} ({count})
                        </button>
                      );
                    })}
                    <button
                      onClick={() => setFilterCategory('excluded')}
                      style={{
                        padding: '3px 10px',
                        borderRadius: '999px',
                        background: filterCategory === 'excluded' ? '#ef4444' : '#f3f4f6',
                        color: filterCategory === 'excluded' ? '#fff' : '#999',
                        fontSize: '0.65rem',
                        fontWeight: 600,
                        border: 'none',
                        cursor: 'pointer',
                        whiteSpace: 'nowrap',
                      }}
                    >
                      Excluded ({activeCollection.images.filter((i: ImageItem) => i.isExcluded).length})
                    </button>
                  </div>
                )}

                {/* Image Grid */}
                <div
                  className="flex-1 overflow-y-auto"
                  style={{ padding: '12px 16px 16px' }}
                >
                  {collectionDetailQuery.isLoading ? (
                    <div className="flex items-center justify-center h-32">
                      <Loader2 className="w-6 h-6 animate-spin" style={{ color: '#007bff' }} />
                    </div>
                  ) : filteredImages.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-32">
                      <p style={{ fontSize: '0.85rem', color: '#999' }}>
                        {activeCollection.status === 'scraping'
                          ? 'Scraping images...'
                          : activeCollection.status === 'analyzing'
                            ? 'Analyzing images with AI...'
                            : 'No images match this filter'}
                      </p>
                    </div>
                  ) : (
                    <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                      {filteredImages.map((image: ImageItem) => (
                        <ImageCard
                          key={image.id}
                          image={image}
                          onToggleExclude={handleToggleExclude}
                          isSelected={selectedImageIds.has(image.id)}
                        />
                      ))}
                    </div>
                  )}
                </div>
              </>
            ) : (
              <div className="flex flex-col items-center justify-center h-full">
                <ChevronRight className="w-6 h-6 mb-2" style={{ color: '#ccc' }} />
                <p style={{ fontSize: '0.85rem', color: '#999' }}>
                  Select a collection to view images
                </p>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
