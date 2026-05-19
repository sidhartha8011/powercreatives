// fflate is dynamically imported below to avoid Vite bundling initialization issues
import type { TextSlot, MediaSlot } from '../types';

/**
 * Escapes a CSV cell.
 */
function escapeCsvCell(cell: string): string {
  if (!cell) return '';
  const escaped = String(cell).replace(/"/g, '""');
  // If it contains comma, newline, or quotes, wrap in quotes
  if (escaped.includes(',') || escaped.includes('\n') || escaped.includes('"')) {
    return `"${escaped}"`;
  }
  return escaped;
}

/**
 * Exports the generated Ads (Text + Media) to a ZIP file containing:
 * 1. meta_ads_import.csv (Meta Ads Bulk Import format)
 * 2. All associated image files
 */
export async function exportToMetaAdsZip(
  textSlots: TextSlot[],
  mediaSlots: MediaSlot[],
  campaignName: string = 'PowerCreatives Campaign',
  adSetName: string = 'PowerCreatives Ad Set'
): Promise<void> {
  if (textSlots.length === 0 && mediaSlots.length === 0) {
    throw new Error('No ads to export.');
  }

  // Dynamically import fflate
  const fflate = await import('fflate');
  const zipSync = fflate.zipSync;
  const strToU8 = fflate.strToU8;

  const zipData: Record<string, Uint8Array> = {};
  
  // CSV Headers based on Meta Ads Bulk Import Format
  const csvRows: string[][] = [
    [
      'Campaign Name',
      'Ad Set Name',
      'Ad Name',
      'Title',
      'Body',
      'Description',
      'Call to Action',
      'Image File Name',
      'Link'
    ]
  ];

  // We pair textSlots and mediaSlots. If there are more text slots than media, we loop media.
  // If more media than text, we loop text.
  const adCount = Math.max(textSlots.length, mediaSlots.length);
  
  for (let i = 0; i < adCount; i++) {
    const text = textSlots.length > 0 ? textSlots[i % textSlots.length] : undefined;
    const media = mediaSlots.length > 0 ? mediaSlots[i % mediaSlots.length] : undefined;
    
    // Fallbacks if one is missing
    const headline = text?.headline || '';
    const body = text?.body || '';
    const description = text?.description || '';
    const cta = text?.cta || 'LEARN_MORE';
    const audienceName = text?.audienceName || 'General';

    let fileName = '';

    // 1. Fetch image and add to ZIP if media exists
    if (media && media.status === 'complete' && media.url) {
      try {
        const response = await fetch(media.url);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const arrayBuffer = await response.arrayBuffer();
        
        const fileExtension = media.url.split('.').pop()?.split('?')[0] || 'jpg';
        fileName = `ad_image_${i + 1}.${fileExtension}`;
        
        zipData[fileName] = new Uint8Array(arrayBuffer);
      } catch (err) {
        console.error(`Failed to download image for Ad ${i + 1}`, err);
      }
    }
      
    // 2. Add row to CSV
    const adName = `Ad ${i + 1} - ${audienceName}`;
    
    csvRows.push([
      campaignName,
      adSetName,
      adName,
      headline,
      body,
      description,
      cta,
      fileName,
      'https://powercreatives.com' // Placeholder link
    ]);
  }

  // 3. Generate CSV string
  const csvString = csvRows.map(row => row.map(escapeCsvCell).join(',')).join('\n');
  zipData['meta_ads_import.csv'] = strToU8(csvString);

  // 4. Generate and download ZIP
  const zipped = zipSync(zipData);
  const blob = new Blob([zipped], { type: 'application/zip' });
  
  // Create a temporary link to trigger download
  const url = window.URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'powercreatives_meta_export.zip';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
  window.URL.revokeObjectURL(url);
}
