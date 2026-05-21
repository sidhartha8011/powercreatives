export const getGradient = (id: number) => {
  const colors = [
    'linear-gradient(135deg, #fceabb 0%, #f8b500 100%)',
    'linear-gradient(135deg, #13547a 0%, #80d0c7 100%)',
    'linear-gradient(135deg, #3a1c71 0%, #d76d77 50%, #ffaf7b 100%)',
    'linear-gradient(135deg, #0ba360 0%, #3cba92 100%)',
  ];
  return colors[id % colors.length];
};

export const formatProjectDate = (dateStr?: string) => {
  if (!dateStr) return '';
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(dateStr));
};

/**
 * Safely extracts the primary database ID from a copy variation ID.
 * Handles pure numbers, numeric strings, and composite duplicate IDs (e.g., "42-copy-177937").
 */
export function extractNumericId(id: string | number | undefined | null): number {
  if (typeof id === 'number') {
    return id;
  }
  
  if (!id) return 0;

  // Extract leading digits before hyphen/suffix (handles "42-copy-123")
  const match = String(id).match(/^(\d+)/);
  if (match) {
    const parsed = parseInt(match[1], 10);
    return isNaN(parsed) ? 0 : parsed;
  }

  return 0;
}
