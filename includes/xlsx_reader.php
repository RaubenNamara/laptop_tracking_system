<?php
/**
 * Tiny dependency-free .xlsx reader.
 * Returns an array of rows (each row is an array of cell strings).
 * Supports the first worksheet only and shared strings.
 *
 * Usage:
 *   require_once 'xlsx_reader.php';
 *   $rows = lts_read_xlsx('/path/to/file.xlsx');   // [[h1,h2,h3], [r1c1,r1c2,r1c3], ...]
 */
if (!function_exists('lts_read_xlsx')) {
    function lts_read_xlsx(string $path): array {
        if (!class_exists('ZipArchive')) {
            throw new Exception('PHP zip extension is required to read Excel files. Save your file as CSV instead, or enable extension=zip in php.ini.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Exception('Could not open Excel file (is it valid .xlsx?).');
        }

        // 1) Shared strings table (optional)
        $shared = [];
        $ssXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($ssXml !== false) {
            $sx = @simplexml_load_string($ssXml);
            if ($sx !== false) {
                foreach ($sx->si as $si) {
                    // Concatenate <t> nodes (handles rich text runs)
                    $text = '';
                    if (isset($si->t)) $text .= (string)$si->t;
                    foreach ($si->r as $r) $text .= (string)$r->t;
                    $shared[] = $text;
                }
            }
        }

        // 2) First worksheet — try sheet1.xml then fall back
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (preg_match('#xl/worksheets/sheet\d+\.xml$#', $n)) {
                    $sheetXml = $zip->getFromName($n); break;
                }
            }
        }
        $zip->close();
        if ($sheetXml === false) throw new Exception('No worksheet found inside the Excel file.');

        $sx = @simplexml_load_string($sheetXml);
        if ($sx === false) throw new Exception('Could not parse worksheet XML.');

        $rows = [];
        foreach ($sx->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $ref  = (string)$c['r'];          // e.g. "B3"
                $type = (string)$c['t'];          // "s" = shared string, "" = number, etc.
                $col  = preg_replace('/[0-9]/', '', $ref);
                $idx  = lts_xlsx_col_to_index($col);

                $val = '';
                if ($type === 's') {
                    $i = (int)$c->v;
                    $val = $shared[$i] ?? '';
                } elseif ($type === 'inlineStr') {
                    $val = (string)$c->is->t;
                } elseif ($type === 'b') {
                    $val = ((string)$c->v) === '1' ? 'TRUE' : 'FALSE';
                } else {
                    $val = (string)$c->v;
                }
                $cells[$idx] = $val;
            }
            // Compact to a 0-based array, preserving column gaps as ''
            $maxIdx = $cells ? max(array_keys($cells)) : -1;
            $line = [];
            for ($k = 0; $k <= $maxIdx; $k++) $line[] = $cells[$k] ?? '';
            $rows[] = $line;
        }
        return $rows;
    }
}

if (!function_exists('lts_xlsx_col_to_index')) {
    function lts_xlsx_col_to_index(string $col): int {
        $col = strtoupper($col); $n = 0;
        for ($i = 0, $l = strlen($col); $i < $l; $i++) {
            $n = $n * 26 + (ord($col[$i]) - 64);
        }
        return $n - 1; // 0-based
    }
}
