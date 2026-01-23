<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Exporter\Excel;

use Omines\DataTablesBundle\Exporter\AbstractDataTableExporter;
use PhpOffice\PhpSpreadsheet\Cell\CellAddress;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Helper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Exports DataTable data to Excel.
 *
 * @author Maxime Pinot <contact@maximepinot.com>
 */
class ExcelExporter extends AbstractDataTableExporter
{
    #[\Override]
    public function export(array $columnNames, \Iterator $data, array $columnOptions): \SplFileInfo
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getSheet(0);

        foreach ($columnNames as $key => $name) {
            $columnNames[$key] = strip_tags($name);
        }

        $sheet->fromArray($columnNames, null, 'A1');
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);

        $rowIndex = 2;
        $htmlHelper = new Helper\Html();
        foreach ($data as $row) {
            $colIndex = 1;
            foreach ($row as $value) {
                if ($value instanceof DateTime) {
                    $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value->format('d/m/Y'));
                    $sheet->getStyleByColumnAndRow($colIndex, $rowIndex)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DATETIME);
                }
                else if (is_array($value)) {
                    $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $htmlHelper->toRichTextObject(implode(', ', $value)));
                }
                else if ($value != null && is_numeric($value)) {
                    $value = floatval($value);
                    $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
                    if (floor($value) != $value) {
                        $sheet->getStyleByColumnAndRow($colIndex, $rowIndex)->getNumberFormat()->setFormatCode(StatisticBundle::EXCEL_FORMAT_NUMBER_00);
                    } else {
                        $sheet->getStyleByColumnAndRow($colIndex, $rowIndex)->getNumberFormat()->setFormatCode(StatisticBundle::EXCEL_FORMAT_NUMBER);
                    }
                } elseif ($value != null && is_string($value) && str_ends_with($value, '€')) {
                    $value = str_replace([' ', '€'], '', $value);
                    $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $value);
                    $sheet->getStyleByColumnAndRow($colIndex, $rowIndex)->getNumberFormat()->setFormatCode(StatisticBundle::EXCEL_FORMAT_CURRENCY_EUR);
                } else {
                    $sheet->setCellValueByColumnAndRow($colIndex, $rowIndex, $htmlHelper->toRichTextObject($value));
                }
                $colIndex++;
            }
            ++$rowIndex;
        }

        $this->autoSizeColumnWidth($sheet);

        $filePath = sys_get_temp_dir() . '/' . uniqid('dt') . '.xlsx';

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);

        return new \SplFileInfo($filePath);
    }

    /**
     * Sets the columns width to automatically fit the contents.
     */
    private function autoSizeColumnWidth(Worksheet $sheet): void
    {
        foreach (range(1, Coordinate::columnIndexFromString($sheet->getHighestColumn(1))) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
    }

    public function getMimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function getName(): string
    {
        return 'excel';
    }
}
