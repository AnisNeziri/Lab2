<?php

namespace App\Support;

use DateTimeInterface;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Small dependency-free XLSX writer.
 *
 * It emits a genuine ZIP/OpenXML workbook without requiring ext-zip, which is
 * important for the bundled AIMS desktop PHP runtime. Strings are always
 * written as inline strings; formulas are accepted only through an explicit
 * `formula` cell, preventing spreadsheet-formula injection from user data.
 */
class OpenXmlWorkbook
{
    /** @var array<int, array{name: string, rows: array}> */
    private array $sheets = [];

    public function addSheet(string $name, array $rows): self
    {
        $name = $this->uniqueSheetName($name);
        $this->sheets[] = ['name' => $name, 'rows' => array_values($rows)];

        return $this;
    }

    public function bytes(): string
    {
        if ($this->sheets === []) {
            $this->addSheet('Data', []);
        }

        $entries = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => $this->rootRelationships(),
            'docProps/app.xml' => $this->appProperties(),
            'docProps/core.xml' => $this->coreProperties(),
            'xl/workbook.xml' => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationships(),
            'xl/styles.xml' => $this->stylesXml(),
        ];

        foreach ($this->sheets as $index => $sheet) {
            $entries['xl/worksheets/sheet'.($index + 1).'.xml'] = $this->worksheetXml($sheet['rows']);
        }

        return $this->storedZip($entries);
    }

    public static function cell(mixed $value, string $style = ''): array
    {
        return ['value' => $value, 'style' => $style];
    }

    public static function formula(string $formula, mixed $cachedValue = 0, string $style = ''): array
    {
        return ['formula' => ltrim($formula, '='), 'value' => $cachedValue, 'style' => $style];
    }

    private function uniqueSheetName(string $candidate): string
    {
        $base = trim(preg_replace('~[\\\\/?*\[\]:]+~u', ' ', $candidate) ?? '') ?: 'Sheet';
        $base = mb_substr($base, 0, 31);
        $existing = array_column($this->sheets, 'name');
        $name = $base;
        $suffix = 2;

        while (in_array(mb_strtolower($name), array_map('mb_strtolower', $existing), true)) {
            $tail = ' '.$suffix++;
            $name = mb_substr($base, 0, 31 - mb_strlen($tail)).$tail;
        }

        return $name;
    }

    private function worksheetXml(array $rows): string
    {
        $maxColumns = 1;
        $widths = [];
        foreach ($rows as $row) {
            $row = is_array($row) ? array_values($row) : [$row];
            $maxColumns = max($maxColumns, count($row));
            foreach ($row as $column => $raw) {
                $value = is_array($raw) && array_key_exists('value', $raw) ? $raw['value'] : $raw;
                $length = mb_strlen($value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? ''));
                $widths[$column] = min(42, max($widths[$column] ?? 8, $length + 2));
            }
        }

        $cols = '';
        for ($column = 0; $column < $maxColumns; $column++) {
            $number = $column + 1;
            $width = number_format((float) ($widths[$column] ?? 12), 1, '.', '');
            $cols .= '<col min="'.$number.'" max="'.$number.'" width="'.$width.'" customWidth="1"/>';
        }

        $sheetData = '';
        foreach (array_values($rows) as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $sheetData .= '<row r="'.$rowNumber.'">';
            foreach (array_values(is_array($row) ? $row : [$row]) as $columnIndex => $raw) {
                $sheetData .= $this->cellXml($raw, $columnIndex, $rowNumber);
            }
            $sheetData .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/><cols>'.$cols.'</cols><sheetData>'.$sheetData.'</sheetData>'
            .'<autoFilter ref="A1:'.$this->columnName($maxColumns - 1).max(1, count($rows)).'"/>'
            .'</worksheet>';
    }

    private function cellXml(mixed $raw, int $columnIndex, int $rowNumber): string
    {
        $cell = is_array($raw) && (array_key_exists('value', $raw) || array_key_exists('formula', $raw))
            ? $raw
            : ['value' => $raw];
        $value = $cell['value'] ?? null;
        $reference = $this->columnName($columnIndex).$rowNumber;
        $style = $this->styleIndex((string) ($cell['style'] ?? ''));
        $styleAttribute = $style > 0 ? ' s="'.$style.'"' : '';

        if (isset($cell['formula'])) {
            $formula = $this->xml((string) $cell['formula']);
            $cached = is_numeric($value) ? (string) (0 + $value) : '0';

            return '<c r="'.$reference.'"'.$styleAttribute.'><f>'.$formula.'</f><v>'.$cached.'</v></c>';
        }

        if ($value instanceof DateTimeInterface) {
            // Excel date-only cells must use the displayed calendar date, not
            // the UTC instant (which can shift the date across time zones).
            $calendarDate = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $value->format('Y-m-d'),
                new DateTimeZone('UTC'),
            );
            $serial = (($calendarDate?->getTimestamp() ?? 0) / 86400) + 25569;

            return '<c r="'.$reference.'" s="3"><v>'.number_format($serial, 8, '.', '').'</v></c>';
        }

        $numericString = is_string($value)
            && $value !== ''
            && is_numeric($value)
            && (($cell['type'] ?? null) === 'number' || in_array(($cell['style'] ?? ''), ['currency', 'percent', 'total', 'integer'], true));
        if (is_int($value) || is_float($value) || $numericString) {
            return '<c r="'.$reference.'"'.$styleAttribute.'><v>'.(string) (0 + $value).'</v></c>';
        }

        if (is_bool($value)) {
            return '<c r="'.$reference.'" t="b"'.$styleAttribute.'><v>'.($value ? '1' : '0').'</v></c>';
        }

        $text = $this->xml((string) ($value ?? ''));

        return '<c r="'.$reference.'" t="inlineStr"'.$styleAttribute.'><is><t xml:space="preserve">'.$text.'</t></is></c>';
    }

    private function styleIndex(string $style): int
    {
        return match ($style) {
            'header' => 1,
            'currency' => 2,
            'date' => 3,
            'title' => 4,
            'percent' => 5,
            'total' => 6,
            'integer' => 7,
            default => 0,
        };
    }

    private function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3"><numFmt numFmtId="164" formatCode="€ #,##0.00;[Red]-€ #,##0.00"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd"/><numFmt numFmtId="166" formatCode="0.00%"/></numFmts>'
            .'<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FF0B1F3A"/><sz val="16"/><name val="Calibri"/></font></fonts>'
            .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0B5ED7"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FF"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border><left style="thin"><color rgb="FFD7E2F0"/></left><right style="thin"><color rgb="FFD7E2F0"/></right><top style="thin"><color rgb="FFD7E2F0"/></top><bottom style="thin"><color rgb="FFD7E2F0"/></bottom><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function contentTypes(): string
    {
        $worksheets = '';
        foreach ($this->sheets as $index => $_sheet) {
            $worksheets .= '<Override PartName="/xl/worksheets/sheet'.($index + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            .$worksheets.'</Types>';
    }

    private function workbookXml(): string
    {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="'.$this->xml($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 2).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<workbookPr date1904="0"/><bookViews><workbookView activeTab="0"/></bookViews><sheets>'.$sheets.'</sheets><calcPr calcId="191029" fullCalcOnLoad="1"/></workbook>';
    }

    private function workbookRelationships(): string
    {
        $relationships = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($this->sheets as $index => $_sheet) {
            $relationships .= '<Relationship Id="rId'.($index + 2).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($index + 1).'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relationships.'</Relationships>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            .'</Relationships>';
    }

    private function coreProperties(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:creator>AIMS</dc:creator><cp:lastModifiedBy>AIMS</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">'.$now.'</dcterms:modified></cp:coreProperties>';
    }

    private function appProperties(): string
    {
        $titles = implode('', array_map(fn (array $sheet) => '<vt:lpstr>'.$this->xml($sheet['name']).'</vt:lpstr>', $this->sheets));

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>AIMS</Application><TitlesOfParts><vt:vector size="'.count($this->sheets).'" baseType="lpstr">'.$titles.'</vt:vector></TitlesOfParts></Properties>';
    }

    private function storedZip(array $entries): string
    {
        $local = '';
        $central = '';
        $offset = 0;
        [$dosTime, $dosDate] = $this->dosDateTime();

        foreach ($entries as $name => $data) {
            if (! is_string($name) || ! is_string($data)) {
                throw new InvalidArgumentException('ZIP entries must be strings.');
            }
            $name = str_replace('\\', '/', $name);
            $crc = crc32($data);
            $size = strlen($data);
            $nameLength = strlen($name);
            $localHeader = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0).$name;
            $local .= $localHeader.$data;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLength, 0, 0, 0, 0, 0, $offset).$name;
            $offset += strlen($localHeader) + $size;
        }

        $count = count($entries);
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), strlen($local), 0);

        return $local.$central.$end;
    }

    private function dosDateTime(): array
    {
        $parts = getdate();
        $year = max(1980, (int) $parts['year']);
        $time = ((int) $parts['hours'] << 11) | ((int) $parts['minutes'] << 5) | ((int) floor($parts['seconds'] / 2));
        $date = (($year - 1980) << 9) | ((int) $parts['mon'] << 5) | (int) $parts['mday'];

        return [$time, $date];
    }

    private function columnName(int $index): string
    {
        $name = '';
        do {
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $name;
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
