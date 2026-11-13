<?php 
require 'config.php'; 
require_role(['Supervisor', 'Administrator']);

function column_letter($index)
{
	$letter = '';
	while ($index > 0) {
		$index--;
		$letter = chr(65 + ($index % 26)) . $letter;
		$index = intdiv($index, 26);
	}
	return $letter;
}

function build_xlsx_bytes(array $rows)
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('PHP ZIP extension is not enabled. Enable extension=zip in php.ini and restart Apache.');
    }

    $sheetRows = [];
    $columnWidths = [];

    foreach ($rows as $row) {
        foreach ($row as $colIndex => $value) {
            $length = strlen((string) $value);
            if (!isset($columnWidths[$colIndex]) || $length > $columnWidths[$colIndex]) {
                $columnWidths[$colIndex] = $length;
            }
        }
    }

    $colsXml = '';
    foreach ($columnWidths as $colIndex => $length) {
        $width = min(max($length + 2, 10), 50);
        $columnNumber = $colIndex + 1;
        $colsXml .= '<col min="' . $columnNumber . '" max="' . $columnNumber . '" width="' . $width . '" customWidth="1"/>';
    }

    foreach ($rows as $rowIndex => $row) {
        $cells = [];
        foreach ($row as $colIndex => $value) {
            $cellRef = column_letter($colIndex + 1) . ($rowIndex + 1);
            if ($value === null || $value === '') {
                $cells[] = '<c r="' . $cellRef . '"/>';
                continue;
            }
            if (is_numeric($value)) {
                $cells[] = '<c r="' . $cellRef . '" t="n"><v>' . (string) $value . '</v></c>';
                continue;
            }
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $cells[] = '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . $escaped . '</t></is></c>';
        }
        $sheetRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>' . $colsXml . '</cols><sheetData>' . implode('', $sheetRows) . '</sheetData></worksheet>';
    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/></Relationships>';
    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/><Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/></Types>';
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>BeanTrack</dc:creator><cp:lastModifiedBy>BeanTrack</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:modified></cp:coreProperties>';
    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Microsoft Excel</Application></Properties>';

    $tmp = tempnam(sys_get_temp_dir(), 'beantrack_xlsx_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $fileContents = file_get_contents($tmp);
    unlink($tmp);
    return $fileContents;
}

// Download Blank Client Format Template XLSX (Entries removed, format preserved)
if (isset($_GET['download_template'])) {
    $templatePath = __DIR__ . '/reports/Daily-Report-Template.xlsx';
    if (file_exists($templatePath)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Daily-Report-Template.xlsx"');
        header('Content-Length: ' . filesize($templatePath));
        readfile($templatePath);
        exit;
    }
}

// Helper: Generate Client-Format Excel Workbook (.xlsx) with clean OpenXML table and relations
function generate_client_excel_file(PDO $pdo, string $from, string $to, string $branch = '', ?string $outputPath = null): string
{
    $templatePath = __DIR__ . '/reports/Daily-Report-Template.xlsx';
    if (!file_exists($templatePath)) {
        throw new RuntimeException('Daily Report Template file not found at: ' . $templatePath);
    }

    // ── 1. Fetch all data ──────────────────────────────────────────────────
    $sql = "SELECT t.transaction_date, t.customer_name, t.bundle, t.branch_name, t.payment_method,
                   ti.brand_name, ti.product_name, ti.remarks AS item_remarks, ti.price_type,
                   ti.ordered_qty, ti.unit_qty, ti.unit_price, ti.subtotal, ti.price_seq, ti.bundle_seq
            FROM transactions t JOIN transaction_items ti ON ti.transaction_id = t.id
            WHERE t.transaction_date BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($branch !== '' && $branch !== 'all') { $sql .= " AND t.branch_name = ?"; $params[] = $branch; }
    $sql .= " ORDER BY t.transaction_date ASC, t.id ASC, ti.id ASC";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $salesRows = $stmt->fetchAll();

    $sqlArr = "SELECT date_arrived, supplier, po_no, branch_name, brand_name, item_name, cases, qty, remarks
               FROM supplier_deliveries WHERE date_arrived BETWEEN ? AND ?";
    $pArr = [$from, $to];
    if ($branch !== '' && $branch !== 'all') { $sqlArr .= " AND branch_name = ?"; $pArr[] = $branch; }
    $stmtArr = $pdo->prepare($sqlArr . " ORDER BY date_arrived ASC, id ASC"); $stmtArr->execute($pArr);
    $arrivals = $stmtArr->fetchAll(PDO::FETCH_NUM);

    $sqlSpoi = "SELECT date, branch_name, brand_name, item_name, qty, remarks
                FROM inventory_spoilage WHERE date BETWEEN ? AND ?";
    $pSpoi = [$from, $to];
    if ($branch !== '' && $branch !== 'all') { $sqlSpoi .= " AND branch_name = ?"; $pSpoi[] = $branch; }
    $stmtSpoi = $pdo->prepare($sqlSpoi . " ORDER BY date ASC, id ASC"); $stmtSpoi->execute($pSpoi);
    $spoilages = $stmtSpoi->fetchAll(PDO::FETCH_NUM);

    $stmtTrans = $pdo->prepare("SELECT date, from_branch_name, to_branch_name, brand_name, item_name, qty, remarks
                                 FROM inventory_transfers WHERE date BETWEEN ? AND ? ORDER BY date ASC, id ASC");
    $stmtTrans->execute([$from, $to]);
    $transfers = $stmtTrans->fetchAll(PDO::FETCH_NUM);

    // ── 2. Copy template to temp file, then open and modify ONLY changed entries ──
    $tmp = tempnam(sys_get_temp_dir(), 'client_xlsx_');
    copy($templatePath, $tmp);

    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        unlink($tmp);
        throw new RuntimeException('Cannot open template ZIP copy');
    }

    // ── 3. Build modified XML for sheet1 ──────────────────────────────────
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $rowXmlList = [];
    $rowNum = 2;
    foreach ($salesRows as $r) {
        $txDate = $r['transaction_date'] ?: date('Y-m-d');
        $ts = strtotime($txDate);
        $values = [
            $txDate, date('M', $ts), date('D', $ts), date('Y', $ts), date('Y M', $ts),
            $r['customer_name'] ?: 'WALK-IN', $r['bundle'] ?: '', $r['branch_name'] ?: 'DAVAO',
            $r['brand_name'] ?: '', $r['product_name'] ?: '', $r['item_remarks'] ?: '',
            $r['price_type'] ?: 'SRP', $r['payment_method'] ?: 'Cash',
            (float)$r['ordered_qty'], (float)$r['unit_qty'], (float)$r['unit_price'],
            (float)$r['subtotal'], $r['price_seq'] ?: '', $r['bundle_seq'] ?: 'NOT A BUNDLE'
        ];
        $cells = [];
        foreach ($values as $ci => $val) {
            $ref = column_letter($ci + 1) . $rowNum;
            if ($val === '' || $val === null) {
                $cells[] = '<c r="' . $ref . '"/>';
            } elseif (is_numeric($val) && $ci >= 13 && $ci <= 16) {
                $cells[] = '<c r="' . $ref . '" t="n"><v>' . (float)$val . '</v></c>';
            } else {
                $esc = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
                $cells[] = '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc . '</t></is></c>';
            }
        }
        $rowXmlList[] = '<row r="' . $rowNum . '">' . implode('', $cells) . '</row>';
        $rowNum++;
    }
    if (empty($rowXmlList)) {
        $cells = [];
        for ($c = 1; $c <= 19; $c++) $cells[] = '<c r="' . column_letter($c) . '2"/>';
        $rowXmlList[] = '<row r="2">' . implode('', $cells) . '</row>';
        $lastRow = 2;
    } else {
        $lastRow = $rowNum - 1;
    }
    $sheetXml = preg_replace('/<row r="([2-9]|\d{2,})"[^>]*>.*?<\/row>/s', '', $sheetXml);
    $sheetXml = str_replace('</sheetData>', implode('', $rowXmlList) . '</sheetData>', $sheetXml);
    $sheetXml = preg_replace('/<dimension ref="[^"]*"/', '<dimension ref="A1:S' . $lastRow . '"', $sheetXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

    $table1Xml = $zip->getFromName('xl/tables/table1.xml');
    if (is_string($table1Xml)) {
        $table1Xml = preg_replace('/ref="A1:S\d+"/', 'ref="A1:S' . $lastRow . '"', $table1Xml);
        $table1Xml = preg_replace('/<autoFilter ref="A1:S\d+"/', '<autoFilter ref="A1:S' . $lastRow . '"', $table1Xml);
        $zip->addFromString('xl/tables/table1.xml', $table1Xml);
    }

    // ── 4. Sheet 2, 3, 4 helper ───────────────────────────────────────────
    $writeSheet = function(string $sheetFile, string $tableFile, array $dataRows, array $numCols, string $endCol) use ($zip) {
        $sXml = $zip->getFromName($sheetFile);
        if (!is_string($sXml)) return;
        $sXml = preg_replace('/<row r="([2-9]|\d{2,})"[^>]*>.*?<\/row>/s', '', $sXml);
        if (empty($dataRows)) {
            // Provide 1 empty row so Excel table definition A1:{endCol}2 is structurally valid
            $numColsTotal = ord($endCol) - 64;
            $cells = '';
            for ($c = 1; $c <= $numColsTotal; $c++) {
                $cells .= '<c r="' . column_letter($c) . '2"/>';
            }
            $sXml = str_replace('</sheetData>', '<row r="2">' . $cells . '</row></sheetData>', $sXml);
            $lastR = 2;
        } else {
            $newRows = '';
            $rNum = 2;
            foreach ($dataRows as $row) {
                $cells = '';
                foreach ($row as $ci => $val) {
                    $ref = column_letter($ci + 1) . $rNum;
                    if ($val === null || $val === '') {
                        $cells .= '<c r="' . $ref . '"/>';
                    } elseif (in_array($ci, $numCols, true)) {
                        $cells .= '<c r="' . $ref . '" t="n"><v>' . (float)$val . '</v></c>';
                    } else {
                        $esc = htmlspecialchars((string)$val, ENT_XML1, 'UTF-8');
                        $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $esc . '</t></is></c>';
                    }
                }
                $newRows .= '<row r="' . $rNum . '">' . $cells . '</row>';
                $rNum++;
            }
            $lastR = $rNum - 1;
            $sXml = str_replace('</sheetData>', $newRows . '</sheetData>', $sXml);
        }
        $newRef = 'A1:' . $endCol . $lastR;
        $sXml = preg_replace('/<dimension ref="[^"]*"/', '<dimension ref="' . $newRef . '"', $sXml);
        $zip->addFromString($sheetFile, $sXml);

        $tXml = $zip->getFromName($tableFile);
        if (is_string($tXml)) {
            $tXml = preg_replace('/\bref="A1:' . preg_quote($endCol, '/') . '\d+"/', 'ref="' . $newRef . '"', $tXml);
            $tXml = preg_replace('/<autoFilter ref="[^"]*"/', '<autoFilter ref="' . $newRef . '"', $tXml);
            $zip->addFromString($tableFile, $tXml);
        }
    };

    $writeSheet('xl/worksheets/sheet2.xml', 'xl/tables/table2.xml', $arrivals, [6, 7], 'I');
    $writeSheet('xl/worksheets/sheet3.xml', 'xl/tables/table3.xml', $spoilages, [4], 'F');
    $writeSheet('xl/worksheets/sheet4.xml', 'xl/tables/table4.xml', $transfers, [5], 'G');

    // ── 4b. Sheet 8 (Details) — dynamic master data from system database ──
    $dbProducts = $pdo->query("SELECT COALESCE(qty_per_case, 1) as qty_per_case, COALESCE(brand, '') as brand, name as item, COALESCE(category, '') as category FROM products ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $dbBrands = $pdo->query("SELECT DISTINCT name FROM brands WHERE name IS NOT NULL AND name != '' UNION SELECT DISTINCT brand FROM products WHERE brand IS NOT NULL AND brand != '' ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
    $dbBranches = $pdo->query("SELECT DISTINCT code FROM branches WHERE code IS NOT NULL AND code != '' ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $dbPriceTypes = ['SRP', 'WHOLESALE', 'BULK', 'DEALER', 'RESELLER', 'REBRANDING'];

    $prodEndRow = max(count($dbProducts) + 1, 2);
    $brandEndRow = max(count($dbBrands) + 1, 2);
    $branchEndRow = max(count($dbBranches) + 1, 2);
    $priceEndRow = max(count($dbPriceTypes) + 1, 2);
    $maxDetailRows = max($prodEndRow, $brandEndRow, $branchEndRow, $priceEndRow);

    $s8 = $zip->getFromName('xl/worksheets/sheet8.xml');
    if (is_string($s8)) {
        $s8 = preg_replace('/<sheetData>.*?<\/sheetData>/s', '<sheetData></sheetData>', $s8);
        $s8Rows = [];
        $s8Rows[] = '<row r="1">' .
            '<c r="A1" t="inlineStr"><is><t>QTY/CASE</t></is></c>' .
            '<c r="B1" t="inlineStr"><is><t>BRAND</t></is></c>' .
            '<c r="C1" t="inlineStr"><is><t>ITEM</t></is></c>' .
            '<c r="D1" t="inlineStr"><is><t>TYPE</t></is></c>' .
            '<c r="F1" t="inlineStr"><is><t>BRANDS</t></is></c>' .
            '<c r="H1" t="inlineStr"><is><t>BRANCH</t></is></c>' .
            '<c r="J1" t="inlineStr"><is><t>PRICE TYPE</t></is></c>' .
            '</row>';

        for ($r = 2; $r <= $maxDetailRows; $r++) {
            $idx = $r - 2;
            $cells = [];

            // Products (Cols A-D)
            if (isset($dbProducts[$idx])) {
                $cells[] = '<c r="A' . $r . '" t="n"><v>' . (float)$dbProducts[$idx]['qty_per_case'] . '</v></c>';
                $cells[] = '<c r="B' . $r . '" t="inlineStr"><is><t>' . htmlspecialchars($dbProducts[$idx]['brand'], ENT_XML1, 'UTF-8') . '</t></is></c>';
                $cells[] = '<c r="C' . $r . '" t="inlineStr"><is><t>' . htmlspecialchars($dbProducts[$idx]['item'], ENT_XML1, 'UTF-8') . '</t></is></c>';
                $cells[] = '<c r="D' . $r . '" t="inlineStr"><is><t>' . htmlspecialchars($dbProducts[$idx]['category'], ENT_XML1, 'UTF-8') . '</t></is></c>';
            } elseif ($r === 2 && empty($dbProducts)) {
                $cells[] = '<c r="A2"/><c r="B2"/><c r="C2"/><c r="D2"/>';
            }

            // Brands (Col F)
            if (isset($dbBrands[$idx])) {
                $cells[] = '<c r="F' . $r . '" t="inlineStr"><is><t>' . htmlspecialchars($dbBrands[$idx], ENT_XML1, 'UTF-8') . '</t></is></c>';
            } elseif ($r === 2 && empty($dbBrands)) {
                $cells[] = '<c r="F2"/>';
            }

            // Branches (Col H)
            if (isset($dbBranches[$idx])) {
                $cells[] = '<c r="H' . $r . '" t="inlineStr"><is><t>' . htmlspecialchars($dbBranches[$idx], ENT_XML1, 'UTF-8') . '</t></is></c>';
            } elseif ($r === 2 && empty($dbBranches)) {
                $cells[] = '<c r="H2"/>';
            }

            // Price types (Col J)
            if (isset($dbPriceTypes[$idx])) {
                $cells[] = '<c r="J' . $r . '" t="inlineStr"><is><t>' . htmlspecialchars($dbPriceTypes[$idx], ENT_XML1, 'UTF-8') . '</t></is></c>';
            } elseif ($r === 2 && empty($dbPriceTypes)) {
                $cells[] = '<c r="J2"/>';
            }

            if (!empty($cells)) {
                $s8Rows[] = '<row r="' . $r . '">' . implode('', $cells) . '</row>';
            }
        }

        $s8 = str_replace('<sheetData></sheetData>', '<sheetData>' . implode('', $s8Rows) . '</sheetData>', $s8);
        $s8 = preg_replace('/<dimension ref="[^"]*"/', '<dimension ref="A1:J' . $maxDetailRows . '"', $s8);
        $zip->addFromString('xl/worksheets/sheet8.xml', $s8);

        // Sync table ranges for Sheet 8
        $t11 = $zip->getFromName('xl/tables/table11.xml');
        if (is_string($t11)) {
            $t11 = preg_replace('/\bref="[A-Z0-9:]+"/', 'ref="A1:D' . $prodEndRow . '"', $t11);
            $t11 = preg_replace('/<autoFilter ref="[^"]*"/', '<autoFilter ref="A1:D' . $prodEndRow . '"', $t11);
            $zip->addFromString('xl/tables/table11.xml', $t11);
        }
        $t12 = $zip->getFromName('xl/tables/table12.xml');
        if (is_string($t12)) {
            $t12 = preg_replace('/\bref="[A-Z0-9:]+"/', 'ref="F1:F' . $brandEndRow . '"', $t12);
            $t12 = preg_replace('/<autoFilter ref="[^"]*"/', '<autoFilter ref="F1:F' . $brandEndRow . '"', $t12);
            $zip->addFromString('xl/tables/table12.xml', $t12);
        }
        $t10 = $zip->getFromName('xl/tables/table10.xml');
        if (is_string($t10)) {
            $t10 = preg_replace('/\bref="[A-Z0-9:]+"/', 'ref="H1:H' . $branchEndRow . '"', $t10);
            $t10 = preg_replace('/<autoFilter ref="[^"]*"/', '<autoFilter ref="H1:H' . $branchEndRow . '"', $t10);
            $zip->addFromString('xl/tables/table10.xml', $t10);
        }
        $t13 = $zip->getFromName('xl/tables/table13.xml');
        if (is_string($t13)) {
            $t13 = preg_replace('/\bref="[A-Z0-9:]+"/', 'ref="J1:J' . $priceEndRow . '"', $t13);
            $t13 = preg_replace('/<autoFilter ref="[^"]*"/', '<autoFilter ref="J1:J' . $priceEndRow . '"', $t13);
            $zip->addFromString('xl/tables/table13.xml', $t13);
        }
    }

    // ── 5. Sanitize — only needed if template still has these ─────────────
    // Strip #REF! definedNames from workbook.xml
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if (is_string($wbXml) && strpos($wbXml, '#REF!') !== false) {
        $wbXml = preg_replace('/<definedName[^>]*>#REF!<\/definedName>\s*/', '', $wbXml);
        $wbXml = preg_replace('/<definedNames>\s*<\/definedNames>/', '', $wbXml);
        $zip->addFromString('xl/workbook.xml', $wbXml);
    }
    // Remove table7 if it exists (template should already not have it)
    $zip->deleteName('xl/tables/table7.xml');
    $zip->deleteName('xl/calcChain.xml');

    $zip->close();

    if ($outputPath !== null) {
        copy($tmp, $outputPath);
        unlink($tmp);
        return $outputPath;
    }
    return $tmp;
}



// Export Client Excel Workbook (.xlsx matching client 8-sheet format)
if (isset($_GET['export_client_xlsx'])) {
    $from = trim($_GET['from'] ?? '2025-01-01');
    $to = trim($_GET['to'] ?? date('Y-m-d'));
    $branch = trim($_GET['branch'] ?? '');

    $tmp = generate_client_excel_file($pdo, $from, $to, $branch, null);
    audit('Export Client Report (.xlsx)', 'reports', null, "Exported Client Daily Report (.xlsx) for " . ($branch !== '' && $branch !== 'all' ? $branch : 'All Branches') . " ($from to $to)");

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="client_daily_report_' . $from . '_to_' . $to . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

// 1. Exact 19-Column Client Sales CSV Export
if (isset($_GET['export_client_sales'])) {
    $from = trim($_GET['from'] ?? '2025-01-01');
    $to = trim($_GET['to'] ?? date('Y-m-d'));
    $branch = trim($_GET['branch'] ?? '');

    $sql = "
        SELECT 
            t.transaction_date,
            t.customer_name,
            t.bundle,
            t.branch_name,
            t.payment_method,
            ti.brand_name,
            ti.product_name,
            ti.remarks AS item_remarks,
            ti.price_type,
            ti.ordered_qty,
            ti.unit_qty,
            ti.unit_price,
            ti.subtotal,
            ti.price_seq,
            ti.bundle_seq
        FROM transactions t
        JOIN transaction_items ti ON ti.transaction_id = t.id
        WHERE t.transaction_date BETWEEN ? AND ?
    ";
    $params = [$from, $to];
    if ($branch !== '' && $branch !== 'all') {
        $sql .= " AND t.branch_name = ?";
        $params[] = $branch;
    }
    $sql .= " ORDER BY t.transaction_date ASC, t.id ASC, ti.id ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="client_sales_report_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');

    // Exact client header:
    fputcsv($out, [
        'DATE', 'MONTH', 'DAY', 'YEAR', 'M+Y', 
        'CUSTOMER', 'BUNDLE', 'BRANCH', 'BRAND', 'ITEM', 
        'REMARKS', 'PRICE TYPE', 'MOP', 'QTY', 'U-QTY', 
        'U-PRICE', 'Amount', 'PRICE SEQ', 'BUNDLE SEQ'
    ]);

    foreach ($rows as $r) {
        $txDate = $r['transaction_date'] ?: date('Y-m-d');
        $ts = strtotime($txDate);
        fputcsv($out, [
            $txDate,
            date('M', $ts),
            date('D', $ts),
            date('Y', $ts),
            date('Y M', $ts),
            $r['customer_name'] ?: 'WALK-IN',
            $r['bundle'] ?: '',
            $r['branch_name'] ?: 'DAVAO',
            $r['brand_name'],
            $r['product_name'],
            $r['item_remarks'] ?: '',
            $r['price_type'] ?: 'SRP',
            $r['payment_method'] ?: 'Cash',
            (float)$r['ordered_qty'],
            (float)$r['unit_qty'],
            (float)$r['unit_price'],
            (float)$r['subtotal'],
            $r['price_seq'] ?: '',
            $r['bundle_seq'] ?: 'NOT A BUNDLE'
        ]);
    }
    fclose($out);
    audit('Export Client Sales (CSV)', 'reports', null, "Exported 19-Column Sales CSV for " . ($branch !== '' && $branch !== 'all' ? $branch : 'All Branches') . " ($from to $to); Rows: " . count($rows));
    exit;
}

// Default filter values
$from = trim($_GET['from'] ?? '2025-01-01');
$to = trim($_GET['to'] ?? date('Y-m-d'));
$branch = trim($_GET['branch'] ?? '');
$report_message = '';

// Generate Client-Format Excel Workbook for Management Review
if (isset($_GET['generate'])) {
    $branch_name = $branch !== '' && $branch !== 'all' ? $branch : 'Main Store';
    $generated_by = $_SESSION['user']['name'] ?? 'Supervisor';
    $generated_at = date('Y-m-d H:i:s');
    $sanitized_branch = preg_replace('/[^A-Za-z0-9 _-]/', '', $branch_name) ?: 'main-store';
    $base_name = strtolower(str_replace(' ', '-', trim($sanitized_branch))) . '-' . date('Ymd-His');
    $xlsx_filename = $base_name . '.xlsx';
    $report_dir = __DIR__ . '/reports/generated';
    if (!is_dir($report_dir)) {
        mkdir($report_dir, 0777, true);
    }

    $outFilePath = $report_dir . '/' . $xlsx_filename;
    generate_client_excel_file($pdo, $from, $to, $branch, $outFilePath);

    // Save metadata json sidecar for management review dashboard
    $meta = [
        'branch' => $branch_name,
        'period' => $from . ' to ' . $to,
        'generated_by' => $generated_by,
        'generated_at' => $generated_at
    ];
    file_put_contents($report_dir . '/' . $base_name . '.json', json_encode($meta, JSON_PRETTY_PRINT));

    $report_message = "Executive Client-Format Excel report generated ($from to $to) and saved to Management Review Archive.";
    audit('Generate Static Report', 'reports', null, "Generated client format report for $branch_name ($from to $to)");
}

// Query Live Stats
$sqlWhere = " WHERE t.transaction_date BETWEEN ? AND ?";
$sqlParams = [$from, $to];
if ($branch !== '' && $branch !== 'all') {
    $sqlWhere .= " AND t.branch_name = ?";
    $sqlParams[] = $branch;
}

$stmtSummary = $pdo->prepare("
    SELECT COALESCE(SUM(t.total_amount),0) AS total_sales, 
           COUNT(DISTINCT t.id) AS total_orders,
           COUNT(DISTINCT t.customer_name) AS unique_customers
    FROM transactions t $sqlWhere
");
$stmtSummary->execute($sqlParams);
$summary = $stmtSummary->fetch();

// Payment Method Breakdown
$stmtPaymentSales = $pdo->prepare("
    SELECT t.payment_method, COUNT(DISTINCT t.id) AS orders, SUM(t.total_amount) AS revenue
    FROM transactions t $sqlWhere
    GROUP BY t.payment_method ORDER BY revenue DESC
");
$stmtPaymentSales->execute($sqlParams);
$paymentSales = $stmtPaymentSales->fetchAll();

// Brand Breakdown
$stmtBrandSales = $pdo->prepare("
    SELECT ti.brand_name, SUM(ti.ordered_qty) AS cases_sold, SUM(ti.unit_qty) AS units_sold, SUM(ti.subtotal) AS revenue
    FROM transaction_items ti
    JOIN transactions t ON t.id = ti.transaction_id $sqlWhere
    GROUP BY ti.brand_name ORDER BY revenue DESC LIMIT 10
");
$stmtBrandSales->execute($sqlParams);
$brandSales = $stmtBrandSales->fetchAll();

// Price Tier Breakdown
$stmtTierSales = $pdo->prepare("
    SELECT ti.price_type, COUNT(ti.id) AS item_lines, SUM(ti.unit_qty) AS units, SUM(ti.subtotal) AS revenue
    FROM transaction_items ti
    JOIN transactions t ON t.id = ti.transaction_id $sqlWhere
    GROUP BY ti.price_type ORDER BY revenue DESC
");
$stmtTierSales->execute($sqlParams);
$tierSales = $stmtTierSales->fetchAll();

// Top Customers
$stmtTopCust = $pdo->prepare("
    SELECT t.customer_name, COUNT(DISTINCT t.id) AS orders, SUM(t.total_amount) AS spend
    FROM transactions t $sqlWhere
    GROUP BY t.customer_name ORDER BY spend DESC LIMIT 10
");
$stmtTopCust->execute($sqlParams);
$topCust = $stmtTopCust->fetchAll();

include 'partials/header.php';
?>

<div class="page-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:20px;">
    <div>
        <h2 style="margin:0 0 4px 0;">Distribution Analytics &amp; Reports</h2>
        <p class="muted" style="margin:0;">Performance, brand revenues, pricing tiers, client spending, and one-click export in the client's exact CSV format.</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn primary" href="reports.php?export_client_xlsx=1&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&branch=<?= urlencode($branch) ?>">
            Download Client Excel (.xlsx)
        </a>
        <a class="btn secondary" href="reports.php?export_client_sales=1&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&branch=<?= urlencode($branch) ?>">
            Download Sales CSV (19-Col)
        </a>
        <a class="btn" href="reports.php?download_template=1">
            Download Blank Template (.xlsx)
        </a>
    </div>
</div>

<?php if ($report_message): ?>
    <div class="alert success" style="margin-bottom:16px;"><?= e($report_message) ?></div>
<?php endif; ?>

<!-- Filter Bar -->
<section class="card" style="margin-bottom:20px; padding:16px;">
    <form method="get" action="reports.php" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <label style="width:160px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">From Date</span>
            <input type="date" name="from" value="<?= e($from) ?>" style="width:100%;">
        </label>
        <label style="width:160px; margin:0;">
            <span style="font-size:12px; font-weight:600; text-transform:uppercase; color:var(--muted);">To Date</span>
            <input type="date" name="to" value="<?= e($to) ?>" style="width:100%;">
        </label>
        <div style="display:flex; gap:8px;">
            <button class="btn primary" type="submit">Refresh Analysis</button>
            <button class="btn secondary" type="submit" name="generate" value="1">Generate for Management (.xlsx)</button>
        </div>
    </form>
</section>

<!-- KPI Stats -->
<div class="stats" style="margin-bottom:24px;">
    <div class="stat">
        <span>Period Revenue</span>
        <strong style="color:var(--primary);">₱<?= number_format((float)$summary['total_sales'], 2) ?></strong>
    </div>
    <div class="stat">
        <span>Completed Orders</span>
        <strong><?= number_format((int)$summary['total_orders']) ?></strong>
    </div>
    <div class="stat">
        <span>Active Clients</span>
        <strong><?= number_format((int)$summary['unique_customers']) ?></strong>
    </div>
    <div class="stat">
        <span>Avg Order Value</span>
        <strong>₱<?= number_format($summary['total_orders'] > 0 ? (float)$summary['total_sales'] / (int)$summary['total_orders'] : 0, 2) ?></strong>
    </div>
</div>

<div class="grid-two" style="margin-bottom:24px;">
    <!-- Revenue by Payment Method -->
    <section class="card">
        <h3 style="margin-top:0;">Payment Methods</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Payment Method</th><th style="text-align:right;">Orders</th><th style="text-align:right;">Revenue</th></tr></thead>
                <tbody>
                    <?php if ($paymentSales): ?>
                        <?php foreach ($paymentSales as $ps): ?>
                            <tr>
                                <td><span class="badge" style="background:#e0f2f1; color:#00695c; font-weight:600;"><?= e($ps['payment_method']) ?></span></td>
                                <td style="text-align:right;"><?= number_format((int)$ps['orders']) ?></td>
                                <td style="text-align:right; font-weight:700; color:var(--primary);">₱<?= number_format((float)$ps['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="muted">No sales in this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Revenue by Pricing Tier -->
    <section class="card">
        <h3 style="margin-top:0;">Sales by Pricing Tier</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tier</th><th style="text-align:right;">Units Sold</th><th style="text-align:right;">Revenue</th></tr></thead>
                <tbody>
                    <?php if ($tierSales): ?>
                        <?php foreach ($tierSales as $ts): ?>
                            <tr>
                                <td>
                                    <span class="badge" style="
                                        background: <?= $ts['price_type'] === 'DEALER' ? '#e1f5fe; color:#0277bd;' : ($ts['price_type'] === 'BULK' ? '#f3e5f5; color:#7b1fa2;' : ($ts['price_type'] === 'WHOLESALE' ? '#e8f5e9; color:#2e7d32;' : '#fff3e0; color:#ef6c00;')) ?>;
                                        font-weight:700;">
                                        <?= e($ts['price_type']) ?>
                                    </span>
                                </td>
                                <td style="text-align:right;"><?= number_format((float)$ts['units']) ?></td>
                                <td style="text-align:right; font-weight:700; color:var(--primary);">₱<?= number_format((float)$ts['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="muted">No sales recorded.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="grid-two">
    <!-- Top Brands -->
    <section class="card">
        <h3 style="margin-top:0;">Top Brands by Volume &amp; Revenue</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Brand</th><th style="text-align:right;">Cases</th><th style="text-align:right;">Units</th><th style="text-align:right;">Revenue</th></tr></thead>
                <tbody>
                    <?php if ($brandSales): ?>
                        <?php foreach ($brandSales as $br): ?>
                            <tr>
                                <td><strong><?= e($br['brand_name']) ?></strong></td>
                                <td style="text-align:right;"><?= number_format((float)$br['cases_sold'], 1) ?></td>
                                <td style="text-align:right;"><?= number_format((float)$br['units_sold']) ?></td>
                                <td style="text-align:right; font-weight:700; color:var(--primary);">₱<?= number_format((float)$br['revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="muted">No brand data available.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Top Clients -->
    <section class="card">
        <h3 style="margin-top:0;">Top Client Accounts</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Client Account</th><th style="text-align:right;">Orders</th><th style="text-align:right;">Total Spend</th></tr></thead>
                <tbody>
                    <?php if ($topCust): ?>
                        <?php foreach ($topCust as $c): ?>
                            <tr>
                                <td><strong><?= e($c['customer_name']) ?></strong></td>
                                <td style="text-align:right;"><?= number_format((int)$c['orders']) ?></td>
                                <td style="text-align:right; font-weight:700; color:var(--primary);">₱<?= number_format((float)$c['spend'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" class="muted">No customer orders recorded.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<?php include 'partials/footer.php'; ?>
