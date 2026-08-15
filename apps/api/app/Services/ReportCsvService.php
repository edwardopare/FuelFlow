<?php

namespace App\Services;

class ReportCsvService
{
    /**
     * @param  list<string>  $columns
     * @param  iterable<array<string, mixed>>  $rows
     */
    public function make(array $columns, iterable $rows): string
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            throw new \RuntimeException('Unable to create the report export stream.');
        }

        try {
            fputcsv($stream, $columns, escape: '\\');

            foreach ($rows as $row) {
                fputcsv(
                    $stream,
                    array_map(
                        fn (string $column) => $this->safeValue($row[$column] ?? null),
                        $columns,
                    ),
                    escape: '\\',
                );
            }

            rewind($stream);
            $contents = stream_get_contents($stream);

            if ($contents === false) {
                throw new \RuntimeException('Unable to read the report export stream.');
            }

            return $contents;
        } finally {
            fclose($stream);
        }
    }

    private function safeValue(mixed $value): string|int|float
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $string = (string) ($value ?? '');

        return preg_match('/^[=+\-@\t\r]/', $string) === 1
            ? "'{$string}"
            : $string;
    }
}
