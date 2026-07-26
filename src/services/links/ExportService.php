<?php

namespace anvildev\beacon\services\links;

use craft\base\Component;

class ExportService extends Component
{
    /**
     * @param array<int|string, mixed> $headers
     * @param array<int, array<int|string, mixed>> $rows
     */
    public function toCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, array_map($this->escapeCsvField(...), $headers), escape: '');
        foreach ($rows as $row) {
            fputcsv($handle, array_map($this->escapeCsvField(...), $row), escape: '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);
        if ($csv === false) {
            return '';
        }
        return $csv;
    }

    private function escapeCsvField(string|int|float|bool|\Stringable|null $value): string
    {
        $str = (string) $value;
        if ($str === '') {
            return $str;
        }
        if (in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }
        return $str;
    }
}
