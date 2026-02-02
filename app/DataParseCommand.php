<?php

namespace App;

use DateTimeImmutable;
use Tempest\Console\ConsoleCommand;
use Tempest\Console\HasConsole;

final class DataParseCommand
{
    use HasConsole;

    #[ConsoleCommand]
    public function __invoke(
        string $inputPath = __DIR__ . '/../data/data.csv',
        string $outputPath = __DIR__ . '/../data/data.json',
    ): void {
        $startTime = microtime(true);

        $handle = fopen($inputPath, 'r');

        $data = [];

        while (($line = fgets($handle)) !== false) {
            [$uri, $date] = explode(';', trim($line));

            $date = new DateTimeImmutable($date);
            $path = parse_url($uri, PHP_URL_PATH);

            $data[$path][$date->format('Y-m-d')] ??= 0;

            $data[$path][$date->format('Y-m-d')] += 1;
        }

        foreach ($data as &$visits) {
            ksort($visits);
        }

        fclose($handle);

        file_put_contents($outputPath, json_encode($data, JSON_PRETTY_PRINT));

        $endTime = microtime(true);

        $executionTime = $endTime - $startTime;

        $this->success(sprintf("Done in %ss", $executionTime));
    }
}