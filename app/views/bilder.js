/*
 * Fotos fuer die Bilderkennung vorbereiten.
 *
 * Steht fuer sich, weil es zwei Aufrufer hat, die sonst nichts gemeinsam
 * haben: die Einleseansicht der App (ein Modul) und die Lerneinheitsseite
 * im Lehrkraft-Bereich (ein gewoehnliches Skript, das sich das hier per
 * import() nachlaedt). Zwei Abschriften derselben Rechnerei waeren bald
 * zwei verschiedene Bildgroessen - und damit zwei verschiedene Rechnungen
 * beim Modell.
 *
 * Ohne Abhaengigkeiten: kein core.js, kein DOM ausserhalb des eigenen
 * <canvas>. Damit laesst es sich von ueberall laden.
 */

/** Hoechstens so viele Fotos gehen in einen Einlesevorgang. Muss zu
 *  MAX_IMAGES in api/import.php passen - dort wird es durchgesetzt. */
export const MAX_IMAGES = 6;

/* Claude skaliert größere Bilder ohnehin herunter - kleiner hochladen spart
   Uploadzeit und Token, ohne an Erkennungsqualität zu verlieren. */
export const MAX_EDGE = 1568;

export const JPEG_QUALITY = 0.82;

/** Verkleinert ein Foto auf MAX_EDGE und liefert Base64-JPEG ohne Data-URL-Prefix. */
export async function shrinkToBase64(file) {
    const bitmap = await loadBitmap(file);

    const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
    const width  = Math.max(1, Math.round(bitmap.width * scale));
    const height = Math.max(1, Math.round(bitmap.height * scale));

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(bitmap, 0, 0, width, height);
    bitmap.close?.();

    const dataUrl = canvas.toDataURL('image/jpeg', JPEG_QUALITY);
    return { data: dataUrl.split(',')[1], media_type: 'image/jpeg' };
}

function loadBitmap(file) {
    if ('createImageBitmap' in window) {
        // imageOrientation korrigiert die EXIF-Drehung von iPhone-Fotos.
        return createImageBitmap(file, { imageOrientation: 'from-image' })
            .catch(() => loadViaImageElement(file));
    }
    return loadViaImageElement(file);
}

function loadViaImageElement(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
        img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('Bild unlesbar')); };
        img.src = url;
    });
}
