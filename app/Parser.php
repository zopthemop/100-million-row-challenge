<?php

namespace App;

final class Parser {
	public function parse(string $inputPath, string $outputPath): void {
		$workers = 4;
		// We can reasonably guess the file size without doing IO
		$size = 7_595_602_700;

		$bufSize = 64 * 1024 * 1024; // 64 MiB
		$part = intdiv($size, $workers);

		// Precompute ranges temp files
		$ranges = [];
		$tmpDir = sys_get_temp_dir();
		$tmpFiles = [];
		for ($i = 0; $i < $workers; $i++) {
			$start = $i * $part;
			$endHint = ($i === $workers - 1) ? $size : ($i + 1) * $part;
			$ranges[$i] = [$start, $endHint];
			$tmpFiles[$i] = $tmpDir . 'partial_' . $i;
		}

		// Fork children
		$pids = [];
		for ($i = 0; $i < $workers; $i++) {
			$pid = pcntl_fork();
			if ($pid === 0) {
				// Child
				[$start, $endHint] = $ranges[$i];
				$stats = $this->worker_process($inputPath, $start, $endHint, $bufSize);
				file_put_contents($tmpFiles[$i], json_encode($stats, JSON_UNESCAPED_SLASHES));
				exit(0);
			}

			// Parent
			$pids[$pid] = $i;
		}

		// Wait for all children
		$remaining = count($pids);
		while ($remaining > 0) {
			$status = 0;
			$pid = pcntl_wait($status);
			if ($pid > 0) {
				$remaining--;
				if (!pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
					$idx = $pids[$pid] ?? null;
					throw new RuntimeException("worker " . ($idx ?? '?') . " exited abnormally (pid=$pid, status=$status)");
				}
			}
		}

		// Merge partials
		$counts = [];
		for ($i = 0; $i < $workers; $i++) {
			$content = @file_get_contents($tmpFiles[$i]);
			if ($content === false) {
				// Clean up any temp files we created before throwing
				for ($j = 0; $j <= $i; $j++) { @unlink($tmpFiles[$j]); }
				throw new RuntimeException("failed to read partial file: " . $tmpFiles[$i]);
			}
			$partial = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
			foreach ($partial as $k => $v) {
				$counts[$k] = ($counts[$k] ?? 0) + $v;
			}
			@unlink($tmpFiles[$i]);
		}

		foreach ($counts as $k => $c) {
			[$url, $date] = explode(",", $k, 2);
			$stats[$url][$date] = ($stats[$url][$date] ?? 0) + $c;
		}


		foreach ($stats as &$urlData) {
			ksort($urlData);
		}

		file_put_contents($outputPath, json_encode($stats, JSON_PRETTY_PRINT));
	}

	function worker_process(string $path, int $start, int $endHint, int $bufSize): array {
		$fh = fopen($path, 'rb');
		if (!$fh) throw new RuntimeException("open failed: $path");

		if ($start > 0) {
			fseek($fh, $start);
			stream_get_line($fh, 0, "\n"); // align to next full line
		} else {
			fseek($fh, 0);
		}

		$stats = [];
		while (!feof($fh)) {
			$posBefore = ftell($fh);
			if ($posBefore === false || $posBefore >= $endHint) break;

			$toRead = $endHint - $posBefore;
			if ($toRead <= 0) break;
			if ($toRead > $bufSize) $toRead = $bufSize;

			$blk = fread($fh, $toRead);
			if ($blk === '' || $blk === false) break;

			$len = strlen($blk);
			if ($len > 0 && $blk[$len - 1] !== "\n") {
				$rest = stream_get_line($fh, 0, "\n");
				if ($rest !== false) $blk .= $rest . "\n";
			}

			$pos = 0;
			while (($nl = strpos($blk, "\n", $pos)) !== false) {
				$k  = substr($blk, $pos + 19, $nl - $pos - 35);
				$stats[$k] = 1 + ($stats[$k] ?? 0);
				$pos = $nl + 1;
			}
		}
		fclose($fh);

		return $stats;
	}
}
