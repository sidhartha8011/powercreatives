/**
 * CSV Export Utility
 *
 * Generic helper to export tabular data as a downloadable CSV file.
 * Handles proper escaping of fields containing commas, quotes, and newlines.
 *
 * @package PowerCreatives
 * @since   1.2.0
 */

/**
 * Escape a single CSV field value.
 * Wraps in double quotes if the value contains commas, quotes, or newlines.
 */
function escapeCsvField(value: unknown): string {
    const str = String(value ?? "");
    // If field contains special characters, wrap in quotes and escape inner quotes
    if (str.includes(",") || str.includes('"') || str.includes("\n") || str.includes("\r")) {
        return `"${str.replace(/"/g, '""')}"`;
    }
    return str;
}

/**
 * Build a CSV string from headers and rows, then trigger a browser download.
 *
 * @param filename - Download filename (e.g. "templates-export.csv")
 * @param headers  - Column header labels
 * @param rows     - Array of row arrays (each row = array of cell values)
 */
export function exportToCsv(
    filename: string,
    headers: string[],
    rows: (string | number | boolean | null | undefined)[][]
): void {
    // Build CSV content with BOM for Excel UTF-8 compatibility
    const bom = "\uFEFF";
    const headerLine = headers.map(escapeCsvField).join(",");
    const dataLines = rows.map((row) => row.map(escapeCsvField).join(","));
    const csvContent = bom + [headerLine, ...dataLines].join("\n");

    // Create blob and trigger download
    const blob = new Blob([csvContent], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = filename;
    link.style.display = "none";
    document.body.appendChild(link);
    link.click();

    // Cleanup
    setTimeout(() => {
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }, 100);
}
