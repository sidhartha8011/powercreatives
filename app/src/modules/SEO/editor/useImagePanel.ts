/**
 * THE IMAGE MACHINERY (decomposition S5, gap 325d280/10a0233): the locked
 * image's selection watcher, the alt/title rule form, add-image delivery,
 * and save/revert/hide — one owner. Moved verbatim from SectionModal.tsx;
 * an image bug lives in THIS file.
 */

import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import type { Editor } from '@tiptap/core';

import { trpc } from '@/lib/trpc';
import { normSrc } from './content-laws';
import type { ImgSelection } from './types';

declare const wp: any;

export type ImageBusyAction = 'imageAdd' | 'imageSave' | 'imageHide' | 'imageRevert';

export interface UseImagePanelArgs {
  editor: Editor | null;
  isPage: boolean;
  readOnly: boolean;
  busy: boolean;
  siteId: number | 'local';
  postId: number;
  /** The composer's one-long-action-at-a-time guard, image verbs only. */
  setBusyAction: (action: ImageBusyAction | null) => void;
}

export function useImagePanel(args: UseImagePanelArgs) {
  const { editor, isPage, readOnly, busy, siteId, postId, setBusyAction } = args;

  // ── Image metadata (page mode, V3): click a locked image → side panel
  //    edits its alt/title as a dynamic IMAGE rule (attr rewrite at render
  //    time — the image itself never moves). Server keeps the ORIGINAL attrs
  //    from rule creation; editing back (or Revert) deletes the rule.
  const [imgSel, setImgSel] = useState<ImgSelection | null>(null);
  const [imgForm, setImgForm] = useState({ alt: '', title: '' });
  const imageRulesQuery = trpc.seo.remoteGetParagraphRules.useQuery(
    { siteId: siteId as number, postId },
    { enabled: isPage && !readOnly, staleTime: 0 },
  );
  const imageRules: Array<{ target: string; matchText: string; occurrence: number; active: boolean }> =
    Array.isArray((imageRulesQuery.data as any)?.rules) ? (imageRulesQuery.data as any).rules : [];
  const imgHasRule = imgSel != null && imageRules.some((r) =>
    r.target === 'image' && r.active && r.occurrence === imgSel.occurrence && r.matchText === normSrc(imgSel.src));
  const saveImageMutation = trpc.seo.remoteSaveImageRule.useMutation();

  useEffect(() => {
    if (!isPage || !editor) return;
    const onSel = () => {
      const sel: any = editor.state.selection;
      const node = sel?.node;
      if (node?.type?.name !== 'image') {
        setImgSel(null);
        return;
      }
      const src = String(node.attrs?.src ?? '');
      // Occurrence among same-src images in document order — the rule identity.
      let occ = 0;
      editor.state.doc.descendants((n: any, pos: number) => {
        if (n.type?.name === 'image' && pos < sel.from && normSrc(String(n.attrs?.src ?? '')) === normSrc(src)) occ++;
        return true;
      });
      const alt = String(node.attrs?.alt ?? '');
      const nodeTitle = String(node.attrs?.title ?? '');
      setImgSel({ src, occurrence: occ, alt, title: nodeTitle });
      setImgForm({ alt, title: nodeTitle });
    };
    editor.on('selectionUpdate', onSel);
    return () => { editor.off('selectionUpdate', onSel); };
  }, [isPage, editor]);

  // ── Add image (phase 1): pick from the hub's media library → the file is
  //    delivered into the CLIENT site's own library (autonomy law) → the
  //    client-native URL is inserted at the cursor, marked platform-added. ──
  const uploadMediaMutation = trpc.seo.remoteUploadMedia.useMutation();
  const addImage = () => {
    if (busy) return;
    if (typeof wp === 'undefined' || !wp.media) {
      toast.error('The media library isn’t available on this screen.');
      return;
    }
    const frame = wp.media({ title: 'Add image', multiple: false, library: { type: 'image' } });
    frame.on('select', () => {
      const att = frame.state().get('selection').first().toJSON();
      void (async () => {
        setBusyAction('imageAdd');
        try {
          const res: any = await uploadMediaMutation.mutateAsync({ siteId: siteId as number, url: String(att.url ?? '') });
          const clientUrl = String(res?.url ?? '');
          if (!clientUrl) throw new Error('The site did not return the delivered image’s URL.');
          editor?.chain().focus().insertContent({
            type: 'image',
            attrs: {
              src: clientUrl,
              alt: String(att.alt || att.title || ''),
              'data-pcm-added': String(res?.id ?? '1'),
            },
          }).run();
          toast.success('Image added — it now lives in the site’s own media library.');
        } catch (e: any) {
          toast.error(e?.message ?? 'Could not deliver the image to the site');
        } finally {
          setBusyAction(null);
        }
      })();
    });
    frame.open();
  };

  const saveImageMeta = async (mode: 'save' | 'revert' | 'hide') => {
    if (!imgSel || busy) return;
    setBusyAction(mode === 'save' ? 'imageSave' : mode === 'hide' ? 'imageHide' : 'imageRevert');
    try {
      const res: any = await saveImageMutation.mutateAsync({
        siteId: siteId as number, postId,
        src: imgSel.src, occurrence: imgSel.occurrence,
        alt: imgForm.alt, title: imgForm.title,
        // For a NEW rule the attrs as selected ARE the originals; for an
        // existing rule the server keeps its stored originals regardless.
        originalAlt: imgSel.alt, originalTitle: imgSel.title,
        revert: mode === 'revert',
        hidden: mode === 'hide',
      });
      if (mode === 'hide') {
        // Reflect the hide in the doc — the live page stops serving the tag;
        // the media library is untouched. Un-hide = restore a version.
        editor?.chain().focus().deleteSelection().run();
        toast.success('Image hidden — the site serves without it (the file stays in the media library).');
      } else if (res?.reverted) {
        // Apply the server-returned originals in place — the document is
        // never auto-replaced (incident fix 2026-07-11).
        editor?.commands.updateAttributes('image', {
          alt: String(res?.original?.alt ?? ''),
          title: String(res?.original?.title ?? '') || null,
        });
        toast.success('Image metadata reverted — the original serves again.');
      } else {
        editor?.commands.updateAttributes('image', { alt: imgForm.alt, title: imgForm.title });
        toast.success('Saved — the site serves the new image metadata.');
      }
      void imageRulesQuery.refetch();
      setImgSel(null);
    } catch (e: any) {
      toast.error(e?.message ?? 'Could not save the image metadata');
    } finally {
      setBusyAction(null);
    }
  };

  return { imgSel, setImgSel, imgForm, setImgForm, imgHasRule, addImage, saveImageMeta };
}
