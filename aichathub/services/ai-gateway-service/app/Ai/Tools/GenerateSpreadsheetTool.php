<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\ChargesForGeneratedFile;
use App\Services\GeneratedAttachmentTracker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Stringable;

/**
 * Same automatic function-calling shape as GenerateImageTool — the model decides on
 * its own when the user wants a downloadable spreadsheet, based on the schema's
 * description below, and supplies the actual row data as structured arguments
 * rather than free text (PhpSpreadsheet needs real cell values, not prose).
 */
class GenerateSpreadsheetTool implements Tool
{
    use ChargesForGeneratedFile;

    private const PRICING_MODEL_ID = 'document-xlsx';

    // Same brand violet as the other generation tools — a generated sheet was
    // previously plain default-styled cells with no distinction between the
    // header row and data, no column sizing, nothing to tell it apart from
    // pasting raw values into a blank workbook.
    private const ACCENT = '6C37E6';

    public function __construct(
        private string $userId,
        private GeneratedAttachmentTracker $tracker,
    ) {}

    public function description(): Stringable|string
    {
        return 'Generates a downloadable Excel (.xlsx) spreadsheet file. Call this whenever '
            .'the user asks for an Excel file, spreadsheet, or a table of data to download — '
            .'do not just print the table in the chat instead.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'sheet_name' => $schema->string()
                ->description('Name of the worksheet, e.g. "Budget".')
                ->required(),
            'rows' => $schema->array()
                ->items($schema->array()->items($schema->string()))
                ->description(
                    'The spreadsheet contents as an array of rows, each row an array of cell '
                    .'values (as strings — numbers and formulas are fine written as strings, '
                    .'e.g. "42" or "=SUM(A1:A3)"). The first row is normally the header.'
                )
                ->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $sheetName = (string) $request['sheet_name'];
        $rows = $request['rows'];

        return $this->chargeAndStore(
            userId: $this->userId,
            pricingModelId: self::PRICING_MODEL_ID,
            filename: (Str::slug($sheetName) ?: Str::random(10)).'.xlsx',
            mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            generate: function () use ($sheetName, $rows) {
                $spreadsheet = new Spreadsheet();
                $sheet = $spreadsheet->getActiveSheet();
                $sheet->setTitle(Str::limit($sheetName, 31, ''));

                $columnCount = 0;
                foreach ($rows as $rowIndex => $row) {
                    $columnCount = max($columnCount, count($row));
                    foreach ($row as $colIndex => $value) {
                        $coordinate = Coordinate::stringFromColumnIndex($colIndex + 1).($rowIndex + 1);
                        $sheet->setCellValue($coordinate, $value);
                    }
                }

                // Header row (row 1) styled distinctly from the data beneath it —
                // bold white text on the brand fill — and every used column
                // auto-sized so values aren't left truncated behind the default
                // column width.
                if ($columnCount > 0) {
                    $headerRange = 'A1:'.Coordinate::stringFromColumnIndex($columnCount).'1';
                    $sheet->getStyle($headerRange)->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                    $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::ACCENT);
                    for ($col = 1; $col <= $columnCount; $col++) {
                        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
                    }
                }

                $tmpPath = tempnam(sys_get_temp_dir(), 'xlsx_');
                (new Xlsx($spreadsheet))->save($tmpPath);
                $bytes = file_get_contents($tmpPath);
                unlink($tmpPath);

                return $bytes;
            },
            tracker: $this->tracker,
            unavailableMessage: 'Spreadsheet generation is temporarily unavailable. Please try again later.',
            generationFailedMessage: 'Spreadsheet generation failed. Please try rephrasing your request.',
            successMessage: 'Spreadsheet generated successfully and shown to the user.',
        );
    }
}
