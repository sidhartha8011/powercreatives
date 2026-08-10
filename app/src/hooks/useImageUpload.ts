import { useState, useCallback } from 'react';
import { trpc } from '@/lib/trpc';

export interface UseImageUploadOptions {
  onSuccess?: (url: string) => void;
  onError?: (error: Error) => void;
  compress?: boolean;
  maxWidth?: number;
  maxHeight?: number;
  quality?: number;
}

export function useImageUpload(options: UseImageUploadOptions = {}) {
  const [isUploading, setIsUploading] = useState(false);
  const uploadImageMutation = trpc.writer.uploadImage.useMutation();

  const uploadImage = useCallback(async (file: File): Promise<string> => {
    setIsUploading(true);
    try {
      // 1. Optional frontend image compression using HTML5 canvas
      let fileToUpload = file;
      if (options.compress !== false && file.type.startsWith('image/')) {
        fileToUpload = await new Promise<File>((resolve) => {
          const img = new Image();
          img.src = URL.createObjectURL(file);
          img.onload = () => {
            URL.revokeObjectURL(img.src);
            const canvas = document.createElement('canvas');
            const maxWidth = options.maxWidth || 800;
            const maxHeight = options.maxHeight || 800;
            let width = img.width;
            let height = img.height;

            if (width > maxWidth || height > maxHeight) {
              if (width > height) {
                height = Math.round((height * maxWidth) / width);
                width = maxWidth;
              } else {
                width = Math.round((width * maxHeight) / height);
                height = maxHeight;
              }
            }

            canvas.width = width;
            canvas.height = height;
            const ctx = canvas.getContext('2d');
            if (ctx) {
              ctx.drawImage(img, 0, 0, width, height);
              canvas.toBlob(
                (blob) => {
                  if (blob) {
                    const compressedFile = new File([blob], file.name, {
                      type: 'image/jpeg',
                      lastModified: Date.now(),
                    });
                    resolve(compressedFile);
                  } else {
                    resolve(file);
                  }
                },
                'image/jpeg',
                options.quality || 0.75
              );
            } else {
              resolve(file);
            }
          };
          img.onerror = () => resolve(file);
        });
      }

      // 2. Convert file to Base64
      const reader = new FileReader();
      const base64 = await new Promise<string>((resolve, reject) => {
        reader.onload = () => resolve(reader.result as string);
        reader.onerror = reject;
        reader.readAsDataURL(fileToUpload);
      });

      // 3. Perform upload via pre-wired TRPC endpoint
      const result = await uploadImageMutation.mutateAsync({
        fileData: base64,
        filename: fileToUpload.name,
        mimeType: fileToUpload.type,
      });

      options.onSuccess?.(result.url);
      return result.url;
    } catch (err: any) {
      console.error('[useImageUpload] Upload failed:', err);
      const errorObject = err instanceof Error ? err : new Error(String(err));
      options.onError?.(errorObject);
      throw errorObject;
    } finally {
      setIsUploading(false);
    }
  }, [options, uploadImageMutation]);

  /**
   * Upload an image that is ALREADY a data URL, and return its hosted URL.
   *
   * `uploadImage` above takes a `File` and compresses it. Canvas output —
   * a flattened annotation, a freehand draw layer — is already a finished PNG
   * and arrives as a data URL, so it needs the same destination without the
   * File round-trip or a second lossy pass.
   *
   * This exists because a data URL must never be stored in a document. WordPress
   * strips `data:` from any `src` on save (`wp_allowed_protocols()` has no
   * entry for it), which left images source-less and then emptied whole cards.
   * The media library is where an image belongs; the document keeps a URL.
   */
  const uploadDataUrl = useCallback(async (dataUrl: string, filename: string): Promise<string> => {
    const mimeType = dataUrl.slice(5, dataUrl.indexOf(';')) || 'image/png';
    setIsUploading(true);
    try {
      const result = await uploadImageMutation.mutateAsync({ fileData: dataUrl, filename, mimeType });
      options.onSuccess?.(result.url);
      return result.url;
    } catch (err: any) {
      console.error('[useImageUpload] Data-URL upload failed:', err);
      const errorObject = err instanceof Error ? err : new Error(String(err));
      options.onError?.(errorObject);
      throw errorObject;
    } finally {
      setIsUploading(false);
    }
  }, [options, uploadImageMutation]);

  return {
    isUploading,
    uploadImage,
    uploadDataUrl,
  };
}
