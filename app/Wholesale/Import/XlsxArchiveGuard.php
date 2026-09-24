<?php

namespace App\Wholesale\Import;

use XMLReader;
use ZipArchive;

/** Bound the archive before OpenSpout reads dimensions, styles or shared strings. */
final class XlsxArchiveGuard
{
    public const MAX_UNPACKED_BYTES = 48 * 1024 * 1024;

    public const MAX_ENTRY_BYTES = 8 * 1024 * 1024;

    public const MAX_CELLS = 20000;

    public function check(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new PurchaseImportException('file_invalid');
        }
        try {
            if ($zip->numFiles > 2000 || $zip->locateName('xl/workbook.xml') === false) {
                throw new PurchaseImportException('file_archive_limit');
            }
            $total = 0;
            $cells = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', $stat['name']);
                if (isset($names[$name]) || str_starts_with($name, '/') || str_contains($name, ':')
                    || in_array('..', explode('/', $name), true) || str_contains(strtolower($name), 'vbaproject')) {
                    throw new PurchaseImportException('file_unsafe');
                }
                $names[$name] = true;
                $total += $stat['size'];
                if ($stat['size'] > self::MAX_ENTRY_BYTES || $total > self::MAX_UNPACKED_BYTES) {
                    throw new PurchaseImportException('file_archive_limit');
                }
                if (! str_ends_with($name, '.xml') && ! str_ends_with($name, '.rels')) {
                    continue;
                }
                $stream = $zip->getStream($stat['name']);
                if ($stream === false) {
                    throw new PurchaseImportException('file_invalid');
                }
                try {
                    $xml = stream_get_contents($stream, self::MAX_ENTRY_BYTES + 1);
                } finally {
                    fclose($stream);
                }
                if ($xml === false || strlen($xml) > self::MAX_ENTRY_BYTES) {
                    throw new PurchaseImportException('file_archive_limit');
                }
                $this->checkXml($xml, $cells);
            }
        } finally {
            $zip->close();
        }
    }

    private function checkXml(string $xml, int &$cells): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;
        try {
            if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new PurchaseImportException('file_invalid');
            }
            $reader->setParserProperty(XMLReader::LOADDTD, false);
            $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE || $reader->nodeType === XMLReader::ENTITY_REF) {
                    throw new PurchaseImportException('file_unsafe');
                }
                if ($reader->depth > 64) {
                    throw new PurchaseImportException('file_archive_limit');
                }
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'Relationship' && $reader->getAttribute('TargetMode') === 'External') {
                    throw new PurchaseImportException('file_unsafe');
                }
                if ($reader->localName === 'c' && ++$cells > self::MAX_CELLS) {
                    throw new PurchaseImportException('file_archive_limit');
                }
                if (in_array($reader->localName, ['c', 'dimension'], true)) {
                    $reference = $reader->getAttribute($reader->localName === 'c' ? 'r' : 'ref') ?? '';
                    foreach (explode(':', $reference) as $coordinate) {
                        if (preg_match('/^([A-Z]+)([0-9]+)$/', $coordinate, $match)) {
                            $column = 0;
                            foreach (str_split($match[1]) as $letter) {
                                $column = $column * 26 + ord($letter) - 64;
                                if ($column > 64) {
                                    throw new PurchaseImportException('file_archive_limit');
                                }
                            }
                            if ((int) $match[2] > 100000) {
                                throw new PurchaseImportException('file_archive_limit');
                            }
                        }
                    }
                }
                if ($reader->localName === 'row') {
                    $rowIndex = $reader->getAttribute('r');
                    if ($rowIndex !== null && (! ctype_digit($rowIndex) || (int) $rowIndex < 1 || (int) $rowIndex > 100000)) {
                        throw new PurchaseImportException('file_archive_limit');
                    }
                    $spans = explode(':', $reader->getAttribute('spans') ?? '0');
                    if (max(array_map('intval', $spans)) > 64) {
                        throw new PurchaseImportException('file_archive_limit');
                    }
                }
            }
            if (libxml_get_errors()) {
                throw new PurchaseImportException('file_invalid');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
