/**
 * ArticleCard — domain card body for the Articles kanban.
 *
 * Title + metadata + status-change <select>. The select stays because we
 * don't have drag-and-drop yet; it's small and accessible.
 */

import { ExternalLink } from 'lucide-react';

import type { StatusKey } from '@/components/shared/design-tokens';

import { articleColumns } from './articleColumns';
import styles from './articleCard.module.css';
import type { Article } from '../types';

export interface ArticleCardProps {
  article: Article;
  onStatusChange: (articleId: number, next: StatusKey) => void;
}

export function ArticleCard({ article, onStatusChange }: ArticleCardProps) {
  return (
    <article className={styles.card}>
      <div className={styles.title}>{article.title}</div>

      <div className={styles.meta}>
        <span>{new Date(article.updatedAt).toLocaleDateString()}</span>
        {article.publishedUrl && (
          <>
            <span className={styles.metaSep}>·</span>
            <a
              href={article.publishedUrl}
              target="_blank"
              rel="noopener noreferrer"
              className={styles.liveLink}
            >
              <ExternalLink className={styles.linkIcon} aria-hidden="true" />
              View live
            </a>
          </>
        )}
      </div>

      <select
        className={styles.statusSelect}
        value={article.status}
        onChange={(e) => onStatusChange(article.id, e.target.value as StatusKey)}
        aria-label={`Change status for ${article.title}`}
      >
        {articleColumns.map((col) => (
          <option key={col.id} value={col.id}>
            {col.label}
          </option>
        ))}
      </select>
    </article>
  );
}
