/**
 * relTime — "2h ago" style relative time for card meta-lines and log entries.
 * Full timestamps belong in title attributes, not the reading line.
 */
export function relTime(mysqlDate: string): string {
  const then = new Date(mysqlDate.replace(' ', 'T')).getTime();
  if (Number.isNaN(then)) return '';
  const mins = Math.max(0, Math.round((Date.now() - then) / 60000));
  if (mins < 1) return 'just now';
  if (mins < 60) return `${mins}m ago`;
  const hours = Math.round(mins / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.round(hours / 24);
  return `${days}d ago`;
}
