/**
 * Local photo compression before upload (target ~70% JPEG quality,
 * max dimension downscale). Keeps field uploads small on metered
 * connections and inside the IndexedDB budget.
 */

/**
 * Compress an image File/Blob.
 *
 * @param {File | Blob} file
 * @param {{ quality?: number, maxDim?: number }} [options]
 * @returns {Promise<File | Blob>}
 */
export async function compressImage(file, { quality = 0.7, maxDim = 1600 } = {}) {
    if (!file || !file.type?.startsWith?.('image/')) {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file);
        const scale = Math.min(1, maxDim / Math.max(bitmap.width, bitmap.height));

        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(bitmap.width * scale));
        canvas.height = Math.max(1, Math.round(bitmap.height * scale));
        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);

        const blob = await new Promise((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', quality),
        );

        bitmap.close();

        return blob ?? file;
    } catch {
        return file;
    }
}

/**
 * Object URL for previewing an image blob.
 *
 * @param {File | Blob} file
 * @returns {string | null}
 */
export function previewUrl(file) {
    if (!file || !file.type?.startsWith?.('image/')) {
        return null;
    }

    return URL.createObjectURL(file);
}
