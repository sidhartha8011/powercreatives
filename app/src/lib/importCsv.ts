/**
 * CSV Import Utility
 *
 * Generic helper to parse a CSV file selected by the user.
 * Handles RFC 4180 quoted fields (commas, newlines, escaped quotes inside fields).
 *
 * @package PowerCreatives
 * @since   1.2.0
 */

/**
 * Parse a CSV string into a 2D array of strings.
 * Correctly handles quoted fields containing commas, newlines, and escaped quotes.
 *
 * @param text - Raw CSV text content
 * @returns Array of rows, each row is an array of field values
 */
export function parseCsvText(text: string): string[][] {
    const rows: string[][] = [];
    let current = "";
    let inQuotes = false;
    let row: string[] = [];

    // Strip BOM if present
    const csv = text.startsWith("\uFEFF") ? text.slice(1) : text;

    for (let i = 0; i < csv.length; i++) {
        const char = csv[i];
        const next = csv[i + 1];

        if (inQuotes) {
            if (char === '"' && next === '"') {
                // Escaped quote inside quoted field
                current += '"';
                i++; // skip next quote
            } else if (char === '"') {
                // End of quoted field
                inQuotes = false;
            } else {
                current += char;
            }
        } else {
            if (char === '"' && current.length === 0) {
                // Start of quoted field
                inQuotes = true;
            } else if (char === ",") {
                // Field separator
                row.push(current);
                current = "";
            } else if (char === "\r" && next === "\n") {
                // CRLF line ending
                row.push(current);
                current = "";
                rows.push(row);
                row = [];
                i++; // skip \n
            } else if (char === "\n") {
                // LF line ending
                row.push(current);
                current = "";
                rows.push(row);
                row = [];
            } else {
                current += char;
            }
        }
    }

    // Push last field and row
    if (current.length > 0 || row.length > 0) {
        row.push(current);
        rows.push(row);
    }

    return rows;
}

/**
 * Read a File object and parse it as CSV.
 *
 * @param file - File from an <input type="file"> element
 * @returns Promise resolving to parsed rows (header row included)
 */
export function parseCsvFile(file: File): Promise<string[][]> {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            try {
                const text = reader.result as string;
                resolve(parseCsvText(text));
            } catch (err) {
                reject(err);
            }
        };
        reader.onerror = () => reject(new Error("Failed to read file"));
        reader.readAsText(file, "utf-8");
    });
}

/**
 * Validate that CSV headers match the expected headers.
 * Comparison is case-insensitive and trimmed.
 *
 * @param actual - Header row from parsed CSV
 * @param expected - Expected header names
 * @returns true if all expected headers are present in order
 */
export function validateCsvHeaders(
    actual: string[],
    expected: string[]
): { valid: boolean; missing: string[] } {
    const normalizedActual = actual.map((h) => h.trim().toLowerCase());
    const missing = expected.filter(
        (h) => !normalizedActual.includes(h.toLowerCase())
    );
    return { valid: missing.length === 0, missing };
}
