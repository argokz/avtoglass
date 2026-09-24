<?php

namespace App\Wholesale\Import;

use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

final class PurchaseListExport
{
    public function template(string $format): string
    {
        $rows = [
            ['article', 'quantity', 'manufacturer', 'comment'],
            ['DEMO-CAMRY', '2', 'FYG', 'Пример: замените своими данными / Мысал: өз деректеріңізді енгізіңіз'],
            ['DEMO-2', '1', 'XYG', 'Камеру проверит менеджер / Камераны менеджер тексереді'],
        ];
        if ($format === 'csv') {
            return $this->csv($rows);
        }
        if ($format !== 'xlsx') {
            throw new PurchaseImportException('file_type');
        }
        $path = tempnam(sys_get_temp_dir(), 'purchase-template-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            try {
                $writer->getCurrentSheet()->setName('Заказ');
                $writer->getCurrentSheet()->setColumnWidth(24, 1, 3);
                $writer->getCurrentSheet()->setColumnWidth(14, 2);
                $writer->getCurrentSheet()->setColumnWidth(66, 4);
                $headerStyle = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('B91C1C');
                foreach ($rows as $index => $values) {
                    $row = new Row(array_map(fn ($value) => new StringCell($value, null), $values), $index === 0 ? $headerStyle : (new Style)->setShouldWrapText());
                    $row->setHeight($index === 0 ? 24 : 44);
                    $writer->addRow($row);
                }
                $writer->addNewSheetAndMakeItCurrent()->setName('Инструкция');
                $writer->getCurrentSheet()->setColumnWidth(24, 1);
                $writer->getCurrentSheet()->setColumnWidth(100, 2);
                foreach ([
                    ['Поле / Өріс', 'Правило / Ереже'],
                    ['article', 'Артикул / код, текст. Артикул / код, мәтін. Сохраняйте начальные нули. Бастапқы нөлдерді сақтаңыз.'],
                    ['quantity', 'Количество / саны: целое число 1–99 / 1–99 бүтін сан.'],
                    ['manufacturer', 'Необязательно: производитель / Міндетті емес: өндіруші.'],
                    ['comment', 'Необязательно: комментарий / Міндетті емес: түсініктеме.'],
                    ['Лист / Парақ', 'Читается первый лист «Заказ» / Бірінші «Заказ» парағы оқылады.'],
                    ['Лимиты / Шектеулер', '20 MiB; 1 000 строк / жол; 500 позиций / тауар түрі.'],
                    ['Формулы / Формулалар', 'Используйте буквальные значения / Нақты мәндерді енгізіңіз.'],
                ] as $values) {
                    $row = new Row(array_map(fn ($value) => new StringCell($value, null), $values), (new Style)->setShouldWrapText());
                    $row->setHeight(42);
                    $writer->addRow($row);
                }
            } finally {
                $writer->close();
            }

            return file_get_contents($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        try {
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                fputcsv($stream, array_map($this->safeCell(...), $row), ';', '"', '', "\r\n");
            }
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    private function safeCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s\x00]*[=+@\-]/u', $value) ? "'".$value : $value;
    }
}
